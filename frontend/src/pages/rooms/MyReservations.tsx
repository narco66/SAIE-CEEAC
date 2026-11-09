import { useState } from 'react'
import { useNavigate } from 'react-router'
import { useQueryClient } from '@tanstack/react-query'
import { useAuth } from '../../lib/auth'
import { useList } from '../../lib/hooks'
import { OCC_STATUS, SERVICE_STATUS } from '../../lib/format'
import { Button, EmptyState, ErrorState, Loading, PageHeading, Pagination, Status, StatusOf, Tabs, Toolbar, useDebounced } from '../../components/ui'
import { CancelOccurrenceDialog, pickOccurrence, recurrenceLabel, slotLabel, TZ_LABEL, type Occurrence, type Reservation } from './shared'

type When = 'future' | 'past' | 'recurring'

/** V26 Mes réservations : passées, futures et récurrentes. */
export default function MyReservations() {
  const { me } = useAuth()
  const navigate = useNavigate()
  const client = useQueryClient()
  const [when, setWhen] = useState<When>('future')
  const [q, setQ] = useState('')
  const search = useDebounced(q)
  const list = useList<Reservation>('reservations', '/reservations', { scope: 'mine', when, q: search || undefined })
  const [cancelling, setCancelling] = useState<{ r: Reservation; o: Occurrence } | null>(null)

  return (
    <>
      <PageHeading title="Mes réservations" description={`Réservations dont vous êtes l’organisateur · ${TZ_LABEL}`} demo={me.demo_mode}
        actions={<Button icon="plus" onClick={() => navigate('/reservations/nouvelle')}>Nouvelle réservation</Button>} />
      <Tabs value={when} onChange={(v) => { setWhen(v); list.setPage(1) }} items={[
        { value: 'future', label: 'À venir' }, { value: 'past', label: 'Passées' }, { value: 'recurring', label: 'Récurrentes' },
      ]} />
      <section className="panel table-panel">
        <Toolbar value={q} onChange={(v) => { setQ(v); list.setPage(1) }} placeholder="Référence ou objet…" />
        {list.isLoading ? <Loading /> : list.isError ? <ErrorState error={list.error} onRetry={() => list.refetch()} /> : list.items.length === 0 ? (
          <EmptyState title="Aucune réservation" text={when === 'future' ? 'Vous n’avez aucune réservation à venir.' : when === 'past' ? 'Aucune réservation passée.' : 'Aucune série récurrente.'}
            action={when === 'future' ? <Button icon="plus" onClick={() => navigate('/reservations/nouvelle')}>Réserver une salle</Button> : undefined} />
        ) : (
          <div className="table-scroll">
            <table>
              <thead><tr><th>Référence</th><th>Salle</th><th>Créneau</th><th>Statut</th><th>Confidentialité</th><th>Prestations</th><th><span className="sr-only">Actions</span></th></tr></thead>
              <tbody>
                {list.items.map((r) => {
                  const o = pickOccurrence(r)
                  const services = r.occurrences.flatMap((x) => x.services)
                  const cancellable = o && ['pending', 'confirmed'].includes(o.status) && new Date(o.starts_at).getTime() > Date.now()
                  return (
                    <tr key={r.id}>
                      <td><a className="cell-link" href={`/reservations/${r.id}`} onClick={(e) => { e.preventDefault(); navigate(`/reservations/${r.id}`) }}>{r.reference}</a><small className="muted">{r.subject}</small></td>
                      <td>{o?.room.name ?? '—'}<small className="muted">{o?.room.floor ?? ''}</small></td>
                      <td>{o ? slotLabel(o.starts_at, o.ends_at) : '—'}{r.occurrences.length > 1 && <small className="muted">{r.occurrences.length} occurrences · {recurrenceLabel(r.recurrence)}</small>}</td>
                      <td>{o ? <StatusOf map={OCC_STATUS} value={o.status} /> : '—'}</td>
                      <td>{r.confidential ? <Status tone="warning">Confidentielle</Status> : <Status tone="neutral">Standard</Status>}</td>
                      <td>
                        {services.length === 0 ? <span className="muted">Aucune</span> : (
                          <div className="chip-row">
                            {(o?.services.length ? o.services : services).slice(0, 3).map((s) => <span className="chip" key={s.id}>{s.type ?? 'Prestation'} · {(SERVICE_STATUS[s.status] ?? [s.status])[0]}</span>)}
                          </div>
                        )}
                      </td>
                      <td>
                        <div className="row-actions">
                          <Button size="small" variant="secondary" icon="eye" onClick={() => navigate(`/reservations/${r.id}`)}>Ouvrir</Button>
                          {cancellable && o && <Button size="small" variant="secondary" icon="x" onClick={() => setCancelling({ r, o })}>Annuler</Button>}
                        </div>
                      </td>
                    </tr>
                  )
                })}
              </tbody>
            </table>
          </div>
        )}
        <Pagination meta={list.meta} onPage={list.setPage} />
      </section>
      {cancelling && (
        <CancelOccurrenceDialog reservation={cancelling.r} occurrence={cancelling.o} onClose={() => setCancelling(null)}
          onDone={() => { client.invalidateQueries({ queryKey: ['reservations'] }); client.invalidateQueries({ queryKey: ['reservation', cancelling.r.id] }) }} />
      )}
    </>
  )
}
