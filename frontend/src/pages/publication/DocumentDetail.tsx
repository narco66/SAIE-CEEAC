import { useState } from 'react'
import { useNavigate, useParams } from 'react-router'
import { useQuery, useQueryClient } from '@tanstack/react-query'
import { QRCodeSVG } from 'qrcode.react'
import { api, ApiError, fileUrl } from '../../lib/api'
import { useAuth } from '../../lib/auth'
import { CLASS_LABEL, CLASS_TONE, fmtBytes, fmtDate, fmtDateTime, LANG_LABEL, SCAN_STATUS, todayLocal } from '../../lib/format'
import { Button, ConfirmDialog, ErrorState, Field, Icon, Kv, Loading, Modal, Notice, PageHeading, Status, StatusOf, useToast } from '../../components/ui'
import { DOC_STATUS, errorText, HISTORY_LABEL, useExportRequest, useReferentials, type AssetVersion, type UserRef } from './shared'
import { DOC_EXTENSIONS, type DocumentRow } from './Documents'
import { checkFile } from './Media'

interface DocumentDetailData extends DocumentRow {
  versions: { id: string; version: number; notes: string | null; created_at: string | null; creator: UserRef | null; file: AssetVersion | null }[]
  audiences: { id: string; name: string }[]
  can_manage: boolean
  qr: { code: string; classification: string } | null
  history: { action: string; actor: string | null; reason: string | null; at: string | null }[]
}
interface ReadsResponse { data: { user: string; structure: string | null; first_opened_at: string | null }[]; meta: { tracking: boolean; reason?: string; expected?: number | null; read?: number } }

/** V14 Fiche document : version courante, historique, QR, actions du propriétaire et accusés de lecture P1 (DOC-05 à DOC-08). */
export default function DocumentDetail() {
  const { id } = useParams()
  const navigate = useNavigate()
  const client = useQueryClient()
  const toast = useToast()
  const { me } = useAuth()
  const exporter = useExportRequest()
  const [inline, setInline] = useState(false)
  const [modal, setModal] = useState<'version' | 'edit' | 'unpublish' | null>(null)
  const [publishing, setPublishing] = useState(false)
  const [actionError, setActionError] = useState<string | null>(null)
  const query = useQuery({
    queryKey: ['document', id],
    queryFn: ({ signal }) => api.get<{ data: DocumentDetailData }>(`/documents/${id}`, undefined, signal).then((r) => r.data),
    refetchInterval: (q) => (['quarantine', 'scanning'].includes(q.state.data?.current_version?.file?.scan_status ?? '') ? 3000 : false),
  })
  const d = query.data
  const reads = useQuery({
    queryKey: ['document-reads', id],
    queryFn: ({ signal }) => api.get<ReadsResponse>(`/documents/${id}/reads`, undefined, signal),
    enabled: !!d?.can_manage,
  })

  if (query.isLoading) return <Loading />
  if (query.isError || !d) return <ErrorState error={query.error} onRetry={() => query.refetch()} />

  const cv = d.current_version
  const file = cv?.file
  const ready = file?.scan_status === 'ready'
  const isPdf = file?.extension === 'pdf'
  const expired = !!d.expires_at && d.expires_at < todayLocal()
  const update = (data: DocumentDetailData) => { client.setQueryData(['document', id], data); client.invalidateQueries({ queryKey: ['documents'] }) }

  const publish = async () => {
    setPublishing(true)
    setActionError(null)
    try {
      update((await api.post<{ data: DocumentDetailData }>(`/documents/${d.id}/publish`)).data)
      toast.success('Document publié', d.classification === 'restreint' ? 'Aucun QR n’est créé pour un document restreint.' : 'Le lien QR est actif.')
    } catch (e) {
      setActionError(errorText(e))
    } finally {
      setPublishing(false)
    }
  }

  return (
    <>
      <PageHeading
        title={d.title}
        description={`${d.reference} · ${d.owner?.name ?? 'propriétaire non renseigné'}`}
        demo={me.demo_mode}
        actions={<>
          <Button variant="secondary" icon="folder" onClick={() => navigate('/documents')}>Bibliothèque</Button>
          <Button variant="secondary" icon="download" busy={exporter.busy !== null} onClick={() => exporter.run('document_sheet', 'pdf', { document_id: d.id })}>Fiche PDF10</Button>
          {d.can_manage && <Button variant="secondary" icon="edit" onClick={() => setModal('edit')}>Modifier</Button>}
          {d.can_manage && <Button variant="secondary" icon="upload" onClick={() => setModal('version')}>Nouvelle version</Button>}
          {d.can_manage && d.status !== 'published' && <Button icon="check" onClick={publish} busy={publishing} disabled={!ready || expired} title={!ready ? 'Le fichier doit être déclaré sain' : expired ? 'Document expiré' : undefined}>Publier</Button>}
          {d.can_manage && d.status === 'published' && <Button variant="danger" icon="x" onClick={() => setModal('unpublish')}>Dépublier</Button>}
        </>}
      />
      {actionError && <div style={{ marginBottom: 12 }}><Notice tone="danger">{actionError}</Notice></div>}
      {d.status === 'unpublished' && (
        <div style={{ marginBottom: 12 }}>
          <Notice tone="danger"><strong>Document retiré de la diffusion.</strong> Il a été dépublié{d.history.find((h) => h.action === 'unpublished') ? ` le ${fmtDateTime(d.history.find((h) => h.action === 'unpublished')!.at)} — motif : « ${d.history.find((h) => h.action === 'unpublished')!.reason ?? '—'} »` : ''}. Les liens QR sont désactivés et les écrans ne le référencent plus.</Notice>
        </div>
      )}
      {expired && <div style={{ marginBottom: 12 }}><Notice tone="warning">Ce document est expiré depuis le {fmtDate(d.expires_at)}.</Notice></div>}
      {d.status === 'draft' && <div style={{ marginBottom: 12 }}><Notice tone="info">Brouillon : le document n’est pas encore publié{!ready ? ' ; le fichier doit d’abord être déclaré sain' : ''}.</Notice></div>}
      <div className="pub-editor-grid">
        <div style={{ display: 'grid', gap: 15 }}>
          <section className="panel">
            <div className="panel-title"><div><h2>Résumé</h2><p>Mis à jour le {fmtDateTime(d.updated_at)}</p></div><StatusOf map={DOC_STATUS} value={d.status} /></div>
            <div className="panel-body">
              <p style={{ whiteSpace: 'pre-wrap', marginBottom: 14 }}>{d.summary || <span className="muted">Aucun résumé.</span>}</p>
              <Kv items={[
                ['Référence', d.reference],
                ['Propriétaire', d.owner?.name],
                ['Auteur', d.author?.name],
                ['Catégorie', d.category?.name],
                ['Classification', <Status tone={CLASS_TONE[d.classification]}>{CLASS_LABEL[d.classification]}</Status>],
                ['Langue', d.language ? LANG_LABEL[d.language] ?? d.language : '—'],
                ['Mots-clés', d.keywords.length ? <span className="chip-row">{d.keywords.map((k) => <span className="chip" key={k}>{k}</span>)}</span> : '—'],
                ['Validité', `${d.valid_from ? `du ${fmtDate(d.valid_from)} ` : ''}${d.expires_at ? `au ${fmtDate(d.expires_at)}` : 'sans date d’expiration'}`],
                ['Publié le', d.published_at ? fmtDateTime(d.published_at) : 'non publié'],
                ['Diffusion sur écran', <span className={d.diffusion_allowed ? 'green-text' : 'red-text'}>{d.diffusion_allowed ? 'Autorisée' : 'Interdite (restreint)'}</span>],
                ['Publics', d.audiences.length ? d.audiences.map((a) => a.name).join(', ') : 'Tous les agents habilités'],
              ]} />
            </div>
          </section>
          <section className="panel">
            <div className="panel-title">
              <div><h2>Version courante · v{cv?.version ?? '—'}</h2><p>{file ? `${file.original_name} · ${fmtBytes(file.size)}` : 'Aucun fichier'}</p></div>
              {file && <StatusOf map={SCAN_STATUS} value={file.scan_status} />}
            </div>
            <div className="panel-body">
              {!file ? <Notice tone="warning">Aucune version disponible.</Notice> : !ready ? (
                <Notice tone={file.scan_status === 'rejected' ? 'danger' : 'warning'}>Fichier {SCAN_STATUS[file.scan_status]?.[0].toLowerCase() ?? file.scan_status} : téléchargement et aperçu indisponibles tant que l’analyse n’est pas saine.{file.scan_detail ? ` ${file.scan_detail}` : ''}</Notice>
              ) : (
                <>
                  <div className="row-actions" style={{ justifyContent: 'flex-start' }}>
                    <a className="button" href={fileUrl(`/documents/${d.id}/versions/${cv!.version}/download`)}><Icon name="download" size={16} /><span>Télécharger</span></a>
                    {isPdf && <Button variant="secondary" icon="eye" onClick={() => setInline((x) => !x)}>{inline ? 'Masquer l’aperçu' : 'Aperçu'}</Button>}
                  </div>
                  <p className="muted small" style={{ marginTop: 8 }}>Empreinte SHA-256 : <span className="mono">{file.sha256}</span>{d.classification !== 'public' ? ' · chaque téléchargement est journalisé.' : ''}</p>
                  {inline && isPdf && <iframe className="pub-preview-pdf" style={{ marginTop: 10 }} src={fileUrl(`/documents/${d.id}/versions/${cv!.version}/download`, { inline: 1 })} title={`Aperçu de ${d.title}`} />}
                </>
              )}
            </div>
          </section>
          <section className="panel table-panel">
            <div className="panel-title"><div><h2>Historique des versions</h2><p>Les versions remplacées sont réservées au propriétaire.</p></div></div>
            <div className="table-scroll">
              <table>
                <thead><tr><th>Version</th><th>Fichier</th><th>Notes</th><th>Déposée</th><th>Analyse</th><th><span className="sr-only">Téléchargement</span></th></tr></thead>
                <tbody>
                  {d.versions.map((v) => {
                    const current = v.id === cv?.id
                    return (
                      <tr key={v.id}>
                        <td><strong>v{v.version}</strong><small>{current ? 'Courante' : 'Remplacée (archivée)'}</small></td>
                        <td>{v.file?.original_name ?? '—'}<small>{fmtBytes(v.file?.size)}</small></td>
                        <td>{v.notes ?? '—'}</td>
                        <td>{fmtDateTime(v.created_at)}<small>{v.creator?.name}</small></td>
                        <td><StatusOf map={SCAN_STATUS} value={v.file?.scan_status} /></td>
                        <td>
                          {v.file?.scan_status === 'ready' && (current || d.can_manage)
                            ? <a className="link-text" href={fileUrl(`/documents/${d.id}/versions/${v.version}/download`)}>Télécharger</a>
                            : !current ? <span className="muted small">Réservée au propriétaire</span> : null}
                        </td>
                      </tr>
                    )
                  })}
                </tbody>
              </table>
            </div>
          </section>
        </div>
        <aside className="pub-aside">
          <section className="panel">
            <div className="panel-title"><div><h2>QR code</h2><p>Accès depuis un écran ou un support imprimé</p></div></div>
            <div className="panel-body">
              {d.qr && d.status === 'published' ? (
                <div className="pub-qr">
                  <div><QRCodeSVG value={`${window.location.origin}/q/${d.qr.code}`} size={120} title={`QR code vers ${d.title}`} /></div>
                  <div>
                    <p className="small mono" style={{ overflowWrap: 'anywhere' }}>{`${window.location.origin}/q/${d.qr.code}`}</p>
                    <p className="muted small">{d.qr.classification === 'public' ? 'Accès public.' : 'Document interne : le lien ouvre l’authentification avant la consultation.'}</p>
                  </div>
                </div>
              ) : d.qr ? <p className="muted small">Lien QR désactivé : le document n’est pas publié.</p> : <p className="muted small">{d.classification === 'restreint' ? 'Aucun QR pour un document restreint.' : 'Le QR est créé à la publication.'}</p>}
            </div>
          </section>
          {d.can_manage && (
            <section className="panel">
              <div className="panel-title"><div><h2>Accusés de lecture</h2><p>Version courante · visible du seul propriétaire</p></div></div>
              <div className="panel-body">
                {reads.isLoading ? <Loading /> : reads.isError ? <ErrorState error={reads.error} onRetry={() => reads.refetch()} /> : !reads.data?.meta.tracking ? (
                  <p className="muted small">{reads.data?.meta.reason ?? 'Aucun suivi nominatif.'}</p>
                ) : (
                  <>
                    <p className="small" style={{ marginBottom: 8 }}><strong>{reads.data.meta.read ?? reads.data.data.length}</strong> lecture(s){reads.data.meta.expected != null ? ` sur ${reads.data.meta.expected} agent(s) attendu(s)` : ' (aucun public défini : suivi non nominatif attendu)'}</p>
                    <div className="timeline-list">
                      {reads.data.data.length === 0 ? <p className="muted small">Aucune ouverture enregistrée.</p> : reads.data.data.map((r, i) => <div key={i}><time>{fmtDateTime(r.first_opened_at)}</time><span>{r.user}<small className="muted" style={{ display: 'block' }}>{r.structure ?? ''}</small></span></div>)}
                    </div>
                  </>
                )}
              </div>
            </section>
          )}
          <section className="panel">
            <div className="panel-title"><div><h2>Historique</h2><p>Décisions et modifications</p></div></div>
            <div className="panel-body timeline-list">
              {d.history.length === 0 ? <p className="muted small">Aucun événement.</p> : d.history.map((h, i) => (
                <div key={i}><time>{fmtDateTime(h.at)}</time><span><strong className="small">{HISTORY_LABEL[h.action] ?? h.action}</strong><small className="muted" style={{ display: 'block' }}>{h.actor ?? 'Système'}</small>{h.reason && <small style={{ display: 'block' }}>« {h.reason} »</small>}</span></div>
              ))}
            </div>
          </section>
        </aside>
      </div>
      {modal === 'version' && <NewVersion doc={d} onClose={() => setModal(null)} onDone={(x) => { update(x); setModal(null); toast.success('Nouvelle version déposée', 'Elle est en cours d’analyse.') }} />}
      {modal === 'edit' && <EditMetadata doc={d} onClose={() => setModal(null)} onDone={(x) => { update(x); setModal(null); toast.success('Métadonnées enregistrées') }} />}
      {modal === 'unpublish' && (
        <ConfirmDialog
          title="Dépublier le document"
          object={`${d.reference} · ${d.title}`}
          consequence="La diffusion s’arrête : les liens QR sont désactivés et les manifestes des écrans ne référencent plus ce document. La fiche et l’historique restent consultables."
          confirmLabel="Dépublier"
          reasonLabel="Motif de la dépublication"
          reasonRequired
          danger
          onClose={() => setModal(null)}
          onConfirm={async (reason) => {
            update((await api.post<{ data: DocumentDetailData }>(`/documents/${d.id}/unpublish`, { reason })).data)
            toast.success('Document dépublié', d.title)
          }}
        />
      )}
    </>
  )
}

function NewVersion({ doc, onClose, onDone }: { doc: DocumentDetailData; onClose: () => void; onDone: (d: DocumentDetailData) => void }) {
  const [file, setFile] = useState<File | null>(null)
  const [notes, setNotes] = useState('')
  const [fileError, setFileError] = useState<string | null>(null)
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<ApiError | null>(null)
  const submit = async () => {
    if (!file) return
    setBusy(true)
    setError(null)
    try {
      const fd = new FormData()
      fd.append('file', file)
      if (notes.trim()) fd.append('notes', notes.trim())
      onDone((await api.upload<{ data: DocumentDetailData }>(`/documents/${doc.id}/versions`, fd)).data)
    } catch (e) {
      setError(e instanceof ApiError ? e : new ApiError(500, { message: errorText(e) }))
    } finally {
      setBusy(false)
    }
  }
  return (
    <Modal title="Nouvelle version" kicker={doc.reference} onClose={onClose} footer={<><Button variant="secondary" onClick={onClose}>Annuler</Button><Button icon="upload" onClick={submit} busy={busy} disabled={!file}>Déposer la version</Button></>}>
      <p className="muted small" style={{ marginBottom: 12 }}>La version courante (v{doc.current_version?.version ?? '—'}) sera remplacée et archivée ; elle restera accessible au propriétaire.</p>
      <Field label="Fichier" required error={fileError ?? error?.field('file')} help="PDF, DOCX, PPTX, XLSX ou image · 50 Mo maximum.">
        <input type="file" accept={DOC_EXTENSIONS.map((e) => `.${e}`).join(',')} onChange={(e) => { const f = e.target.files?.[0] ?? null; const err = f ? checkFile(f, DOC_EXTENSIONS) : null; setFileError(err); setFile(err ? null : f) }} />
      </Field>
      <Field label="Notes de version" error={error?.field('notes')}><textarea value={notes} maxLength={500} onChange={(e) => setNotes(e.target.value)} /></Field>
      {error && !Object.keys(error.errors).length && <Notice tone="danger">{error.message}</Notice>}
    </Modal>
  )
}

function EditMetadata({ doc, onClose, onDone }: { doc: DocumentDetailData; onClose: () => void; onDone: (d: DocumentDetailData) => void }) {
  const refs = useReferentials()
  const [f, setF] = useState({
    title: doc.title, summary: doc.summary ?? '', classification: doc.classification, category_id: doc.category?.id ?? '', owner_structure_id: doc.owner?.id ?? '',
    language: doc.language ?? '', keywords: doc.keywords.join(', '), valid_from: doc.valid_from ?? '', expires_at: doc.expires_at ?? '',
  })
  const [audiences, setAudiences] = useState<string[]>(doc.audiences.map((a) => a.id))
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<ApiError | null>(null)
  const set = (k: keyof typeof f, v: string) => setF((x) => ({ ...x, [k]: v }))
  const submit = async () => {
    setBusy(true)
    setError(null)
    try {
      const body = {
        title: f.title.trim(), summary: f.summary.trim() || null, classification: f.classification, category_id: f.category_id || null, owner_structure_id: f.owner_structure_id || null,
        language: f.language || null, keywords: f.keywords.split(',').map((k) => k.trim()).filter(Boolean), valid_from: f.valid_from || null, expires_at: f.expires_at || null, audience_ids: audiences,
      }
      onDone((await api.patch<{ data: DocumentDetailData }>(`/documents/${doc.id}`, body)).data)
    } catch (e) {
      setError(e instanceof ApiError ? e : new ApiError(500, { message: errorText(e) }))
    } finally {
      setBusy(false)
    }
  }
  return (
    <Modal title="Modifier les métadonnées" kicker={doc.reference} wide onClose={onClose} footer={<><Button variant="secondary" onClick={onClose}>Annuler</Button><Button onClick={submit} busy={busy} disabled={!f.title.trim()}>Enregistrer</Button></>}>
      <div className="form-grid">
        <Field label="Titre" required wide error={error?.field('title')}><input value={f.title} maxLength={200} onChange={(e) => set('title', e.target.value)} /></Field>
        <Field label="Résumé" wide error={error?.field('summary')}><textarea value={f.summary} maxLength={2000} onChange={(e) => set('summary', e.target.value)} /></Field>
        <Field label="Classification" required error={error?.field('classification')} help={doc.status === 'published' ? 'Changer la classification d’un document publié modifie immédiatement ses droits d’accès.' : undefined}>
          <select value={f.classification} onChange={(e) => set('classification', e.target.value)}>{Object.entries(CLASS_LABEL).map(([k, l]) => <option key={k} value={k}>{l}</option>)}</select>
        </Field>
        <Field label="Catégorie" error={error?.field('category_id')}>
          <select value={f.category_id} onChange={(e) => set('category_id', e.target.value)}><option value="">— Aucune —</option>{(refs.data?.categories ?? []).map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}</select>
        </Field>
        <Field label="Structure propriétaire" error={error?.field('owner_structure_id')}>
          <select value={f.owner_structure_id} onChange={(e) => set('owner_structure_id', e.target.value)}><option value="">— Inchangée —</option>{(refs.data?.structures ?? []).map((s) => <option key={s.id} value={s.id}>{s.name}</option>)}</select>
        </Field>
        <Field label="Langue" error={error?.field('language')}>
          <select value={f.language} onChange={(e) => set('language', e.target.value)}><option value="">—</option>{Object.entries(LANG_LABEL).map(([k, l]) => <option key={k} value={k}>{l}</option>)}</select>
        </Field>
        <Field label="Mots-clés" wide error={error?.field('keywords')} help="Séparés par des virgules."><input value={f.keywords} onChange={(e) => set('keywords', e.target.value)} /></Field>
        <Field label="Valide à partir du" error={error?.field('valid_from')}><input type="date" value={f.valid_from} onChange={(e) => set('valid_from', e.target.value)} /></Field>
        <Field label="Expire le" error={error?.field('expires_at')}><input type="date" value={f.expires_at} min={f.valid_from || undefined} onChange={(e) => set('expires_at', e.target.value)} /></Field>
      </div>
      {f.classification !== 'public' && (
        <fieldset className="field" style={{ border: 0, padding: 0, margin: '14px 0 0' }}>
          <legend style={{ padding: 0 }}>Publics destinataires</legend>
          <div className="check-grid" style={{ marginTop: 6, maxHeight: 180, overflow: 'auto' }}>
            {(refs.data?.structures ?? []).map((s) => (
              <label key={s.id}><input type="checkbox" checked={audiences.includes(s.id)} onChange={(e) => setAudiences((a) => (e.target.checked ? [...a, s.id] : a.filter((x) => x !== s.id)))} /> {s.name}</label>
            ))}
          </div>
        </fieldset>
      )}
      {error && !Object.keys(error.errors).length && <div style={{ marginTop: 12 }}><Notice tone="danger">{error.message}</Notice></div>}
    </Modal>
  )
}
