import { useMemo, useState } from 'react'
import { useNavigate, useSearchParams } from 'react-router'
import { keepPreviousData, useQuery } from '@tanstack/react-query'
import { api } from '../../lib/api'
import { useAuth } from '../../lib/auth'
import { addDays, DAYS, fmtDate, fmtLongDate, fmtTime, localParts, toIsoFromLocal, todayLocal } from '../../lib/format'
import { Button, EmptyState, ErrorState, Icon, Loading, Modal, PageHeading, Select, Status } from '../../components/ui'
import {
  BUSY_LABEL, CalendarLegend, DayTrack, fmtDayMonth, fmtMonth, freeGaps, fromMinutes, HourScale, mondayOf, TZ_LABEL,
  useExportPdf, useOccurrenceIndex, useReferentials, type Busy, type CalendarRow,
} from './shared'

type View = 'day' | 'week' | 'month' | 'list'
const VIEWS: { value: View; label: string }[] = [{ value: 'day', label: 'Vue jour' }, { value: 'week', label: 'Vue semaine' }, { value: 'month', label: 'Vue mois' }, { value: 'list', label: 'Vue liste' }]

/** V25 Calendrier des salles : libre, préparation, réservé, en attente et indisponible sont distingués par texte et motif. */
export default function RoomCalendar() {
  const { can, me } = useAuth()
  const navigate = useNavigate()
  const [params, setParams] = useSearchParams()
  const view = (VIEWS.some((v) => v.value === params.get('vue')) ? params.get('vue') : 'day') as View
  const anchor = params.get('date') && /^\d{4}-\d{2}-\d{2}$/.test(params.get('date')!) ? params.get('date')! : todayLocal()
  const [floor, setFloor] = useState('')
  const [capacity, setCapacity] = useState('')
  const [weekLength, setWeekLength] = useState<5 | 7>(5)
  const [exporting, setExporting] = useState(false)
  const refs = useReferentials()
  const index = useOccurrenceIndex()
  const today = todayLocal()

  const set = (patch: Record<string, string>) => {
    const next = new URLSearchParams(params)
    Object.entries(patch).forEach(([k, v]) => next.set(k, v))
    setParams(next, { replace: true })
  }

  const range = useMemo(() => {
    if (view === 'day') return { start: anchor, days: 1 }
    if (view === 'month') {
      const first = `${anchor.slice(0, 7)}-01`
      return { start: mondayOf(first), days: 42 }
    }
    return { start: mondayOf(anchor), days: view === 'list' ? 7 : weekLength }
  }, [view, anchor, weekLength])
  const days = Array.from({ length: range.days }, (_, i) => addDays(range.start, i))
  const from = toIsoFromLocal(range.start, '00:00')
  const to = toIsoFromLocal(addDays(range.start, range.days), '00:00')

  const query = useQuery<CalendarRow[]>({
    queryKey: ['rooms-calendar', from, to, floor, capacity],
    queryFn: ({ signal }) => api.get<{ data: CalendarRow[] }>('/rooms-calendar', { from, to, floor_id: floor || undefined, capacity: capacity || undefined }, signal).then((r) => r.data),
    placeholderData: keepPreviousData,
  })
  const rows = query.data ?? []

  const step = (dir: number) => {
    if (view === 'day') set({ date: addDays(anchor, dir) })
    else if (view === 'month') {
      const [y, m] = anchor.split('-').map(Number)
      const d = new Date(Date.UTC(y, m - 1 + dir, 1))
      set({ date: `${d.getUTCFullYear()}-${String(d.getUTCMonth() + 1).padStart(2, '0')}-01` })
    } else set({ date: addDays(anchor, dir * 7) })
  }
  const title = view === 'day' ? fmtLongDate(`${anchor}T12:00:00+01:00`)
    : view === 'month' ? fmtMonth(anchor)
      : `Semaine du ${fmtDate(`${days[0]}T12:00:00+01:00`)} au ${fmtDate(`${days[days.length - 1]}T12:00:00+01:00`)}`

  const reservationOf = (b: Busy) => b.reservation_id ?? (b.occurrence_id ? index.data?.get(b.occurrence_id) : undefined) ?? null
  const open = (b: Busy) => {
    const id = reservationOf(b)
    if (id) navigate(`/reservations/${id}`)
  }
  const canCreate = can('reservations.create')
  const book = (roomId: string, date: string, start?: string, end?: string) => {
    if (!canCreate) return
    const p = new URLSearchParams({ room_id: roomId, date })
    if (start) p.set('start', start)
    if (end) p.set('end', end)
    navigate(`/reservations/nouvelle?${p.toString()}`)
  }

  return (
    <>
      <PageHeading
        title="Calendrier des salles"
        description="Vue multisalle jour, semaine, mois ou liste. Les temps de préparation et de remise en état sont inclus."
        demo={me.demo_mode}
        actions={<>
          {can('exports.create') && <Button variant="secondary" icon="download" onClick={() => setExporting(true)}>Planning PDF02</Button>}
          {canCreate && <Button icon="plus" onClick={() => navigate('/reservations/nouvelle')}>Nouvelle réservation</Button>}
        </>}
      />
      <section className="panel room-calendar-panel">
        <div className="calendar-top">
          <div className="date-nav">
            <button type="button" onClick={() => step(-1)} aria-label="Période précédente"><Icon name="chevron" size={16} /></button>
            <h2 aria-live="polite">{title}</h2>
            <button type="button" onClick={() => step(1)} aria-label="Période suivante"><Icon name="chevron" size={16} /></button>
          </div>
          <div className="calendar-filters">
            <Button variant="secondary" onClick={() => set({ date: today })}>Aujourd’hui</Button>
            <Select label="Vue" value={view} onChange={(v) => set({ vue: v })} options={VIEWS} />
            {view === 'week' && <Select label="Jours affichés" value={String(weekLength)} onChange={(v) => setWeekLength(v === '7' ? 7 : 5)} options={[{ value: '5', label: '5 jours ouvrés' }, { value: '7', label: '7 jours' }]} />}
            <Select label="Niveau" value={floor} onChange={setFloor} allLabel="Tous les niveaux" options={(refs.data?.floors ?? []).map((f) => ({ value: f.id, label: f.label }))} />
            <label><span className="sr-only">Capacité minimale</span>
              <input className="input" type="number" min={1} placeholder="Capacité min." value={capacity} onChange={(e) => setCapacity(e.target.value)} style={{ width: 120, height: 34 }} aria-label="Capacité minimale" />
            </label>
          </div>
        </div>
        <CalendarLegend />
        {query.isLoading ? <Loading /> : query.isError ? <ErrorState error={query.error} onRetry={() => query.refetch()} /> : rows.length === 0 ? (
          <EmptyState title="Aucune salle pour ces filtres" text="Modifiez le niveau ou la capacité minimale." />
        ) : (
          <div className="cal-scroll" aria-busy={query.isFetching}>
            {view === 'day' && (
              <div className="day-grid">
                <div className="day-head" aria-hidden="true" />
                <HourScale />
                {rows.map((row) => (
                  <DayRow key={row.room.id} row={row} day={anchor} onOpen={open} onFree={canCreate && anchor >= today ? (s, e) => book(row.room.id, anchor, s, e) : undefined} />
                ))}
              </div>
            )}
            {view === 'week' && <WeekView rows={rows} days={days} today={today} onOpen={open} canOpen={(b) => !!reservationOf(b)} onBook={canCreate ? book : undefined} />}
            {view === 'month' && <MonthView rows={rows} days={days} month={anchor.slice(0, 7)} today={today} onOpen={open} canOpen={(b) => !!reservationOf(b)} onDay={(d) => set({ vue: 'day', date: d })} />}
            {view === 'list' && <ListView rows={rows} onOpen={open} canOpen={(b) => !!reservationOf(b)} />}
          </div>
        )}
        <p className="muted small" style={{ padding: '10px 17px' }}>{TZ_LABEL}. Intervalle [début, fin[ ; un créneau « Libre » tient compte des horaires d’ouverture et des tampons des réunions voisines.</p>
      </section>
      {exporting && <ExportDialog from={from} to={to} onClose={() => setExporting(false)} />}
    </>
  )
}

function DayRow({ row, day, onOpen, onFree }: { row: CalendarRow; day: string; onOpen: (b: Busy) => void; onFree?: (s: string, e: string) => void }) {
  return (
    <>
      <div className="day-label">{row.room.name}<small>{row.room.floor} · {row.room.capacity} pl. · {row.room.open_from}–{row.room.open_to}</small></div>
      <DayTrack busy={row.busy} day={day} openFrom={row.room.open_from} openTo={row.room.open_to} label={row.room.name} onOpen={onOpen} onFree={onFree} />
    </>
  )
}

const onDay = (b: Busy, day: string) => localParts(b.starts_at).date <= day && localParts(b.ends_at).date >= day

function WeekView({ rows, days, today, onOpen, canOpen, onBook }: {
  rows: CalendarRow[]; days: string[]; today: string; onOpen: (b: Busy) => void; canOpen: (b: Busy) => boolean
  onBook?: (roomId: string, date: string, start?: string, end?: string) => void
}) {
  const cols = { gridTemplateColumns: `135px repeat(${days.length}, minmax(0, 1fr))` }
  return (
    <div className="meeting-calendar" role="table" aria-label="Planning hebdomadaire des salles">
      <div className="meeting-calendar-head" style={cols} role="row">
        <span role="columnheader">Salles</span>
        {days.map((d) => <span key={d} role="columnheader" className={d === today ? 'today' : ''}>{fmtDayMonth(d)}{d === today ? ' · aujourd’hui' : ''}</span>)}
      </div>
      {rows.map((row) => (
        <div className="meeting-calendar-row" key={row.room.id} style={cols} role="row">
          <strong role="rowheader">{row.room.name}<span className="sr-only"> ({row.room.floor}, {row.room.capacity} places)</span></strong>
          {days.map((d) => {
            const items = row.busy.filter((b) => onDay(b, d))
            const gaps = onBook ? freeGaps(items, d, row.room.open_from, row.room.open_to) : []
            return (
              <div className="mc-cell" key={d} role="cell">
                {items.map((b, i) => {
                  const cls = b.type === 'blocked' ? 'unavailable' : b.type === 'pending' ? 'pending' : 'booked'
                  const prep = b.type !== 'blocked' && b.blocking_start && b.blocking_start < b.starts_at ? ` · prép. dès ${fmtTime(b.blocking_start)}` : ''
                  const content = <>{BUSY_LABEL[b.type]} · {b.label}<small>{fmtTime(b.starts_at)}–{fmtTime(b.ends_at)}{prep}</small></>
                  return canOpen(b) ? (
                    <button key={i} type="button" className={cls} onClick={() => onOpen(b)} title="Ouvrir le détail">{content}</button>
                  ) : <span key={i} className={cls}>{content}</span>
                })}
                {gaps.length > 0 && onBook && (
                  <button type="button" className="free" onClick={() => onBook(row.room.id, d, fromMinutes(gaps[0][0]), fromMinutes(gaps[0][1]))}
                    aria-label={`${row.room.name}, ${fmtDayMonth(d)} : libre, réserver à partir de ${fromMinutes(gaps[0][0])}`}>
                    Libre<small>{gaps.map(([s, e]) => `${fromMinutes(s)}–${fromMinutes(e)}`).slice(0, 2).join(' · ')}{gaps.length > 2 ? '…' : ''}</small>
                  </button>
                )}
                {gaps.length === 0 && items.length === 0 && <span className="booked" style={{ background: '#f5f7f8', color: '#8a96a3', borderColor: '#d5dbe1' }}>Fermée ou passée<small>Non réservable</small></span>}
              </div>
            )
          })}
        </div>
      ))}
    </div>
  )
}

function MonthView({ rows, days, month, today, onOpen, canOpen, onDay: openDay }: {
  rows: CalendarRow[]; days: string[]; month: string; today: string; onOpen: (b: Busy) => void; canOpen: (b: Busy) => boolean; onDay: (d: string) => void
}) {
  return (
    <div style={{ minWidth: 760, padding: '0 0 4px' }}>
      <div className="cal-month-head" aria-hidden="true">{[1, 2, 3, 4, 5, 6, 7].map((d) => <span key={d}>{DAYS[d]}</span>)}</div>
      <div className="cal-month">
        {days.map((d) => {
          const items = rows.flatMap((r) => r.busy.filter((b) => onDay(b, d)).map((b) => ({ ...b, room: r.room.name }))).sort((a, b) => a.starts_at.localeCompare(b.starts_at))
          return (
            <div key={d} className={`${d.slice(0, 7) !== month ? 'out' : ''} ${d === today ? 'today' : ''}`}>
              <button type="button" className="day-num" onClick={() => openDay(d)} aria-label={`Afficher le ${fmtDayMonth(d)} en vue jour (${items.length} créneau(x) occupé(s))`}>{Number(d.slice(8))}</button>
              {items.slice(0, 4).map((b, i) => {
                const text = <><b>{fmtTime(b.starts_at)}</b> {b.room} · {b.type === 'reserved' ? b.label : `${BUSY_LABEL[b.type]} · ${b.label}`}</>
                return canOpen(b)
                  ? <button key={i} type="button" className={`slot ${b.type}`} onClick={() => onOpen(b)} title={`${BUSY_LABEL[b.type]} · ${b.label}`}>{text}</button>
                  : <span key={i} className={`slot ${b.type}`} title={`${BUSY_LABEL[b.type]} · ${b.label}`}>{text}</span>
              })}
              {items.length > 4 && <span className="more">+ {items.length - 4} autre(s)</span>}
            </div>
          )
        })}
      </div>
    </div>
  )
}

function ListView({ rows, onOpen, canOpen }: { rows: CalendarRow[]; onOpen: (b: Busy) => void; canOpen: (b: Busy) => boolean }) {
  const items = rows.flatMap((r) => r.busy.map((b) => ({ ...b, room: r.room }))).sort((a, b) => a.starts_at.localeCompare(b.starts_at))
  if (items.length === 0) return <EmptyState title="Aucune occupation sur la semaine" text="Toutes les salles filtrées sont libres pendant leurs horaires d’ouverture." />
  return (
    <div className="table-scroll">
      <table>
        <thead><tr><th>Date</th><th>Horaire</th><th>Salle</th><th>État</th><th>Objet</th><th>Préparation et remise en état</th><th><span className="sr-only">Actions</span></th></tr></thead>
        <tbody>
          {items.map((b, i) => (
            <tr key={i}>
              <td>{fmtDate(b.starts_at)}</td>
              <td>{fmtTime(b.starts_at)}–{fmtTime(b.ends_at)}</td>
              <td>{b.room.name}<small className="muted">{b.room.floor}</small></td>
              <td><Status tone={b.type === 'blocked' ? 'danger' : b.type === 'pending' ? 'warning' : 'blue'}>{BUSY_LABEL[b.type]}</Status></td>
              <td>{b.label}</td>
              <td>{b.type === 'blocked' ? '—' : `${fmtTime(b.blocking_start)}–${fmtTime(b.blocking_end)}`}</td>
              <td>{canOpen(b) && <Button size="small" variant="secondary" onClick={() => onOpen(b)}>Ouvrir</Button>}</td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  )
}

function ExportDialog({ from, to, onClose }: { from: string; to: string; onClose: () => void }) {
  const { can } = useAuth()
  const internalAllowed = can('reservations.approve')
  const [variant, setVariant] = useState<'public' | 'internal'>('public')
  const { run, busy } = useExportPdf()
  return (
    <Modal title="Exporter le planning des salles" kicker="PDF02" onClose={onClose} footer={<>
      <Button variant="secondary" onClick={onClose}>Fermer</Button>
      <Button icon="download" busy={!!busy} onClick={async () => { await run('room_planning', { from, to, variant }, 'Planning PDF02'); onClose() }}>Générer le PDF</Button>
    </>}>
      <p className="small" style={{ marginBottom: 12 }}>Période affichée : du {fmtDate(from)} au {fmtDate(new Date(new Date(to).getTime() - 1).toISOString())} ({TZ_LABEL}).</p>
      <div className="target-list">
        <label>
          <input type="radio" name="variant" checked={variant === 'public'} onChange={() => setVariant('public')} />
          <span><b>Version publique</b>Sans titres sensibles ni organisateurs ; réunions confidentielles libellées « Réservée ».</span>
        </label>
        {internalAllowed && (
          <label>
            <input type="radio" name="variant" checked={variant === 'internal'} onChange={() => setVariant('internal')} />
            <span><b>Version interne</b>Objets et organisateurs selon vos droits de gestionnaire.</span>
          </label>
        )}
      </div>
    </Modal>
  )
}
