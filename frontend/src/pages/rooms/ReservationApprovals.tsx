import { useState } from 'react'
import { useNavigate } from 'react-router'
import { useQueryClient } from '@tanstack/react-query'
import { api } from '../../lib/api'
import { useAuth } from '../../lib/auth'
import { useList } from '../../lib/hooks'
import { fmtDateTime, fmtRelative } from '../../lib/format'
import { Button, ConfirmDialog, EmptyState, ErrorState, Loading, PageHeading, Pagination, Status, Toolbar, useDebounced, useToast } from '../../components/ui'
import { OccurrenceEditor } from './OccurrenceEditor'
import { slotLabel, TZ_LABEL, type Occurrence, type Reservation } from './shared'

type Action = { kind: 'approve' | 'refuse' | 'move'; r: Reservation; o: Occurrence }

/** V29 Validation des réservations : ancienneté, échéance de blocage et échéance HAB-04 (escalade au suppléant). */
export default function ReservationApprovals() {
  const { me } = useAuth()
  const navigate = useNavigate()
  const client = useQueryClient()
  const toast = useToast()
  const [q, setQ] = useState('')
  const search = useDebounced(q)
  const list = useList<Reservation>('reservations', '/reservations', { scope: 'to_validate', q: search || undefined })
  const [action, setAction] = useState<Action | null>(null)
  const refresh = () => {
    client.invalidateQueries({ queryKey: ['reservations'] })
    client.invalidateQueries({ queryKey: ['rooms-calendar'] })
  }

  return (
    <>
      <PageHeading title="Réservations à valider" description={`Demandes en attente sur les salles dont vous êtes gestionnaire · ${TZ_LABEL}`} demo={me.demo_mode} />
      <section className="panel table-panel">
        <Toolbar value={q} onChange={(v) => { setQ(v); list.setPage(1) }} placeholder="Rechercher une demande de réservation…" />
        {list.isLoading ? <Loading /> : list.isError ? <ErrorState error={list.error} onRetry={() => list.refetch()} /> : list.items.length === 0 ? (
          <EmptyState title="Aucune demande en attente" text="Les nouvelles demandes sur vos salles apparaîtront ici et dans « Mes tâches »." />
        ) : (
          <div className="table-scroll">
            <table>
              <thead><tr><th>Demande</th><th>Salle</th><th>Créneau</th><th>Organisateur</th><th>Ancienneté</th><th>Blocage jusqu’à</th><th>Échéance HAB-04</th><th><span className="sr-only">Actions</span></th></tr></thead>
              <tbody>
                {list.items.map((r) => {
                  const pending = r.occurrences.filter((o) => o.status === 'pending').sort((a, b) => a.starts_at.localeCompare(b.starts_at))
                  const o = pending[0]
                  if (!o) return null
                  const escalated = !!r.approval?.escalated_at
                  const late = r.approval?.due_at && new Date(r.approval.due_at).getTime() < Date.now()
                  return (
                    <tr key={r.id}>
                      <td>
                        <a className="cell-link" href={`/reservations/${r.id}`} onClick={(e) => { e.preventDefault(); navigate(`/reservations/${r.id}`) }}>{r.subject}</a>
                        <small className="muted">{r.reference} · {r.participant_count} participant(s){r.confidential ? ' · confidentielle' : ''}</small>
                      </td>
                      <td>{o.room.name}<small className="muted">{o.room.floor} · {o.room.capacity} places</small></td>
                      <td>{slotLabel(o.starts_at, o.ends_at)}{pending.length > 1 && <small className="muted">{pending.length} occurrences en attente</small>}</td>
                      <td>{r.organizer?.name ?? '—'}<small className="muted">{r.structure ?? ''}</small></td>
                      <td title={fmtDateTime(r.created_at)}>{fmtRelative(r.created_at)}</td>
                      <td>{o.hold_until ? <>{fmtDateTime(o.hold_until)}<small className="muted">{fmtRelative(o.hold_until)}</small></> : '—'}</td>
                      <td>
                        {escalated ? <Status tone="danger" title={`Escaladée le ${fmtDateTime(r.approval?.escalated_at)}`}>Escaladée</Status>
                          : r.approval?.due_at ? <Status tone={late ? 'danger' : 'warning'}>{late ? 'Dépassée' : 'Échéance'} {fmtRelative(r.approval.due_at)}</Status> : '—'}
                        {r.approval?.due_at && <small className="muted">{fmtDateTime(r.approval.due_at)}</small>}
                      </td>
                      <td>
                        <div className="row-actions">
                          <Button size="small" icon="check" onClick={() => setAction({ kind: 'approve', r, o })}>Approuver</Button>
                          <Button size="small" variant="secondary" icon="x" onClick={() => setAction({ kind: 'refuse', r, o })}>Refuser</Button>
                          <Button size="small" variant="secondary" icon="arrow" onClick={() => setAction({ kind: 'move', r, o })}>Déplacer</Button>
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

      {action?.kind === 'approve' && (
        <ConfirmDialog title="Approuver la réservation" object={`${action.r.reference} · ${action.r.subject}`}
          consequence={`Les ${action.r.occurrences.filter((o) => o.status === 'pending').length} occurrence(s) en attente seront confirmées et l’organisateur notifié. Une confirmation concurrente sur le même créneau est refusée par le serveur.`}
          confirmLabel="Approuver" onClose={() => setAction(null)}
          onConfirm={async () => { await api.post(`/reservations/${action.r.id}/approve`); toast.success('Réservation approuvée'); refresh() }} />
      )}
      {action?.kind === 'refuse' && (
        <ConfirmDialog title="Refuser la réservation" object={`${action.r.reference} · ${action.r.subject}`} danger
          consequence="Les occurrences en attente sont refusées, le créneau est libéré et le motif est communiqué à l’organisateur."
          confirmLabel="Refuser la demande" reasonLabel="Motif du refus" reasonRequired onClose={() => setAction(null)}
          onConfirm={async (reason) => { await api.post(`/reservations/${action.r.id}/refuse`, { reason }); toast.success('Demande refusée'); refresh() }} />
      )}
      {action?.kind === 'move' && (
        <OccurrenceEditor reservation={action.r} occurrence={action.o} mode="move" onClose={() => setAction(null)}
          onDone={() => { toast.success('Réservation déplacée', 'L’organisateur est notifié.'); refresh() }} />
      )}
    </>
  )
}
