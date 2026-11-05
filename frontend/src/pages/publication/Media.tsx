import { useEffect, useState, type DragEvent } from 'react'
import { useNavigate } from 'react-router'
import { useQuery, useQueryClient } from '@tanstack/react-query'
import { api, ApiError, fileUrl } from '../../lib/api'
import { useAuth } from '../../lib/auth'
import { useList } from '../../lib/hooks'
import { CLASS_LABEL, CLASS_TONE, fmtBytes, fmtDate, LANG_LABEL, SCAN_STATUS } from '../../lib/format'
import { Button, EmptyState, ErrorState, Field, Icon, Loading, Modal, Notice, PageHeading, Pagination, Select, Status, StatusOf, Toolbar, useDebounced, useToast } from '../../components/ui'
import { errorText, KIND_ICON, KIND_LABEL, useReferentials, type Asset, type AssetVersion } from './shared'

export const ACCEPTED: Record<string, { kind: string; label: string }> = {
  jpg: { kind: 'image', label: 'JPEG' }, jpeg: { kind: 'image', label: 'JPEG' }, png: { kind: 'image', label: 'PNG' }, webp: { kind: 'image', label: 'WebP' },
  mp4: { kind: 'video', label: 'MP4' }, webm: { kind: 'video', label: 'WebM' }, pdf: { kind: 'pdf', label: 'PDF' },
  docx: { kind: 'office', label: 'DOCX' }, pptx: { kind: 'office', label: 'PPTX' }, xlsx: { kind: 'office', label: 'XLSX' },
}
export const MAX_DOC_MB = 50
export const MAX_VIDEO_MB = 500

/** Contrôle préalable au dépôt (type et taille) ; le serveur revérifie la signature MIME réelle. */
export function checkFile(file: File, allowed: string[] = Object.keys(ACCEPTED)): string | null {
  const ext = file.name.split('.').pop()?.toLowerCase() ?? ''
  if (!allowed.includes(ext)) return `Type .${ext || '?'} non autorisé. Formats acceptés : ${[...new Set(allowed.map((e) => ACCEPTED[e]?.label ?? e.toUpperCase()))].join(', ')}.`
  const limit = ACCEPTED[ext]?.kind === 'video' ? MAX_VIDEO_MB : MAX_DOC_MB
  if (file.size > limit * 1024 * 1024) return `Fichier trop volumineux (${fmtBytes(file.size)}) : limite ${limit} Mo pour ce type.`
  return null
}

function meta(a: Asset): string {
  const v = a.current_version
  const parts = [KIND_LABEL[a.kind] ?? a.kind]
  if (v?.width && v.height) parts.push(`${v.width} × ${v.height}`)
  if (v?.pages) parts.push(`${v.pages} pages`)
  if (v?.duration_s) parts.push(`${Math.floor(v.duration_s / 60)}:${String(Math.round(v.duration_s % 60)).padStart(2, '0')}`)
  parts.push(fmtBytes(v?.size))
  return parts.join(' · ')
}

/** V11 Médiathèque : grille ou liste, états d'analyse visibles séparément (DOC-01, DOC-02). */
export default function Media() {
  const { me, can } = useAuth()
  const navigate = useNavigate()
  const [view, setView] = useState<'grid' | 'list'>('grid')
  const [q, setQ] = useState('')
  const [kind, setKind] = useState('')
  const [status, setStatus] = useState('')
  const [classification, setClassification] = useState('')
  const [upload, setUpload] = useState(false)
  const search = useDebounced(q)
  const list = useList<Asset>('assets', '/assets', { q: search || undefined, kind: kind || undefined, status: status || undefined, classification: classification || undefined }, 24)
  const reset = (fn: (v: string) => void) => (v: string) => { fn(v); list.setPage(1) }
  const open = (a: Asset) => navigate(`/mediatheque/${a.id}`)

  return (
    <>
      <PageHeading
        title="Médiathèque"
        description="Images, vidéos, PDF et fichiers bureautiques : aucun fichier n’est utilisable avant la fin de l’analyse."
        demo={me.demo_mode}
        actions={can('media.upload') && <Button icon="upload" onClick={() => setUpload(true)}>Importer des fichiers</Button>}
      />
      <section className="panel">
        <Toolbar value={q} onChange={reset(setQ)} placeholder="Rechercher dans la médiathèque…" right={
          <div className="view-switch" role="group" aria-label="Affichage">
            <button className={view === 'grid' ? 'active' : ''} aria-pressed={view === 'grid'} onClick={() => setView('grid')} aria-label="Vue grille" title="Vue grille"><Icon name="layout" /></button>
            <button className={view === 'list' ? 'active' : ''} aria-pressed={view === 'list'} onClick={() => setView('list')} aria-label="Vue liste" title="Vue liste"><Icon name="list" /></button>
          </div>
        }>
          <div className="pub-filters">
            <Select label="Type" allLabel="Tous types" value={kind} onChange={reset(setKind)} options={Object.entries(KIND_LABEL).map(([value, label]) => ({ value, label }))} />
            <Select label="État d’analyse" allLabel="Tous états" value={status} onChange={reset(setStatus)} options={['quarantine', 'scanning', 'ready', 'rejected'].map((s) => ({ value: s, label: SCAN_STATUS[s][0] }))} />
            <Select label="Classification" allLabel="Toutes classifications" value={classification} onChange={reset(setClassification)} options={Object.entries(CLASS_LABEL).map(([value, label]) => ({ value, label }))} />
          </div>
        </Toolbar>
        {list.isLoading ? <Loading /> : list.isError ? <ErrorState error={list.error} onRetry={() => list.refetch()} /> : list.items.length === 0 ? (
          <EmptyState title="Aucun média" text="Aucun fichier ne correspond à vos filtres." action={can('media.upload') ? <Button icon="upload" onClick={() => setUpload(true)}>Importer un fichier</Button> : undefined} />
        ) : view === 'grid' ? (
          <div className="media-grid">
            {list.items.map((a) => {
              const v = a.current_version
              const thumb = v?.has_thumbnail && a.status === 'ready'
              return (
                <article className="media-card" key={a.id} tabIndex={0} role="link" aria-label={`${a.title} — ${SCAN_STATUS[a.status]?.[0] ?? a.status}`} onClick={() => open(a)} onKeyDown={(e) => { if (e.key === 'Enter') open(a) }}>
                  <div className={`media-preview ${a.kind === 'image' ? 'photo' : a.kind === 'video' ? 'video' : 'pdf'}`}>
                    {thumb ? <img src={fileUrl(`/asset-versions/${v!.id}/thumbnail`)} alt="" loading="lazy" /> : <Icon name={KIND_ICON[a.kind] ?? 'file'} size={32} />}
                    {a.kind === 'video' && v?.duration_s ? <span className="duration">{Math.floor(v.duration_s / 60)}:{String(Math.round(v.duration_s % 60)).padStart(2, '0')}</span> : null}
                  </div>
                  <div className="media-body">
                    <div><strong>{a.title}</strong><Status tone={CLASS_TONE[a.classification]}>{CLASS_LABEL[a.classification]}</Status></div>
                    <small>{meta(a)} · v{v?.version ?? '—'}</small>
                    <div className="media-foot">
                      <StatusOf map={SCAN_STATUS} value={a.status} />
                      <span>{a.usages ?? 0} utilisation{(a.usages ?? 0) > 1 ? 's' : ''}</span>
                    </div>
                  </div>
                </article>
              )
            })}
          </div>
        ) : (
          <div className="table-scroll">
            <table>
              <thead><tr><th>Média</th><th>Type</th><th>Poids</th><th>Version</th><th>Analyse</th><th>Classification</th><th>Utilisations</th><th>Ajouté</th></tr></thead>
              <tbody>
                {list.items.map((a) => (
                  <tr key={a.id}>
                    <td>
                      <div className="title-cell">
                        <span className="doc-thumb"><Icon name={KIND_ICON[a.kind] ?? 'file'} size={16} /></span>
                        <span><a className="link-text" href={`/mediatheque/${a.id}`} onClick={(e) => { e.preventDefault(); open(a) }}>{a.title}</a><small>{a.reference} · {a.current_version?.original_name}</small></span>
                      </div>
                    </td>
                    <td>{meta(a).split(' · ').slice(0, -1).join(' · ')}</td>
                    <td>{fmtBytes(a.current_version?.size)}</td>
                    <td>v{a.current_version?.version ?? '—'}</td>
                    <td><StatusOf map={SCAN_STATUS} value={a.status} /></td>
                    <td><Status tone={CLASS_TONE[a.classification]}>{CLASS_LABEL[a.classification]}</Status></td>
                    <td>{a.usages ?? 0}</td>
                    <td>{fmtDate(a.created_at)}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
        <Pagination meta={list.meta} onPage={list.setPage} />
      </section>
      {upload && <UploadModal onClose={() => setUpload(false)} onOpen={(id) => navigate(`/mediatheque/${id}`)} />}
    </>
  )
}

/** Étapes d'analyse (scan-steps) déduites de l'état serveur de la version. */
export function ScanSteps({ status, version }: { status: string; version: AssetVersion | null }) {
  const ready = status === 'ready'
  const rejected = status === 'rejected'
  const hashed = !!version?.sha256
  const step = (done: boolean, label: string, active: boolean) => {
    const cls = done ? 'done' : rejected ? 'failed' : active ? 'active' : 'pending'
    return <span className={cls}><Icon name={done ? 'check' : rejected ? 'x' : 'clock'} />{label}<span className="sr-only"> — {done ? 'terminé' : rejected ? 'interrompu' : active ? 'en cours' : 'en attente'}</span></span>
  }
  return (
    <div className="scan-steps" aria-live="polite">
      {step(ready || hashed, 'Extension et signature MIME réelle', status === 'scanning')}
      {step(ready || hashed, 'Empreinte SHA-256', status === 'scanning')}
      {step(ready, 'Analyse antivirus', status === 'scanning' && hashed)}
      {step(ready, ready && !version?.has_thumbnail ? 'Miniature (non applicable à ce type)' : 'Génération des miniatures et métadonnées', status === 'scanning' && hashed)}
      {rejected && <Notice tone="danger"><strong>Fichier rejeté.</strong> {version?.scan_detail ?? 'Le contrôle a échoué.'}</Notice>}
      {status === 'quarantine' && <p className="muted small">Fichier en quarantaine : l’analyse va démarrer.</p>}
    </div>
  )
}

function UploadModal({ onClose, onOpen }: { onClose: () => void; onOpen: (id: string) => void }) {
  const client = useQueryClient()
  const toast = useToast()
  const refs = useReferentials()
  const [file, setFile] = useState<File | null>(null)
  const [fileError, setFileError] = useState<string | null>(null)
  const [dragging, setDragging] = useState(false)
  const [title, setTitle] = useState('')
  const [classification, setClassification] = useState('interne')
  const [language, setLanguage] = useState('')
  const [category, setCategory] = useState('')
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<ApiError | null>(null)
  const [uploaded, setUploaded] = useState<Asset | null>(null)

  const statusQuery = useQuery({
    queryKey: ['asset-status', uploaded?.id],
    queryFn: () => api.get<{ data: { status: string; version: AssetVersion | null } }>(`/assets/${uploaded!.id}/status`).then((r) => r.data),
    enabled: !!uploaded,
    refetchInterval: (q) => (['ready', 'rejected'].includes(q.state.data?.status ?? '') ? false : 2000),
  })

  const pick = (f: File | undefined | null) => {
    if (!f) return
    const err = checkFile(f)
    setFileError(err)
    setFile(err ? null : f)
    if (!err && !title) setTitle(f.name.replace(/\.[^.]+$/, ''))
  }
  const onDrop = (e: DragEvent) => { e.preventDefault(); setDragging(false); pick(e.dataTransfer.files?.[0]) }

  const submit = async () => {
    if (!file) { setFileError('Choisissez un fichier.'); return }
    setBusy(true)
    setError(null)
    try {
      const fd = new FormData()
      fd.append('file', file)
      if (title.trim()) fd.append('title', title.trim())
      fd.append('classification', classification)
      if (language) fd.append('language', language)
      if (category) fd.append('category_id', category)
      const res = await api.upload<{ data: Asset }>('/assets', fd)
      setUploaded(res.data)
      client.invalidateQueries({ queryKey: ['assets'] })
      toast.success('Fichier déposé', 'Il est en quarantaine jusqu’à la fin de l’analyse.')
    } catch (e) {
      setError(e instanceof ApiError ? e : new ApiError(500, { message: errorText(e) }))
    } finally {
      setBusy(false)
    }
  }

  const st = statusQuery.data?.status ?? uploaded?.status ?? 'quarantine'
  const finished = st === 'ready' || st === 'rejected'
  useEffect(() => { if (finished) client.invalidateQueries({ queryKey: ['assets'] }) }, [finished, client])

  return (
    <Modal title={uploaded ? 'Analyse du fichier' : 'Importer un fichier'} kicker="MÉDIATHÈQUE" onClose={onClose} footer={uploaded ? <>
      <Button variant="secondary" onClick={onClose}>Fermer</Button>
      <Button onClick={() => onOpen(uploaded.id)}>Ouvrir la fiche</Button>
    </> : <>
      <Button variant="secondary" onClick={onClose}>Annuler</Button>
      <Button icon="upload" onClick={submit} busy={busy} disabled={!file}>Déposer</Button>
    </>}>
      {uploaded ? (
        <>
          <p style={{ marginBottom: 12 }}><strong>{uploaded.title}</strong> · {fmtBytes(uploaded.current_version?.size)} · <StatusOf map={SCAN_STATUS} value={st} /></p>
          <ScanSteps status={st} version={statusQuery.data?.version ?? uploaded.current_version} />
          {statusQuery.isError && <Notice tone="warning">Suivi momentanément indisponible : {errorText(statusQuery.error)}</Notice>}
          {st === 'ready' && <div className="success-box" style={{ marginTop: 12 }}><Icon name="check" /><span><strong>Fichier sain et prêt</strong><small>Il peut désormais être utilisé dans un contenu ou un document.</small></span></div>}
        </>
      ) : (
        <>
          <div className={`upload-zone ${dragging ? 'dragging' : ''}`} onDragOver={(e) => { e.preventDefault(); setDragging(true) }} onDragLeave={() => setDragging(false)} onDrop={onDrop}>
            <Icon name="upload" size={28} />
            <strong>{file ? file.name : 'Glissez-déposez un fichier ici'}</strong>
            <span>{file ? `${fmtBytes(file.size)} · prêt à déposer` : 'ou choisissez-le sur votre poste'}</span>
            <label className="button secondary small">
              Parcourir…
              <input type="file" className="sr-only" accept={Object.keys(ACCEPTED).map((e) => `.${e}`).join(',')} onChange={(e) => pick(e.target.files?.[0])} />
            </label>
            <ul>
              <li>Formats : JPEG, PNG, WebP, MP4, WebM, PDF, DOCX, PPTX, XLSX</li>
              <li>Limites : {MAX_DOC_MB} Mo par document ou image · {MAX_VIDEO_MB} Mo par vidéo</li>
              <li>Signature MIME, empreinte SHA-256 et antivirus contrôlés après dépôt</li>
            </ul>
          </div>
          {(fileError || error?.field('file')) && <p className="field-error" role="alert">{fileError ?? error?.field('file')}</p>}
          <div className="form-grid" style={{ marginTop: 14 }}>
            <Field label="Titre" wide error={error?.field('title')}><input value={title} maxLength={200} onChange={(e) => setTitle(e.target.value)} /></Field>
            <Field label="Classification" required error={error?.field('classification')} help="Un média restreint ne peut illustrer un contenu diffusé.">
              <select value={classification} onChange={(e) => setClassification(e.target.value)}>{Object.entries(CLASS_LABEL).map(([v, l]) => <option key={v} value={v}>{l}</option>)}</select>
            </Field>
            <Field label="Langue" error={error?.field('language')}>
              <select value={language} onChange={(e) => setLanguage(e.target.value)}><option value="">Sans objet</option>{Object.entries(LANG_LABEL).map(([v, l]) => <option key={v} value={v}>{l}</option>)}</select>
            </Field>
            <Field label="Catégorie" wide error={error?.field('category_id')}>
              <select value={category} onChange={(e) => setCategory(e.target.value)}><option value="">— Aucune —</option>{(refs.data?.categories ?? []).filter((c) => c.is_active).map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}</select>
            </Field>
          </div>
          {error && !error.field('file') && <div style={{ marginTop: 12 }}><Notice tone="danger">{error.message}</Notice></div>}
          {busy && <p className="muted small" style={{ marginTop: 8 }} role="status">Envoi en cours… ne fermez pas cette fenêtre.</p>}
        </>
      )}
    </Modal>
  )
}
