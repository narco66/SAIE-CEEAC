import { useEffect, useState } from 'react'
import { useNavigate, useParams } from 'react-router'
import { useQuery, useQueryClient } from '@tanstack/react-query'
import { api, ApiError, fileUrl } from '../../lib/api'
import { useAuth } from '../../lib/auth'
import { CLASS_LABEL, CLASS_TONE, fmtBytes, fmtDateTime, LANG_LABEL, SCAN_STATUS } from '../../lib/format'
import { Button, ConfirmDialog, ErrorState, Field, Icon, Kv, Loading, Notice, PageHeading, Status, StatusOf, useToast } from '../../components/ui'
import { errorText, KIND_LABEL, useReferentials, type Asset, type AssetVersion, type Usage } from './shared'
import { checkFile, ScanSteps } from './Media'

interface AssetDetail extends Asset { versions: AssetVersion[]; usages_detail: Usage[]; can_edit: boolean }

function UsageList({ usages, onOpen }: { usages: Usage[]; onOpen: (u: Usage) => void }) {
  if (!usages.length) return <p className="muted small">Aucune utilisation : ce média n’est référencé par aucun contenu ni document.</p>
  return (
    <div className="timeline-list">
      {usages.map((u) => (
        <div key={`${u.type}-${u.id}`}>
          <time>{u.type === 'content' ? 'Contenu' : 'Document'} · {u.reference}</time>
          <a className="link-text" href={u.type === 'content' ? `/affichage/contenus/${u.id}/edition` : `/documents/${u.id}`} onClick={(e) => { e.preventDefault(); onOpen(u) }}>{u.title}</a>
        </div>
      ))}
    </div>
  )
}

/** V12 Fiche média : aperçu (seulement si sain), métadonnées, versions, empreinte, analyse et utilisations (DOC-02, DOC-04). */
export default function MediaDetail() {
  const { id } = useParams()
  const navigate = useNavigate()
  const client = useQueryClient()
  const toast = useToast()
  const { me } = useAuth()
  const refs = useReferentials()
  const query = useQuery({
    queryKey: ['asset', id],
    queryFn: ({ signal }) => api.get<{ data: AssetDetail }>(`/assets/${id}`, undefined, signal).then((r) => r.data),
    refetchInterval: (q) => (['quarantine', 'scanning'].includes(q.state.data?.current_version?.scan_status ?? '') ? 3000 : false),
  })
  const a = query.data
  const [title, setTitle] = useState('')
  const [classification, setClassification] = useState('')
  const [language, setLanguage] = useState('')
  const [category, setCategory] = useState('')
  const [saving, setSaving] = useState(false)
  const [error, setError] = useState<ApiError | null>(null)
  const [blocking, setBlocking] = useState<Usage[] | null>(null)
  const [trash, setTrash] = useState(false)
  const [uploading, setUploading] = useState(false)
  const [versionError, setVersionError] = useState<string | null>(null)
  const [initFor, setInitFor] = useState<string | null>(null)

  useEffect(() => {
    if (a && refs.data && initFor !== a.updated_at) {
      setTitle(a.title)
      setClassification(a.classification)
      setLanguage(a.language ?? '')
      setCategory(refs.data.categories.find((c) => c.name === a.category)?.id ?? '')
      setInitFor(a.updated_at)
    }
  }, [a, refs.data, initFor])

  if (query.isLoading) return <Loading />
  if (query.isError || !a) return <ErrorState error={query.error} onRetry={() => query.refetch()} />

  const v = a.current_version
  const ready = v?.scan_status === 'ready'
  const src = v ? fileUrl(`/asset-versions/${v.id}/file`) : ''
  const openUsage = (u: Usage) => navigate(u.type === 'content' ? `/affichage/contenus/${u.id}/edition` : `/documents/${u.id}`)
  const dirty = title !== a.title || classification !== a.classification || language !== (a.language ?? '') || category !== (refs.data?.categories.find((c) => c.name === a.category)?.id ?? '')

  const save = async () => {
    setSaving(true)
    setError(null)
    setBlocking(null)
    try {
      const body: Record<string, unknown> = { title: title.trim(), language: language || null, category_id: category || null }
      if (classification !== a.classification) body.classification = classification
      const res = await api.patch<{ data: AssetDetail }>(`/assets/${a.id}`, body, { 'If-Match': String(a.version) })
      client.setQueryData(['asset', id], res.data)
      client.invalidateQueries({ queryKey: ['assets'] })
      toast.success('Métadonnées enregistrées')
    } catch (e) {
      const err = e instanceof ApiError ? e : new ApiError(500, { message: errorText(e) })
      setError(err)
      if (err.code === 'ASSET_IN_USE') setBlocking((err.details.usages as Usage[]) ?? [])
    } finally {
      setSaving(false)
    }
  }

  const addVersion = async (file: File) => {
    const err = checkFile(file, [a.current_version?.extension ?? ''].concat(a.kind === 'image' ? ['jpg', 'jpeg', 'png', 'webp'] : a.kind === 'video' ? ['mp4', 'webm'] : a.kind === 'pdf' ? ['pdf'] : ['docx', 'pptx', 'xlsx']).filter(Boolean))
    setVersionError(err)
    if (err) return
    setUploading(true)
    try {
      const fd = new FormData()
      fd.append('file', file)
      const res = await api.upload<{ data: AssetDetail }>(`/assets/${a.id}/versions`, fd)
      client.setQueryData(['asset', id], res.data)
      client.invalidateQueries({ queryKey: ['assets'] })
      toast.success('Nouvelle version déposée', 'Elle reste en quarantaine jusqu’à la fin de l’analyse.')
    } catch (e) {
      setVersionError(errorText(e))
    } finally {
      setUploading(false)
    }
  }

  return (
    <>
      <PageHeading
        title={a.title}
        description={`${a.reference} · ${KIND_LABEL[a.kind] ?? a.kind}${a.owner ? ` · ${a.owner.name}` : ''}`}
        demo={me.demo_mode}
        actions={<>
          <Button variant="secondary" icon="image" onClick={() => navigate('/mediatheque')}>Médiathèque</Button>
          {ready && <a className="button secondary" href={fileUrl(`/asset-versions/${v!.id}/file`, { download: 1 })}><Icon name="download" size={16} /><span>Télécharger</span></a>}
          {a.can_edit && <Button variant="danger" icon="trash" onClick={() => { setBlocking(null); setTrash(true) }}>Mettre à la corbeille</Button>}
        </>}
      />
      {blocking && (
        <div style={{ marginBottom: 12 }}>
          <Notice tone="danger"><strong>Ce média est utilisé.</strong> Révisez d’abord les éléments suivants :<div style={{ marginTop: 8 }}><UsageList usages={blocking} onOpen={openUsage} /></div></Notice>
        </div>
      )}
      <div className="pub-editor-grid">
        <div style={{ display: 'grid', gap: 15 }}>
          <section className="panel">
            <div className="panel-title"><div><h2>Aperçu</h2><p>Version {v?.version ?? '—'} · {v?.original_name}</p></div><StatusOf map={SCAN_STATUS} value={v?.scan_status ?? a.status} /></div>
            <div className="panel-body">
              {!v ? <Notice tone="warning">Aucune version disponible.</Notice> : !ready ? (
                <>
                  <Notice tone={v.scan_status === 'rejected' ? 'danger' : 'warning'}>Aperçu indisponible tant que l’analyse n’est pas saine ({SCAN_STATUS[v.scan_status]?.[0] ?? v.scan_status}).</Notice>
                  <div style={{ marginTop: 12 }}><ScanSteps status={v.scan_status} version={v} /></div>
                </>
              ) : a.kind === 'image' ? (
                <div className="pub-media-preview"><img src={src} alt={`Aperçu de ${a.title}`} /></div>
              ) : a.kind === 'video' ? (
                <div className="pub-media-preview"><video src={src} controls preload="metadata" aria-label={`Lecture de ${a.title}`} /></div>
              ) : a.kind === 'pdf' ? (
                <iframe className="pub-preview-pdf" src={src} title={`Aperçu PDF de ${a.title}`} />
              ) : (
                <Notice tone="info">Aperçu non disponible pour un fichier bureautique : téléchargez-le pour le consulter.</Notice>
              )}
            </div>
          </section>
          <section className="panel">
            <div className="panel-title">
              <div><h2>Versions</h2><p>Un remplacement crée une nouvelle version, analysée à son tour.</p></div>
              {a.can_edit && (
                <label className="button secondary small" style={{ cursor: 'pointer' }}>
                  <Icon name="upload" size={14} /> {uploading ? 'Dépôt…' : 'Nouvelle version'}
                  <input type="file" className="sr-only" disabled={uploading} onChange={(e) => { const f = e.target.files?.[0]; if (f) addVersion(f); e.target.value = '' }} />
                </label>
              )}
            </div>
            {versionError && <div className="panel-body"><Notice tone="danger">{versionError}</Notice></div>}
            <div className="table-scroll">
              <table>
                <thead><tr><th>Version</th><th>Fichier</th><th>Poids</th><th>Empreinte SHA-256</th><th>Analyse</th><th>Déposée</th></tr></thead>
                <tbody>
                  {a.versions.map((x) => (
                    <tr key={x.id}>
                      <td><strong>v{x.version}</strong>{x.id === v?.id && <small>Courante</small>}</td>
                      <td>{x.original_name}<small>{x.mime ?? x.extension}{x.width && x.height ? ` · ${x.width} × ${x.height}` : ''}{x.pages ? ` · ${x.pages} p.` : ''}{x.duration_s ? ` · ${x.duration_s} s` : ''}</small></td>
                      <td>{fmtBytes(x.size)}</td>
                      <td><span className="mono" title={x.sha256 ?? ''}>{x.sha256 ? `${x.sha256.slice(0, 16)}…` : 'non calculée'}</span></td>
                      <td><StatusOf map={SCAN_STATUS} value={x.scan_status} />{x.scan_engine && <small>{x.scan_engine}</small>}{x.scan_detail && <small>{x.scan_detail}</small>}</td>
                      <td>{fmtDateTime(x.created_at)}{x.processed_at && <small>analysée {fmtDateTime(x.processed_at)}</small>}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          </section>
        </div>
        <aside className="pub-aside">
          <section className="panel">
            <div className="panel-title"><div><h2>Métadonnées</h2><p>{a.can_edit ? 'Modifiables par le propriétaire' : 'Lecture seule'}</p></div></div>
            <div className="panel-body">
              <fieldset disabled={!a.can_edit} style={{ border: 0, padding: 0, margin: 0, display: 'grid', gap: 10 }}>
                <Field label="Titre" required error={error?.field('title')}><input value={title} maxLength={200} onChange={(e) => setTitle(e.target.value)} /></Field>
                <Field label="Classification" required error={error?.field('classification')} help={a.usages ? 'Média utilisé : la classification ne peut changer qu’après révision des contenus.' : undefined}>
                  <select value={classification} onChange={(e) => setClassification(e.target.value)}>{Object.entries(CLASS_LABEL).map(([k, l]) => <option key={k} value={k}>{l}</option>)}</select>
                </Field>
                <Field label="Langue" error={error?.field('language')}>
                  <select value={language} onChange={(e) => setLanguage(e.target.value)}><option value="">Sans objet</option>{Object.entries(LANG_LABEL).map(([k, l]) => <option key={k} value={k}>{l}</option>)}</select>
                </Field>
                <Field label="Catégorie" error={error?.field('category_id')}>
                  <select value={category} onChange={(e) => setCategory(e.target.value)}><option value="">— Aucune —</option>{(refs.data?.categories ?? []).map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}</select>
                </Field>
                {error && !Object.keys(error.errors).length && error.code !== 'ASSET_IN_USE' && <Notice tone="danger">{error.message}</Notice>}
                {a.can_edit && <div className="form-actions" style={{ marginTop: 4 }}><Button onClick={save} busy={saving} disabled={!dirty || !title.trim()}>Enregistrer</Button></div>}
              </fieldset>
              <div style={{ marginTop: 14 }}>
                <Kv items={[
                  ['Référence', a.reference],
                  ['Classification', <Status tone={CLASS_TONE[a.classification]}>{CLASS_LABEL[a.classification]}</Status>],
                  ['Propriétaire', a.owner?.name],
                  ['Déposé par', a.creator?.name],
                  ['Créé le', fmtDateTime(a.created_at)],
                  ['Empreinte', v?.sha256 ? <span className="mono">{v.sha256}</span> : 'non calculée'],
                  ['Moteur d’analyse', v?.scan_engine ?? '—'],
                  ['Détail d’analyse', v?.scan_detail ?? '—'],
                ]} />
              </div>
            </div>
          </section>
          <section className="panel">
            <div className="panel-title"><div><h2>Utilisations ({a.usages_detail.length})</h2><p>Contenus et documents qui référencent ce média</p></div></div>
            <div className="panel-body"><UsageList usages={a.usages_detail} onOpen={openUsage} /></div>
          </section>
        </aside>
      </div>
      {trash && (
        <ConfirmDialog
          title="Mettre le média à la corbeille"
          object={`${a.reference} · ${a.title}`}
          consequence={a.usages_detail.length ? `Ce média est utilisé par ${a.usages_detail.length} élément(s) : la suppression sera refusée.` : 'Le média ne sera plus proposé dans la médiathèque. Les versions approuvées déjà diffusées ne sont pas affectées.'}
          confirmLabel="Mettre à la corbeille"
          danger
          onClose={() => setTrash(false)}
          onConfirm={async () => {
            try {
              await api.post(`/assets/${a.id}/trash`)
            } catch (e) {
              if (e instanceof ApiError && e.code === 'ASSET_IN_USE') setBlocking((e.details.usages as Usage[]) ?? [])
              throw e
            }
            client.invalidateQueries({ queryKey: ['assets'] })
            toast.success('Média mis à la corbeille', a.title)
            navigate('/mediatheque')
          }}
        />
      )}
    </>
  )
}
