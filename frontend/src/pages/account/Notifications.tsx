import { useState } from 'react'
import { useNavigate, useSearchParams } from 'react-router'
import { useQueryClient } from '@tanstack/react-query'
import { api, ApiError } from '../../lib/api'
import { useList } from '../../lib/hooks'
import { fmtDateTime, fmtRelative } from '../../lib/format'
import { Button, EmptyState, ErrorState, Loading, Notice, PageHeading, Pagination, Status, Tabs, useToast } from '../../components/ui'
import './account.css'

interface NotificationRow { id: string; event: string; title: string; body: string | null; link: string | null; object_type: string | null; read_at: string | null; created_at: string | null }

const EVENT_LABEL: Record<string, string> = {
  'content.submitted': 'Soumission', 'content.approved': 'Approbation', 'content.returned': 'Retour pour correction', 'content.expiring': 'Expiration proche',
  'content.reassigned': 'Réassignation', 'approval.reminder': 'Relance', 'approval.no_backup': 'Sans suppléant', 'incident.opened': 'Incident', 'approval.unassigned': 'Sans validateur', 'reservation.pending': 'Réservation en attente',
  'reservation.confirmed': 'Réservation confirmée', 'reservation.refused': 'Réservation refusée', 'reservation.cancelled': 'Réservation annulée',
  'reservation.moved': 'Réservation déplacée', 'reservation.expired': 'Demande expirée', 'reservation.no_show': 'Réunion non honorée', 'service.requested': 'Prestation demandée',
  'incident.assigned': 'Incident affecté', 'alert.pending': 'Alerte à confirmer', 'document.publication_requested': 'Publication de document demandée', 'reservation.reminder': 'Rappel de réunion', 'reservation.auto_released': 'Salle libérée automatiquement', 'approval.escalated': 'Validation escaladée au suppléant',
}
const internal = (link: string | null | undefined): link is string => !!link && link.startsWith('/') && !link.startsWith('//')
type Filter = 'all' | 'unread'

/** V06 Notifications : l'ouverture revérifie les droits ; marquer lu ne vaut pas validation. */
export default function Notifications() {
  const navigate = useNavigate()
  const client = useQueryClient()
  const toast = useToast()
  const [params, setParams] = useSearchParams()
  const [filter, setFilter] = useState<Filter>('all')
  const [busy, setBusy] = useState<string | null>(null)
  const [inaccessible, setInaccessible] = useState<string | null>(null)
  const list = useList<NotificationRow>('notifications', '/notifications', { filter: filter === 'unread' ? 'unread' : undefined })
  const unread = (list.meta as { unread?: number } | undefined)?.unread ?? 0

  const refresh = () => client.invalidateQueries({ queryKey: ['notifications'] })
  const markRead = async (n: NotificationRow) => {
    setBusy(n.id)
    try {
      await api.post(`/notifications/${n.id}/read`, undefined, false)
      await refresh()
    } catch (e) {
      toast.error(e)
    } finally {
      setBusy(null)
    }
  }
  const readAll = async () => {
    setBusy('all')
    try {
      await api.post('/notifications/read-all', undefined, false)
      await refresh()
      toast.success('Toutes les notifications sont marquées lues')
    } catch (e) {
      toast.error(e)
    } finally {
      setBusy(null)
    }
  }
  const open = async (n: NotificationRow) => {
    setBusy(n.id)
    setInaccessible(null)
    try {
      const r = await api.get<{ data: { link: string | null } }>(`/notifications/${n.id}/open`)
      await refresh()
      if (internal(r.data.link)) navigate(r.data.link)
      else toast.info('Notification sans lien', 'Cette notification ne renvoie vers aucune page.')
    } catch (e) {
      if (e instanceof ApiError && (e.status === 403 || e.status === 404)) {
        setInaccessible(n.id)
        await refresh()
      } else toast.error(e)
    } finally {
      setBusy(null)
    }
  }

  return (
    <>
      <PageHeading
        title="Notifications"
        description={`${unread} notification(s) non lue(s)`}
        actions={<Button variant="secondary" icon="check" onClick={readAll} busy={busy === 'all'} disabled={unread === 0}>Tout marquer lu</Button>}
      />
      {params.get('inaccessible') === '1' && (
        <div style={{ marginBottom: 12 }}>
          <Notice tone="warning">
            La cible n’est plus accessible avec vos droits actuels.{' '}
            <button className="link" onClick={() => { params.delete('inaccessible'); setParams(params, { replace: true }) }}>Masquer</button>
          </Notice>
        </div>
      )}
      <div style={{ marginBottom: 12 }}><Notice tone="info">Marquer une notification comme lue ne vaut pas validation de l’objet concerné : ouvrez-la pour agir.</Notice></div>
      <section className="panel table-panel">
        <div style={{ padding: '6px 15px 0' }}>
          <Tabs<Filter> value={filter} onChange={(v) => { setFilter(v); list.setPage(1) }} items={[{ value: 'all', label: 'Toutes' }, { value: 'unread', label: 'Non lues', count: unread }]} />
        </div>
        {list.isLoading ? <Loading /> : list.isError ? <ErrorState error={list.error} onRetry={() => list.refetch()} /> : list.items.length === 0 ? (
          <EmptyState title={filter === 'unread' ? 'Aucune notification non lue' : 'Aucune notification'} />
        ) : (
          <>
            <ul style={{ listStyle: 'none', margin: 0, padding: 0 }}>
              {list.items.map((n) => (
                <li key={n.id} className={`notif-item ${n.read_at ? '' : 'unread'}`}>
                  <span className="notif-dot" aria-hidden="true" />
                  <div>
                    <div style={{ display: 'flex', gap: 8, alignItems: 'center', flexWrap: 'wrap' }}>
                      <strong>{n.title}</strong>
                      {n.read_at ? <Status tone="neutral">Lue</Status> : <Status tone="blue">Non lue</Status>}
                      <small>{EVENT_LABEL[n.event] ?? n.event}</small>
                    </div>
                    {n.body && <p>{n.body.length > 220 ? `${n.body.slice(0, 220)}…` : n.body}</p>}
                    <small>{fmtDateTime(n.created_at)} · {fmtRelative(n.created_at)}</small>
                    {inaccessible === n.id && <div style={{ marginTop: 8 }}><Notice tone="warning">La cible n’est plus accessible avec vos droits actuels.</Notice></div>}
                  </div>
                  <div className="row-actions">
                    {!n.read_at && <Button size="small" variant="ghost" onClick={() => markRead(n)} busy={busy === n.id} ariaLabel={`Marquer comme lue : ${n.title}`}>Marquer lu</Button>}
                    {n.link && <Button size="small" variant="secondary" onClick={() => open(n)} busy={busy === n.id} ariaLabel={`Ouvrir : ${n.title}`}>Ouvrir</Button>}
                  </div>
                </li>
              ))}
            </ul>
            <Pagination meta={list.meta} onPage={list.setPage} />
          </>
        )}
      </section>
    </>
  )
}
