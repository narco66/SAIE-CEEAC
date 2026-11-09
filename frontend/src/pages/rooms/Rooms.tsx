import { useMemo, useState } from 'react'
import { useNavigate, useSearchParams } from 'react-router'
import { keepPreviousData, useQuery } from '@tanstack/react-query'
import { api, type Paginated } from '../../lib/api'
import { useAuth } from '../../lib/auth'
import { useData } from '../../lib/hooks'
import { addDays, fmtTime, localParts, OCC_STATUS, toIsoFromLocal, todayLocal } from '../../lib/format'
import NewRoomDialog from './NewRoomDialog'
import { Button, EmptyState, ErrorState, Field, Icon, Loading, Notice, PageHeading, Status, StatusOf, Tabs, useDebounced } from '../../components/ui'
import { availabilityTone, BUSY_LABEL, TZ_LABEL, useOccurrenceIndex, useReferentials, type Busy, type CalendarRow, type Reservation, type Room } from './shared'

type Tab = 'catalogue' | 'calendar' | 'agenda' | 'approvals'

/** V30 Catalogue des salles : la disponibilité affichée est datée (maintenant ou sur le créneau choisi). */
export default function Rooms() {
  const { can, me, hasRole } = useAuth()
  const navigate = useNavigate()
  const [params, setParams] = useSearchParams()
  const tab = (params.get('vue') === 'agenda' ? 'agenda' : 'catalogue') as Tab
  const approvals = useQuery({
    queryKey: ['reservations', 'to_validate', 'count'],
    queryFn: ({ signal }) => api.get<Paginated<Reservation>>('/reservations', { scope: 'to_validate', per_page: 1 }, signal),
    enabled: can('reservations.approve'),
  })

  const items: { value: Tab; label: string; count?: number | null }[] = [
    { value: 'catalogue', label: 'Catalogue' },
    { value: 'calendar', label: 'Calendrier' },
    { value: 'agenda', label: 'Agenda & événements' },
  ]
  if (can('reservations.approve')) items.push({ value: 'approvals', label: 'À valider', count: approvals.data?.meta.total ?? null })

  const [creatingRoom, setCreatingRoom] = useState(false)
  const onTab = (v: Tab) => {
    if (v === 'calendar') return navigate('/salles/calendrier')
    if (v === 'approvals') return navigate('/reservations/a-valider')
    const next = new URLSearchParams(params)
    if (v === 'agenda') next.set('vue', 'agenda')
    else next.delete('vue')
    setParams(next, { replace: true })
  }

  return (
    <>
      <PageHeading
        title="Salles et réunions"
        description="Catalogue des salles, disponibilités datées et réunions du jour."
        demo={me.demo_mode}
        actions={<>
          {can('rooms.manage') && hasRole('admin_fonc') && <Button variant="secondary" icon="plus" onClick={() => setCreatingRoom(true)}>Nouvelle salle</Button>}
          {can('reservations.create') && <Button icon="plus" onClick={() => navigate('/reservations/nouvelle')}>Nouvelle réservation</Button>}
        </>}
      />
      {creatingRoom && <NewRoomDialog onClose={() => setCreatingRoom(false)} />}
      <Tabs value={tab} onChange={onTab} items={items} />
      {tab === 'catalogue' ? <Catalogue /> : <MeetingAgendas />}
    </>
  )
}

function Catalogue() {
  const { can } = useAuth()
  const navigate = useNavigate()
  const refs = useReferentials()
  const [q, setQ] = useState('')
  const [capacity, setCapacity] = useState('')
  const [equipment, setEquipment] = useState('')
  const [floor, setFloor] = useState('')
  const [date, setDate] = useState('')
  const [start, setStart] = useState('')
  const [end, setEnd] = useState('')
  const [mode, setMode] = useState<'cards' | 'list'>('cards')
  const search = useDebounced(q)
  const slotValid = !!date && !!start && !!end && end > start
  const slotInvalid = !!(date || start || end) && !slotValid

  const filters = {
    q: search || undefined, capacity: capacity || undefined, equipment: equipment || undefined, floor_id: floor || undefined,
    from: slotValid ? toIsoFromLocal(date, start) : undefined, to: slotValid ? toIsoFromLocal(date, end) : undefined, per_page: 50,
  }
  const rooms = useQuery({
    queryKey: ['rooms', filters],
    queryFn: ({ signal }) => api.get<Paginated<Room>>('/rooms', filters, signal),
    placeholderData: keepPreviousData,
  })

  const reserveUrl = (room: Room) => {
    const p = new URLSearchParams({ room_id: room.id })
    if (slotValid) { p.set('date', date); p.set('start', start); p.set('end', end) }
    if (capacity) p.set('participants', capacity)
    return `/reservations/nouvelle?${p.toString()}`
  }
  const list = rooms.data?.data ?? []
  const canReserve = can('reservations.create')
  const blocked = (r: Room) => r.availability?.state === 'unavailable' || (slotValid && r.availability?.state !== 'free')

  return (
    <section className="panel table-panel">
      <div className="rooms-filters" role="search" aria-label="Filtrer les salles">
        <Field label="Recherche"><input value={q} onChange={(e) => setQ(e.target.value)} placeholder="Nom, code ou équipement…" /></Field>
        <Field label="Capacité minimale"><input type="number" min={1} value={capacity} onChange={(e) => setCapacity(e.target.value)} style={{ width: 110 }} /></Field>
        <Field label="Équipement">
          <select value={equipment} onChange={(e) => setEquipment(e.target.value)}>
            <option value="">Tous</option>
            {(refs.data?.equipment ?? []).filter((x) => x.is_active !== false).map((x) => <option key={x.id} value={x.code}>{x.name}</option>)}
          </select>
        </Field>
        <Field label="Niveau">
          <select value={floor} onChange={(e) => setFloor(e.target.value)}>
            <option value="">Tous</option>
            {(refs.data?.floors ?? []).map((f) => <option key={f.id} value={f.id}>{f.label}</option>)}
          </select>
        </Field>
        <Field label="Date du créneau"><input type="date" value={date} min={todayLocal()} onChange={(e) => setDate(e.target.value)} /></Field>
        <Field label="Début"><input type="time" step={900} value={start} onChange={(e) => setStart(e.target.value)} /></Field>
        <Field label="Fin"><input type="time" step={900} value={end} onChange={(e) => setEnd(e.target.value)} /></Field>
        {(date || start || end) && <Button variant="ghost" size="small" icon="x" onClick={() => { setDate(''); setStart(''); setEnd('') }}>Effacer le créneau</Button>}
        <div className="toolbar-spacer" />
        <div className="view-switch" role="group" aria-label="Présentation">
          <button type="button" aria-pressed={mode === 'cards'} onClick={() => setMode('cards')}><Icon name="layout" size={14} />Cartes</button>
          <button type="button" aria-pressed={mode === 'list'} onClick={() => setMode('list')}><Icon name="list" size={14} />Liste</button>
        </div>
      </div>
      <div style={{ padding: '10px 16px 0' }}>
        {slotInvalid ? (
          <Notice tone="warning">Complétez la date, l’heure de début et une heure de fin postérieure pour afficher la disponibilité sur un créneau.</Notice>
        ) : (
          <p className="muted small">
            {slotValid ? `Disponibilité calculée pour le ${date.split('-').reverse().join('/')} de ${start} à ${end}, temps de préparation inclus.` : 'Disponibilité affichée à l’instant présent.'} {TZ_LABEL}.
          </p>
        )}
      </div>
      {rooms.isLoading ? <Loading /> : rooms.isError ? <ErrorState error={rooms.error} onRetry={() => rooms.refetch()} /> : list.length === 0 ? (
        <EmptyState title="Aucune salle ne correspond" text="Élargissez les critères de capacité, d’équipement ou de niveau." />
      ) : mode === 'cards' ? (
        <div className="room-grid" aria-busy={rooms.isFetching}>
          {list.map((room, i) => (
            <article className="room-card" key={room.id}>
              <div className={`room-visual rv${i % 4}`}>
                {room.photos?.[0] && /^(https?:|\/)/.test(room.photos[0]) && <img src={room.photos[0]} alt="" />}
                <Icon name="door" size={30} />
                <Status tone={availabilityTone(room.availability?.state)}>{room.availability?.label ?? 'Disponibilité inconnue'}</Status>
              </div>
              <div>
                <small>{room.floor?.label ?? 'Niveau non renseigné'} · {room.capacity} personnes{room.approval_mode === 'manual' ? ' · sur validation' : ''}</small>
                <h3>{room.name}</h3>
                <div className="chips" aria-label="Équipements">
                  {room.equipment.length ? room.equipment.map((x) => <span key={x.id}>{x.name}{x.quantity && x.quantity > 1 ? ` ×${x.quantity}` : ''}</span>) : <span>Aucun équipement déclaré</span>}
                </div>
                <div className="room-card-actions">
                  {canReserve && (
                    <button type="button" onClick={() => navigate(reserveUrl(room))} disabled={blocked(room)} aria-label={`Réserver ${room.name}`}>
                      {room.availability?.state === 'unavailable' ? 'Indisponible' : blocked(room) ? 'Occupée sur ce créneau' : 'Réserver cette salle'}
                      <Icon name="arrow" size={15} />
                    </button>
                  )}
                  <button type="button" className="secondary-link" onClick={() => navigate(`/salles/${room.id}`)} aria-label={`Voir la fiche de ${room.name}`}>
                    Voir <Icon name="eye" size={14} />
                  </button>
                </div>
              </div>
            </article>
          ))}
        </div>
      ) : (
        <div className="table-scroll">
          <table>
            <thead><tr><th>Salle</th><th>Niveau</th><th>Capacité</th><th>Équipements</th><th>Validation</th><th>Disponibilité</th><th><span className="sr-only">Actions</span></th></tr></thead>
            <tbody>
              {list.map((room) => (
                <tr key={room.id}>
                  <td><strong>{room.name}</strong><small className="muted">{room.code}</small></td>
                  <td>{room.floor?.label ?? '—'}</td>
                  <td>{room.capacity}</td>
                  <td>{room.equipment.map((x) => x.name).join(', ') || '—'}</td>
                  <td>{room.approval_mode === 'manual' ? 'Gestionnaire' : 'Automatique'}</td>
                  <td><Status tone={availabilityTone(room.availability?.state)}>{room.availability?.label ?? '—'}</Status></td>
                  <td>
                    <div className="row-actions">
                      <Button size="small" variant="secondary" icon="eye" onClick={() => navigate(`/salles/${room.id}`)}>Voir</Button>
                      {canReserve && <Button size="small" onClick={() => navigate(reserveUrl(room))} disabled={blocked(room)}>Réserver</Button>}
                    </div>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </section>
  )
}

/** Agenda & événements : réunions du jour (confidentielles « Réservée ») et ordre du jour d'une réunion autorisée. */
function MeetingAgendas() {
  const navigate = useNavigate()
  const today = todayLocal()
  const range = { from: toIsoFromLocal(today, '00:00'), to: toIsoFromLocal(addDays(today, 1), '00:00') }
  const calendar = useData<CalendarRow[]>(['rooms-calendar', range], '/rooms-calendar', range)
  const index = useOccurrenceIndex()
  const [selected, setSelected] = useState<string | null>(null)

  const events = useMemo(() => {
    const out: (Busy & { room: string; floor: string })[] = []
    for (const row of calendar.data ?? []) for (const b of row.busy) if (b.type !== 'blocked') out.push({ ...b, room: row.room.name, floor: row.room.floor })
    return out.sort((a, b) => a.starts_at.localeCompare(b.starts_at))
  }, [calendar.data])
  const reservationOf = (b: Busy) => b.reservation_id ?? (b.occurrence_id ? index.data?.get(b.occurrence_id) : undefined) ?? null
  const firstOpenable = events.map(reservationOf).find(Boolean) ?? null
  const current = selected ?? firstOpenable

  return (
    <div className="two-columns meeting-agenda-layout">
      <section className="panel">
        <div className="panel-title">
          <div><h2>Réunions du jour</h2><p>Objets autorisés selon votre périmètre · {TZ_LABEL}</p></div>
          <Button variant="secondary" icon="calendar" onClick={() => navigate('/salles/calendrier')}>Calendrier</Button>
        </div>
        {calendar.isLoading ? <Loading /> : calendar.isError ? <ErrorState error={calendar.error} onRetry={() => calendar.refetch()} /> : events.length === 0 ? (
          <EmptyState title="Aucune réunion aujourd’hui" text="Aucune salle n’est réservée pour la journée." />
        ) : (
          <div className="event-list">
            {events.map((e, i) => {
              const rid = reservationOf(e)
              return (
                <button key={`${e.occurrence_id ?? i}-${e.starts_at}`} onClick={() => rid && setSelected(rid)} disabled={!rid} aria-pressed={!!rid && current === rid}
                  title={rid ? 'Afficher l’ordre du jour' : 'Détail non accessible à votre profil'}>
                  <time>{fmtTime(e.starts_at)}</time>
                  <span><strong>{e.label}</strong><small>{e.room} · {e.floor} · {fmtTime(e.starts_at)}–{fmtTime(e.ends_at)}</small></span>
                  <Status tone={e.confidential ? 'warning' : e.type === 'pending' ? 'warning' : 'success'}>{e.confidential ? 'Confidentielle' : BUSY_LABEL[e.type]}</Status>
                  {rid ? <Icon name="chevron" size={15} /> : <span aria-hidden="true" />}
                </button>
              )
            })}
          </div>
        )}
      </section>
      <AgendaDetail id={current} />
    </div>
  )
}

function AgendaDetail({ id }: { id: string | null }) {
  const navigate = useNavigate()
  const query = useData<Reservation>(['reservation', id], `/reservations/${id}`, undefined, !!id)
  if (!id) {
    return (
      <section className="panel agenda-detail">
        <div className="panel-title"><div><h2>Ordre du jour</h2><p>Sélectionnez une réunion dont vous êtes organisateur ou gestionnaire.</p></div></div>
        <EmptyState title="Aucune réunion sélectionnée" text="L’ordre du jour n’est visible que pour les réunions autorisées." />
      </section>
    )
  }
  if (query.isLoading) return <section className="panel agenda-detail"><Loading /></section>
  if (query.isError || !query.data) return <section className="panel agenda-detail"><ErrorState error={query.error} onRetry={() => query.refetch()} /></section>
  const r = query.data
  const today = todayLocal()
  const occ = r.occurrences.find((o) => localParts(o.starts_at).date >= today) ?? r.occurrences[0]
  const docs = r.documents ?? []
  return (
    <section className="panel agenda-detail">
      <div className="panel-title">
        <div><h2>Ordre du jour</h2><p>{r.subject} · {r.reference}</p></div>
        {occ && <StatusOf map={OCC_STATUS} value={occ.status} />}
      </div>
      {r.agenda.length === 0 ? (
        <p className="muted small" style={{ padding: '0 18px 14px' }}>Aucun point inscrit à l’ordre du jour.</p>
      ) : (
        <ol>
          {r.agenda.map((a, i) => (
            <li key={a.id}>
              <span>{String(i + 1).padStart(2, '0')}</span>
              <div><strong>{a.title}</strong><small>{[a.time_label, a.presenter].filter(Boolean).join(' · ') || '—'}</small></div>
            </li>
          ))}
        </ol>
      )}
      <button className="agenda-documents" style={{ width: '100%', border: 0, background: 'none', textAlign: 'left', cursor: 'pointer' }} onClick={() => navigate(`/reservations/${r.id}`)}>
        <Icon name="folder" />
        <span><strong>{docs.length} document(s) associé(s)</strong><small>Consultables uniquement par les participants habilités · ouvrir la réservation</small></span>
        <Icon name="chevron" />
      </button>
    </section>
  )
}
