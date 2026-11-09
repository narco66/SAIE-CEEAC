import { useState } from 'react'
import { keepPreviousData, useQuery, useQueryClient } from '@tanstack/react-query'
import { api, fileUrl, type ApiError, type Paginated } from '../../lib/api'
import { useAuth } from '../../lib/auth'
import { fmtBytes, fmtDateTime, fmtRelative } from '../../lib/format'
import { Button, EmptyState, ErrorState, Icon, Loading, Notice, PageHeading, Pagination, StatusOf, useToast } from '../../components/ui'
import { asApiError, EXPORT_STATUS, PERIOD_EXPORT_TYPES, PeriodFields, periodBounds, presetRange, requestExport, type ExportItem, type ExportType } from './common'
import './admin.css'

type ExportPage = Paginated<ExportItem> & { meta: Paginated<ExportItem>['meta'] & { types: ExportType[] } }

/** V41 Exports et traitements : fichiers privés, réautorisés au téléchargement, expiration gérée par le serveur. */
export default function Exports() {
  const { me } = useAuth()
  const client = useQueryClient()
  const toast = useToast()
  const [page, setPage] = useState(1)
  const [busyId, setBusyId] = useState<string | null>(null)

  const query = useQuery({
    queryKey: ['exports', 'list', page],
    queryFn: ({ signal }) => api.get<ExportPage>('/exports', { page, per_page: 20 }, signal),
    placeholderData: keepPreviousData,
    refetchInterval: (q) => (q.state.data?.data.some((e) => e.status === 'pending' || e.status === 'processing') ? 3000 : false),
  })

  const act = async (e: ExportItem, action: 'cancel' | 'retry') => {
    setBusyId(e.id)
    try {
      await api.post(`/exports/${e.id}/${action}`, undefined, false)
      toast.success(action === 'cancel' ? 'Export annulé' : 'Génération relancée')
      await client.invalidateQueries({ queryKey: ['exports'] })
    } catch (err) {
      toast.error(err)
    } finally {
      setBusyId(null)
    }
  }

  const items = query.data?.data ?? []
  const running = items.some((e) => e.status === 'pending' || e.status === 'processing')

  return (
    <>
      <PageHeading title="Exports & traitements" description="Vos demandes de documents PDF, Excel et CSV, leur progression et leur disponibilité." />
      {query.data && <QuickRequest types={query.data.meta.types} />}
      <section className="panel table-panel section-gap">
        <div className="panel-title">
          <div>
            <h2>Mes exports</h2>
            <p>{running ? 'Actualisation automatique toutes les 3 s tant qu’un traitement est en cours' : 'Les fichiers prêts expirent automatiquement : relancez la génération si nécessaire'}</p>
          </div>
          <Button variant="secondary" icon="refresh" size="small" onClick={() => query.refetch()} busy={query.isFetching && !running}>Actualiser</Button>
        </div>
        {query.isLoading ? <Loading /> : query.isError ? <ErrorState error={query.error} onRetry={() => query.refetch()} /> : items.length === 0 ? (
          <EmptyState title="Aucun export" text="Les documents demandés depuis les rapports, la supervision, le journal ou ce formulaire apparaîtront ici." />
        ) : (
          <>
            <div className="table-scroll">
              <table>
                <thead>
                  <tr><th scope="col">Type</th><th scope="col">Demandeur</th><th scope="col">Date</th><th scope="col">Progression</th><th scope="col">Résultat</th><th scope="col">Expiration</th><th scope="col">Erreur</th><th scope="col"><span className="sr-only">Actions</span></th></tr>
                </thead>
                <tbody>
                  {items.map((e) => {
                    const progress = e.status === 'ready' ? 100 : e.progress ?? 0
                    return (
                      <tr key={e.id}>
                        <td>
                          <div className="title-cell">
                            <span className="doc-thumb"><Icon name="file" /></span>
                            <span><strong>{e.label}</strong><small>{e.format.toUpperCase()}{e.file_name ? ` · ${e.file_name}` : ''}{e.classification ? ` · ${e.classification}` : ''}</small></span>
                          </div>
                        </td>
                        <td>{me.name}<small>Moi</small></td>
                        <td>{fmtDateTime(e.created_at)}<small>{fmtRelative(e.created_at)}</small></td>
                        <td style={{ minWidth: 120 }}>
                          <div className="progress" role="progressbar" aria-valuemin={0} aria-valuemax={100} aria-valuenow={progress} aria-label={`Progression ${progress} %`}><i style={{ width: `${progress}%` }} /></div>
                          <small>{progress} %</small>
                        </td>
                        <td><StatusOf map={EXPORT_STATUS} value={e.status} />{e.status === 'ready' && <small>{fmtBytes(e.size)}</small>}</td>
                        <td>{e.expires_at ? <>{fmtDateTime(e.expires_at)}<small>{e.status === 'expired' ? 'expiré' : fmtRelative(e.expires_at)}</small></> : '—'}</td>
                        <td className={e.error ? 'red-text' : ''}>{e.error ?? '—'}</td>
                        <td>
                          <div className="row-actions">
                            {e.status === 'ready' && <a className="button secondary small" href={fileUrl(`/exports/${e.id}/download`)} download><Icon name="download" size={14} /><span>Télécharger</span></a>}
                            {['failed', 'expired', 'cancelled'].includes(e.status) && <Button size="small" variant="secondary" icon="refresh" busy={busyId === e.id} onClick={() => act(e, 'retry')}>Relancer</Button>}
                            {e.status === 'pending' && <Button size="small" variant="secondary" icon="x" busy={busyId === e.id} onClick={() => act(e, 'cancel')}>Annuler</Button>}
                          </div>
                        </td>
                      </tr>
                    )
                  })}
                </tbody>
              </table>
            </div>
            <Pagination meta={query.data?.meta} onPage={setPage} />
          </>
        )}
      </section>
    </>
  )
}

function QuickRequest({ types }: { types: ExportType[] }) {
  const client = useQueryClient()
  const toast = useToast()
  const available = types.filter((t) => t.allowed && PERIOD_EXPORT_TYPES.includes(t.type))
  const initial = presetRange('30')
  const [type, setType] = useState(available[0]?.type ?? '')
  const [format, setFormat] = useState('pdf')
  const [from, setFrom] = useState(initial.from)
  const [to, setTo] = useState(initial.to)
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<ApiError | null>(null)
  const def = available.find((t) => t.type === type)
  const formats = def?.formats ?? []
  const effectiveFormat = formats.includes(format) ? format : formats[0] ?? 'pdf'

  if (available.length === 0) {
    return <Notice tone="info">Aucun document périodique n’est autorisé pour vos habilitations. Les documents liés à un objet (confirmation de réservation, bon de programmation, fiche documentaire) se demandent depuis la fiche de l’objet.</Notice>
  }

  const submit = async () => {
    setBusy(true)
    setError(null)
    try {
      await requestExport(type, effectiveFormat, periodBounds(from, to))
      toast.success('Demande enregistrée', 'La génération démarre ; le suivi est mis à jour automatiquement.')
      await client.invalidateQueries({ queryKey: ['exports', 'list'] })
    } catch (e) {
      setError(asApiError(e))
    } finally {
      setBusy(false)
    }
  }

  return (
    <section className="panel">
      <div className="panel-title"><div><h2>Demande rapide</h2><p>Documents périodiques : les documents liés à un objet se demandent depuis sa fiche.</p></div></div>
      <form className="report-filters" style={{ margin: '0 19px 18px', flexWrap: 'wrap' }} onSubmit={(e) => { e.preventDefault(); submit() }}>
        <label htmlFor="qr-type">
          Type
          <select id="qr-type" className="input" value={type} onChange={(e) => setType(e.target.value)} aria-invalid={!!error?.field('type')}>
            {available.map((t) => <option key={t.type} value={t.type}>{t.label}</option>)}
          </select>
        </label>
        <label htmlFor="qr-format">
          Format
          <select id="qr-format" className="input" value={effectiveFormat} onChange={(e) => setFormat(e.target.value)} aria-invalid={!!error?.field('format')}>
            {formats.map((f) => <option key={f} value={f}>{f.toUpperCase()}</option>)}
          </select>
        </label>
        <PeriodFields idPrefix="qr" from={from} to={to} onChange={(f, t) => { setFrom(f); setTo(t) }} />
        <Button type="submit" icon="download" busy={busy} disabled={!type || !from || !to || from > to}>Demander</Button>
      </form>
      {error && <div style={{ margin: '0 19px 18px' }}><Notice tone="danger">{error.message}{error.field('format') ? ` — ${error.field('format')}` : ''}</Notice></div>}
    </section>
  )
}
