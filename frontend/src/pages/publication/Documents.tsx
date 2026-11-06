import { useState, type DragEvent } from 'react'
import { useNavigate } from 'react-router'
import { useQueryClient } from '@tanstack/react-query'
import { api, ApiError } from '../../lib/api'
import { useAuth } from '../../lib/auth'
import { useList } from '../../lib/hooks'
import { CLASS_LABEL, CLASS_TONE, fmtBytes, fmtDate, LANG_LABEL } from '../../lib/format'
import { Button, EmptyState, ErrorState, Field, Icon, Loading, Modal, Notice, PageHeading, Pagination, Select, Status, StatusOf, Toolbar, useDebounced, useToast } from '../../components/ui'
import { DOC_STATUS, errorText, useReferentials, type AssetVersion, type NamedRef, type UserRef } from './shared'
import { checkFile, MAX_DOC_MB } from './Media'

export interface DocumentRow {
  id: string; version: number; reference: string; title: string; summary: string | null; classification: string; status: string; language: string | null; keywords: string[]
  category: NamedRef | null; owner: NamedRef | null; author: UserRef | null; valid_from: string | null; expires_at: string | null
  current_version: { id: string; version: number; file: AssetVersion | null } | null; diffusion_allowed: boolean; published_at: string | null; updated_at: string | null
}

export const DOC_EXTENSIONS = ['pdf', 'docx', 'pptx', 'xlsx', 'jpg', 'jpeg', 'png', 'webp']

/** V13 Bibliothèque documentaire : recherche plein texte, filtres et compteurs limités aux droits (DOC-05, DOC-06). */
export default function Documents() {
  const { me, can } = useAuth()
  const navigate = useNavigate()
  const refs = useReferentials()
  const [q, setQ] = useState('')
  const [classification, setClassification] = useState('')
  const [status, setStatus] = useState('')
  const [category, setCategory] = useState('')
  const [expiring, setExpiring] = useState(false)
  const [upload, setUpload] = useState(false)
  const search = useDebounced(q, 400)
  const list = useList<DocumentRow>('documents', '/documents', { q: search || undefined, classification: classification || undefined, status: status || undefined, category_id: category || undefined, expiring: expiring || undefined })
  const stats = list.meta?.stats as { active: number; internal: number; expiring: number; scanning: number } | undefined
  const reset = (fn: (v: string) => void) => (v: string) => { fn(v); list.setPage(1) }

  return (
    <>
      <PageHeading
        title="Documents"
        description="Bibliothèque documentaire : seuls les documents autorisés par vos habilitations sont listés et comptés."
        demo={me.demo_mode}
        actions={can('documents.manage') && <Button icon="upload" onClick={() => setUpload(true)}>Déposer un document</Button>}
      />
      <div className="mini-stats">
        <div><Icon name="folder" /><span><strong>{stats?.active ?? '—'}</strong> documents publiés</span></div>
        <div><Icon name="shield" /><span><strong>{stats?.internal ?? '—'}</strong> internes</span></div>
        <div><Icon name="clock" /><span><strong>{stats?.expiring ?? '—'}</strong> expirent sous 30 jours</span></div>
        <div><Icon name="upload" /><span><strong>{stats?.scanning ?? '—'}</strong> en analyse</span></div>
      </div>
      <section className="panel table-panel">
        <Toolbar value={q} onChange={reset(setQ)} placeholder="Rechercher dans les métadonnées et le texte des PDF…">
          <div className="pub-filters">
            <Select label="Classification" allLabel="Toutes classifications" value={classification} onChange={reset(setClassification)} options={Object.entries(CLASS_LABEL).map(([value, label]) => ({ value, label }))} />
            <Select label="Statut" allLabel="Tous statuts" value={status} onChange={reset(setStatus)} options={['draft', 'published', 'unpublished'].map((s) => ({ value: s, label: DOC_STATUS[s][0] }))} />
            <Select label="Catégorie" allLabel="Toutes catégories" value={category} onChange={reset(setCategory)} options={(refs.data?.categories ?? []).map((c) => ({ value: c.id, label: c.name }))} />
            <label className="pub-check"><input type="checkbox" checked={expiring} onChange={(e) => { setExpiring(e.target.checked); list.setPage(1) }} /> Expire bientôt</label>
          </div>
        </Toolbar>
        {list.isLoading ? <Loading /> : list.isError ? <ErrorState error={list.error} onRetry={() => list.refetch()} /> : list.items.length === 0 ? (
          <EmptyState title="Aucun document" text="Aucun document autorisé ne correspond à votre recherche." />
        ) : (
          <div className="table-scroll">
            <table>
              <thead><tr><th>Référence & titre</th><th>Propriétaire</th><th>Classification</th><th>Version</th><th>Mis à jour</th><th>Statut</th><th>Diffusion</th></tr></thead>
              <tbody>
                {list.items.map((d) => (
                  <tr key={d.id}>
                    <td>
                      <div className="title-cell">
                        <span className="doc-thumb"><Icon name="file" /></span>
                        <span>
                          <a className="link-text" href={`/documents/${d.id}`} onClick={(e) => { e.preventDefault(); navigate(`/documents/${d.id}`) }}>{d.title}</a>
                          <small>{d.reference}{d.category ? ` · ${d.category.name}` : ''}{d.expires_at ? ` · expire le ${fmtDate(d.expires_at)}` : ''}</small>
                        </span>
                      </div>
                    </td>
                    <td>{d.owner?.name ?? '—'}</td>
                    <td><Status tone={CLASS_TONE[d.classification]}>{CLASS_LABEL[d.classification]}</Status></td>
                    <td>v{d.current_version?.version ?? '—'}{d.current_version?.file && <small>{d.current_version.file.extension.toUpperCase()} · {fmtBytes(d.current_version.file.size)}</small>}</td>
                    <td>{fmtDate(d.updated_at)}</td>
                    <td><StatusOf map={DOC_STATUS} value={d.status} /></td>
                    <td><span className={d.diffusion_allowed ? 'green-text' : 'red-text'}>{d.diffusion_allowed ? 'Autorisée' : 'Interdite'}</span></td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
        <Pagination meta={list.meta} onPage={list.setPage} />
      </section>
      {upload && <DocumentUpload onClose={() => setUpload(false)} onCreated={(id) => navigate(`/documents/${id}`)} />}
    </>
  )
}

function DocumentUpload({ onClose, onCreated }: { onClose: () => void; onCreated: (id: string) => void }) {
  const { me } = useAuth()
  const refs = useReferentials()
  const client = useQueryClient()
  const toast = useToast()
  const [file, setFile] = useState<File | null>(null)
  const [fileError, setFileError] = useState<string | null>(null)
  const [dragging, setDragging] = useState(false)
  const [f, setF] = useState({ title: '', summary: '', classification: 'interne', category_id: '', owner_structure_id: me.structure?.id ?? '', language: 'fr', keywords: '', valid_from: '', expires_at: '' })
  const [audiences, setAudiences] = useState<string[]>([])
  const [busy, setBusy] = useState(false)
  const [progress, setProgress] = useState(0)
  const [error, setError] = useState<ApiError | null>(null)
  const set = (k: keyof typeof f, v: string) => setF((x) => ({ ...x, [k]: v }))

  const pick = (x: File | undefined | null) => {
    if (!x) return
    const err = checkFile(x, DOC_EXTENSIONS)
    setFileError(err)
    setFile(err ? null : x)
    if (!err && !f.title) set('title', x.name.replace(/\.[^.]+$/, ''))
  }
  const onDrop = (e: DragEvent) => { e.preventDefault(); setDragging(false); pick(e.dataTransfer.files?.[0]) }

  const submit = async () => {
    if (!file) { setFileError('Choisissez un fichier.'); return }
    setBusy(true)
    setError(null)
    try {
      const fd = new FormData()
      fd.append('file', file)
      fd.append('title', f.title.trim())
      if (f.summary.trim()) fd.append('summary', f.summary.trim())
      fd.append('classification', f.classification)
      if (f.category_id) fd.append('category_id', f.category_id)
      if (f.owner_structure_id) fd.append('owner_structure_id', f.owner_structure_id)
      if (f.language) fd.append('language', f.language)
      f.keywords.split(',').map((k) => k.trim()).filter(Boolean).forEach((k) => fd.append('keywords[]', k))
      if (f.valid_from) fd.append('valid_from', f.valid_from)
      if (f.expires_at) fd.append('expires_at', f.expires_at)
      audiences.forEach((a) => fd.append('audience_ids[]', a))
      setProgress(0)
      const res = await api.upload<{ data: DocumentRow }>('/documents', fd, setProgress)
      client.invalidateQueries({ queryKey: ['documents'] })
      toast.success('Document déposé', `${res.data.reference} créé en brouillon ; le fichier est en cours d’analyse.`)
      onCreated(res.data.id)
    } catch (e) {
      setError(e instanceof ApiError ? e : new ApiError(500, { message: errorText(e) }))
    } finally {
      setBusy(false)
    }
  }

  return (
    <Modal title="Déposer un document" kicker="BIBLIOTHÈQUE DOCUMENTAIRE" wide onClose={onClose} footer={<>
      <Button variant="secondary" onClick={onClose}>Annuler</Button>
      <Button icon="upload" onClick={submit} busy={busy} disabled={!file || !f.title.trim()}>Déposer</Button>
    </>}>
      <div className={`upload-zone ${dragging ? 'dragging' : ''}`} onDragOver={(e) => { e.preventDefault(); setDragging(true) }} onDragLeave={() => setDragging(false)} onDrop={onDrop}>
        <Icon name="upload" size={28} />
        <strong>{file ? file.name : 'Glissez-déposez le document ici'}</strong>
        <span>{file ? fmtBytes(file.size) : `PDF, DOCX, PPTX, XLSX ou image · ${MAX_DOC_MB} Mo maximum`}</span>
        <label className="button secondary small">Parcourir…<input type="file" className="sr-only" accept={DOC_EXTENSIONS.map((e) => `.${e}`).join(',')} onChange={(e) => pick(e.target.files?.[0])} /></label>
        <ul><li>L’intégrité, le type MIME réel et l’antivirus sont contrôlés ; publication possible seulement une fois le fichier déclaré sain.</li></ul>
      </div>
      {(fileError || error?.field('file')) && <p className="field-error" role="alert">{fileError ?? error?.field('file')}</p>}
      {busy && <div style={{ marginTop: 8 }}><progress max={1} value={progress} style={{ width: '100%' }} aria-label="Progression de l’envoi" /><p className="muted small" role="status">Envoi en cours : {Math.round(progress * 100)} %</p></div>}
      <div className="form-grid" style={{ marginTop: 14 }}>
        <Field label="Titre" required wide error={error?.field('title')}><input value={f.title} maxLength={200} onChange={(e) => set('title', e.target.value)} /></Field>
        <Field label="Résumé" wide error={error?.field('summary')}><textarea value={f.summary} maxLength={2000} onChange={(e) => set('summary', e.target.value)} /></Field>
        <Field label="Classification" required error={error?.field('classification')} help="Un document restreint n’est jamais diffusé sur écran ni par QR.">
          <select value={f.classification} onChange={(e) => set('classification', e.target.value)}>{Object.entries(CLASS_LABEL).map(([k, l]) => <option key={k} value={k}>{l}</option>)}</select>
        </Field>
        <Field label="Catégorie" error={error?.field('category_id')}>
          <select value={f.category_id} onChange={(e) => set('category_id', e.target.value)}><option value="">— Aucune —</option>{(refs.data?.categories ?? []).filter((c) => c.is_active).map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}</select>
        </Field>
        <Field label="Structure propriétaire" error={error?.field('owner_structure_id')}>
          <select value={f.owner_structure_id} onChange={(e) => set('owner_structure_id', e.target.value)}><option value="">Ma structure</option>{(refs.data?.structures ?? []).map((s) => <option key={s.id} value={s.id}>{s.name}</option>)}</select>
        </Field>
        <Field label="Langue" error={error?.field('language')}>
          <select value={f.language} onChange={(e) => set('language', e.target.value)}>{Object.entries(LANG_LABEL).map(([k, l]) => <option key={k} value={k}>{l}</option>)}</select>
        </Field>
        <Field label="Mots-clés" wide error={error?.field('keywords')} help="Séparés par des virgules (20 au plus).">
          <input value={f.keywords} onChange={(e) => set('keywords', e.target.value)} />
        </Field>
        <Field label="Valide à partir du" error={error?.field('valid_from')}><input type="date" value={f.valid_from} onChange={(e) => set('valid_from', e.target.value)} /></Field>
        <Field label="Expire le" error={error?.field('expires_at')}><input type="date" value={f.expires_at} min={f.valid_from || undefined} onChange={(e) => set('expires_at', e.target.value)} /></Field>
      </div>
      {f.classification !== 'public' && (
        <fieldset className="field" style={{ border: 0, padding: 0, margin: '14px 0 0' }}>
          <legend style={{ padding: 0 }}>Publics destinataires (accusés de lecture)</legend>
          <span className="field-help">Facultatif : limite la consultation aux structures choisies et active le suivi des accusés de lecture.</span>
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
