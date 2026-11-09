import { useState } from 'react'
import { useNavigate } from 'react-router'
import { keepPreviousData, useQuery } from '@tanstack/react-query'
import { api } from '../../lib/api'
import { fmtDateTime, fmtRelative } from '../../lib/format'
import { Button, EmptyState, ErrorState, Icon, Loading, PageHeading, Status, Toolbar } from '../../components/ui'
import './account.css'

interface Task {
  id: string; type: 'content_validation' | 'content_unassigned' | 'reservation_approval' | 'content_return' | 'service'; object: string; reference?: string | null; lang: string | null
  action: string; emitter: string | null; due_at: string | null; escalated_at?: string | null; state: 'open' | 'overdue' | 'escalated' | 'to_correct' | 'unassigned'
  as_backup?: boolean; link: string; reason?: string | null
}
interface TasksResponse { data: Task[]; meta: { counts: { open: number; overdue: number; escalated: number; done_this_month: number } } }
type Filter = 'open' | 'overdue' | 'escalated'

const STATE: Record<Task['state'], [string, string]> = { open: ['À traiter', 'warning'], overdue: ['En retard', 'danger'], escalated: ['Escaladée', 'danger'], to_correct: ['À corriger', 'blue'], unassigned: ['Sans validateur', 'danger'] }
const TYPE: Record<Task['type'], [string, 'file' | 'door' | 'edit' | 'settings']> = {
  content_validation: ['Contenu', 'file'], content_unassigned: ['Validation à affecter', 'settings'], reservation_approval: ['Réservation', 'door'], content_return: ['Correction', 'edit'], service: ['Prestation', 'settings'],
}

/** V05 Tâches et validations : y compris celles reçues en tant que suppléant (HAB-04). */
export default function Tasks() {
  const navigate = useNavigate()
  const [filter, setFilter] = useState<Filter>('open')
  const [term, setTerm] = useState('')
  const query = useQuery({
    queryKey: ['tasks', 'list', filter],
    queryFn: ({ signal }) => api.get<TasksResponse>('/tasks', { filter }, signal),
    placeholderData: keepPreviousData,
    refetchInterval: 120_000,
  })
  const counts = query.data?.meta.counts
  const t = term.trim().toLowerCase()
  const items = (query.data?.data ?? []).filter((x) => !t || `${x.object} ${x.action} ${x.emitter ?? ''} ${x.reference ?? ''}`.toLowerCase().includes(t))
  const cards: { value: Filter | null; label: string; count: number | undefined }[] = [
    { value: 'open', label: 'À traiter', count: counts?.open },
    { value: 'overdue', label: 'En retard', count: counts?.overdue },
    { value: 'escalated', label: 'Escaladées', count: counts?.escalated },
    { value: null, label: 'Traitées ce mois', count: counts?.done_this_month },
  ]

  return (
    <>
      <PageHeading title="Tâches & validations" description="Actions qui vous sont attribuées, directement ou en tant que suppléant." />
      <div className="task-filter-cards" role="group" aria-label="Filtrer les tâches">
        {cards.map((c) => c.value ? (
          <button key={c.label} className={filter === c.value ? 'active' : ''} aria-pressed={filter === c.value} onClick={() => setFilter(c.value as Filter)}>
            <strong>{c.count ?? '—'}</strong><span>{c.label}</span>
          </button>
        ) : (
          <button key={c.label} disabled aria-disabled="true" title="Décompte des décisions prises ce mois-ci (non filtrable)" style={{ cursor: 'default' }}>
            <strong>{c.count ?? '—'}</strong><span>{c.label}</span>
          </button>
        ))}
      </div>
      <section className="panel table-panel">
        <Toolbar value={term} onChange={setTerm} placeholder="Rechercher une tâche…" />
        {query.isLoading ? <Loading /> : query.isError ? <ErrorState error={query.error} onRetry={() => query.refetch()} /> : items.length === 0 ? (
          <EmptyState title={t ? 'Aucun résultat' : 'Aucune tâche'} text={t ? 'Aucune tâche ne correspond à la recherche.' : filter === 'open' ? 'Vous n’avez aucune action en attente.' : 'Aucune tâche dans cette catégorie.'} />
        ) : (
          <div className="table-scroll">
            <table>
              <thead><tr><th scope="col">Objet</th><th scope="col">Action attendue</th><th scope="col">Émetteur</th><th scope="col">Échéance</th><th scope="col">État</th><th scope="col"><span className="sr-only">Ouvrir</span></th></tr></thead>
              <tbody>
                {items.map((x) => {
                  const late = x.state === 'overdue' || (x.due_at !== null && new Date(x.due_at) < new Date())
                  const [typeLabel, icon] = TYPE[x.type] ?? ['Tâche', 'file']
                  return (
                    <tr key={x.id}>
                      <td>
                        <div className="title-cell">
                          <span className="doc-thumb"><Icon name={icon} /></span>
                          <span><strong>{x.object}</strong><small>{typeLabel}{x.lang ? ` · ${x.lang.toUpperCase()}` : ''}{x.reference ? ` · ${x.reference}` : ''}</small></span>
                        </div>
                      </td>
                      <td>
                        {x.action}
                        {x.as_backup && <small><Icon name="users" size={11} /> en tant que suppléant</small>}
                        {x.reason && <small>Motif du retour : {x.reason}</small>}
                      </td>
                      <td>{x.emitter ?? '—'}</td>
                      <td>
                        {x.due_at ? (
                          <span className={late ? 'red-text' : ''}>{fmtDateTime(x.due_at)}<small className={late ? 'red-text' : ''}>{late ? `En retard · ${fmtRelative(x.due_at)}` : fmtRelative(x.due_at)}</small></span>
                        ) : '—'}
                        {x.escalated_at && <small>Escaladée le {fmtDateTime(x.escalated_at)}</small>}
                      </td>
                      <td><Status tone={STATE[x.state]?.[1] ?? 'neutral'}>{STATE[x.state]?.[0] ?? x.state}</Status></td>
                      <td><Button variant="secondary" size="small" onClick={() => navigate(x.link)} ariaLabel={`Ouvrir : ${x.object}`}>Ouvrir</Button></td>
                    </tr>
                  )
                })}
              </tbody>
            </table>
          </div>
        )}
      </section>
    </>
  )
}
