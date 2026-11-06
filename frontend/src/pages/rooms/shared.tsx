import { useState, type ReactNode } from 'react'
import { useQuery } from '@tanstack/react-query'
import { api, ApiError, fileUrl, type Paginated } from '../../lib/api'
import { useAuth } from '../../lib/auth'
import { addDays, fmtDate, fmtTime, localParts, TZ } from '../../lib/format'
import { ConfirmDialog, Icon, useToast } from '../../components/ui'
import './rooms.css'

/* ===== Types du contrat API (Present::room, Present::reservation, Present::occurrence) ===== */
export interface UserRef { id: string; name: string; initials?: string }
export interface Equipment { id: string; code: string; name: string; quantity?: number }
export interface Room {
  id: string; version: number; code: string; name: string; capacity: number; location_text: string | null; photos: string[]
  floor: { id: string; label: string; level: number } | null; manager: UserRef | null
  approval_mode: 'auto' | 'manual'; min_minutes: number; max_minutes: number; lead_days: number
  prep_minutes: number; cleanup_minutes: number; open_from: string; open_to: string; pending_hold_hours: number
  status: string; auto_release_enabled: boolean; equipment: Equipment[]; is_demo?: boolean
  availability?: { state: 'free' | 'busy' | 'unavailable' | string; label: string }
  can_manage?: boolean
  blocks?: { id: string; starts_at: string; ends_at: string; kind: 'technical' | 'institutional'; reason: string }[]
  screens?: { id: string; code: string; name: string }[]
}
export interface Busy {
  type: 'reserved' | 'pending' | 'blocked'; occurrence_id: string | null; reservation_id?: string | null
  starts_at: string; ends_at: string; blocking_start: string; blocking_end: string; label: string; confidential: boolean
}
export interface CalendarRow { room: { id: string; name: string; capacity: number; floor: string; open_from: string; open_to: string }; busy: Busy[] }
export interface ServiceItem { id: string; type: string | null; label?: string; headcount?: number | null; serve_time?: string | null; status: string; details: string | null }
export interface Occurrence {
  id: string; sequence: number; status: string; version: number; starts_at: string; ends_at: string
  blocking_start: string | null; blocking_end: string | null; hold_until: string | null
  decision_reason: string | null; cancel_reason: string | null; no_show_reason: string | null; no_show_at: string | null
  checked_in_at?: string | null
  room: { id: string; code?: string; name?: string; floor?: string | null; capacity?: number }
  services: ServiceItem[]
}
export interface Recurrence { freq: 'none' | 'daily' | 'weekly' | 'monthly'; interval?: number; until?: string | null; count?: number | null; byweekday?: number[] }
export interface Reservation {
  id: string; reference: string; confidential: boolean; version: number; subject: string; description: string | null
  organizer: UserRef | null; structure: string | null; participant_count: number
  internal_participants: string[] | null; external_participants: { name: string; organization?: string | null }[] | null
  recurrence: Recurrence | null; is_priority: boolean; created_at: string; occurrences: Occurrence[]
  agenda: { id: string; position: number; title: string; time_label: string | null; presenter: string | null }[]
  can_manage: boolean
  approval?: { due_at: string | null; escalated_at: string | null } | null
  documents?: { id: string; reference: string; title: string; classification: string }[]
  resources?: { occurrence_id: string; name: string }[]
  welcome?: { id: string; reference: string; title: string; status: string }[]
  history?: { occurred_at: string; actor_label: string | null; action: string; label?: string; reason: string | null }[]
  permissions?: { modify: boolean; approve: boolean; no_show: boolean; priority: boolean; welcome: boolean }
  /** Confirmation de présence (SAL-10) : codes communiqués à l'organisateur seul. */
  presence?: { enabled: boolean; codes: Record<string, string | null>; checked_in: Record<string, string | null> }
  external_notice?: string
}
export interface Referentials {
  floors: { id: string; code: string; label: string; level: number; is_active?: boolean }[]
  structures: { id: string; code: string; name: string; floor_id?: string | null; is_active?: boolean }[]
  equipment: { id: string; code: string; name: string; is_active?: boolean }[]
  service_types: { id: string; code: string; name: string; is_active?: boolean; requires_headcount?: boolean }[]
  users: { id: string; name: string; structure_id: string | null }[]
}

export const TZ_LABEL = 'Heure de Libreville (UTC+1)'
export const BUSY_LABEL: Record<string, string> = { reserved: 'Réservé', pending: 'En attente', blocked: 'Indisponible' }
export const APPROVAL_LABEL: Record<string, string> = { auto: 'Confirmation automatique', manual: 'Validation par le gestionnaire' }
export const WEEKDAYS_LONG = ['', 'Lundi', 'Mardi', 'Mercredi', 'Jeudi', 'Vendredi', 'Samedi', 'Dimanche']
/** États membres de la CEEAC (codes ISO 3166-1 alpha-2). */
export const MEMBER_STATES: [string, string][] = [
  ['AO', 'Angola'], ['BI', 'Burundi'], ['CM', 'Cameroun'], ['CF', 'République centrafricaine'], ['CG', 'Congo'], ['CD', 'République démocratique du Congo'],
  ['GA', 'Gabon'], ['GQ', 'Guinée équatoriale'], ['RW', 'Rwanda'], ['ST', 'Sao Tomé-et-Principe'], ['TD', 'Tchad'],
]
export const GOV02_NOTICE = 'Les données des participants externes sont traitées selon les textes internes de la Commission de la CEEAC (GOV-02). Ne saisissez que le nom et l’organisation nécessaires à l’accueil.'

export const availabilityTone = (state?: string) => (state === 'free' ? 'success' : state === 'busy' ? 'warning' : state === 'unavailable' ? 'danger' : 'neutral')
export const slotLabel = (start?: string | null, end?: string | null) => (start ? `${fmtDate(start)} · ${fmtTime(start)}–${fmtTime(end)}` : '—')
export const toMinutes = (hhmm: string) => {
  const [h, m] = hhmm.split(':').map(Number)
  return (h || 0) * 60 + (m || 0)
}
export const fromMinutes = (min: number) => `${String(Math.floor(min / 60)).padStart(2, '0')}:${String(min % 60).padStart(2, '0')}`
/** Lundi de la semaine contenant la date (YYYY-MM-DD, fuseau institutionnel). */
export const mondayOf = (date: string) => addDays(date, -(localParts(new Date(`${date}T12:00:00+01:00`)).weekday - 1))
export const weekdayOf = (date: string) => localParts(new Date(`${date}T12:00:00+01:00`)).weekday
const monthFmt = new Intl.DateTimeFormat('fr-FR', { timeZone: TZ, month: 'long', year: 'numeric' })
const dayMonthFmt = new Intl.DateTimeFormat('fr-FR', { timeZone: TZ, weekday: 'short', day: 'numeric', month: 'short' })
export const fmtMonth = (date: string) => monthFmt.format(new Date(`${date}T12:00:00+01:00`))
export const fmtDayMonth = (date: string) => dayMonthFmt.format(new Date(`${date}T12:00:00+01:00`))
export const ensureArray = <T,>(v: T[] | null | undefined): T[] => (Array.isArray(v) ? v : [])

export function recurrenceLabel(r: Recurrence | null): string {
  if (!r || r.freq === 'none') return 'Occurrence unique'
  const base = r.freq === 'daily' ? 'Quotidienne' : r.freq === 'weekly' ? `Hebdomadaire (${ensureArray(r.byweekday).map((d) => WEEKDAYS_LONG[d]).join(', ') || 'même jour'})` : 'Mensuelle'
  const every = r.interval && r.interval > 1 ? ` · tous les ${r.interval}` : ''
  const end = r.until ? ` · jusqu’au ${fmtDate(`${r.until}T12:00:00+01:00`)}` : r.count ? ` · ${r.count} occurrence(s)` : ''
  return base + every + end
}

/** Occurrence de référence pour une liste : la prochaine à venir, sinon la dernière. */
export function pickOccurrence(r: Reservation, now = Date.now()): Occurrence | undefined {
  const sorted = [...r.occurrences].sort((a, b) => a.starts_at.localeCompare(b.starts_at))
  return sorted.find((o) => new Date(o.ends_at).getTime() > now && !['cancelled', 'refused'].includes(o.status)) ?? sorted.find((o) => new Date(o.ends_at).getTime() > now) ?? sorted[sorted.length - 1]
}

export function errorText(e: unknown): string {
  return e instanceof ApiError ? e.message : e instanceof Error ? e.message : String(e)
}

export function useReferentials() {
  return useQuery({ queryKey: ['referentials'], queryFn: ({ signal }) => api.get<{ data: Referentials }>('/referentials', undefined, signal).then((r) => r.data), staleTime: 300_000 })
}

/**
 * Le calendrier ne renvoie que l'identifiant d'occurrence : cet index relie les occurrences visibles à leur réservation
 * à partir des réservations de l'utilisateur (et de celles qu'il gère).
 */
export function useOccurrenceIndex() {
  const { can } = useAuth()
  const manager = can('reservations.approve')
  const creator = can('reservations.create')
  return useQuery({
    queryKey: ['reservations', 'occurrence-index', manager, creator],
    staleTime: 60_000,
    queryFn: async ({ signal }) => {
      const map = new Map<string, string>()
      const scopes = [creator ? 'mine' : null, manager ? 'managed' : null].filter(Boolean) as string[]
      for (const scope of scopes) {
        try {
          const page = await api.get<Paginated<Reservation>>('/reservations', { scope, per_page: 500 }, signal)
          page.data.forEach((r) => r.occurrences.forEach((o) => map.set(o.id, r.id)))
        } catch (e) {
          if ((e as Error).name === 'AbortError') throw e
        }
      }
      return map
    },
  })
}

/** Exports PDF (PDF01, PDF02, PDF03) : la génération est asynchrone ; le fichier est ouvert dès qu'il est prêt. */
export function useExportPdf() {
  const toast = useToast()
  const [busy, setBusy] = useState<string | null>(null)
  const run = async (type: string, params: Record<string, unknown>, label: string, format: 'pdf' | 'csv' = 'pdf') => {
    setBusy(type)
    try {
      const created = await api.post<{ data: { id: string; status: string; error?: string | null } }>('/exports', { type, format, params })
      let current = created.data
      for (let i = 0; i < 20 && !['ready', 'failed', 'cancelled', 'expired'].includes(current.status); i++) {
        await new Promise((r) => setTimeout(r, 1500))
        current = (await api.get<{ data: typeof current }>(`/exports/${current.id}`)).data
      }
      if (current.status === 'ready') {
        window.open(fileUrl(`/exports/${current.id}/download`), '_blank', 'noopener')
        toast.success(`${label} prêt`, 'Le téléchargement s’ouvre dans un nouvel onglet.')
      } else if (current.status === 'failed') {
        toast.error(new ApiError(500, { message: current.error ?? 'La génération a échoué.' }), `${label} non généré`)
      } else {
        toast.info(`${label} en préparation`, 'Retrouvez le document dans « Traitements » dès qu’il sera prêt.')
      }
    } catch (e) {
      toast.error(e, `${label} impossible`)
    } finally {
      setBusy(null)
    }
  }
  return { run, busy }
}

/* ===== Piste horaire d'une journée (V25 vue jour, V31 disponibilités) ===== */
export const DAY_START = 7 * 60 + 30
export const DAY_END = 19 * 60
const pct = (min: number) => Math.max(0, Math.min(100, ((min - DAY_START) / (DAY_END - DAY_START)) * 100))

/** Minutes locales d'un instant, bornées à la journée affichée. */
function clipMinutes(iso: string, day: string): number {
  const p = localParts(iso)
  if (p.date < day) return 0
  if (p.date > day) return 24 * 60
  return p.minutes
}

export function freeGaps(busy: Busy[], day: string, openFrom: string, openTo: string, minGap = 30): [number, number][] {
  const now = localParts(new Date())
  let cursor = toMinutes(openFrom)
  const end = toMinutes(openTo)
  if (day < now.date) return []
  if (day === now.date) cursor = Math.max(cursor, Math.ceil(now.minutes / 15) * 15)
  const intervals = busy
    .map((b) => [clipMinutes(b.blocking_start || b.starts_at, day), clipMinutes(b.blocking_end || b.ends_at, day)] as [number, number])
    .filter(([s, e]) => e > s)
    .sort((a, b) => a[0] - b[0])
  const gaps: [number, number][] = []
  for (const [s, e] of intervals) {
    if (s > cursor && Math.min(s, end) - cursor >= minGap) gaps.push([cursor, Math.min(s, end)])
    cursor = Math.max(cursor, e)
  }
  if (end - cursor >= minGap) gaps.push([cursor, end])
  return gaps
}

export function DayTrack({ busy, day, openFrom, openTo, label, onFree, onOpen }: {
  busy: Busy[]; day: string; openFrom: string; openTo: string; label: string
  onFree?: (start: string, end: string) => void; onOpen?: (b: Busy) => void
}) {
  const items = busy.filter((b) => localParts(b.blocking_start || b.starts_at).date <= day && localParts(b.blocking_end || b.ends_at).date >= day)
  const gaps = onFree ? freeGaps(items, day, openFrom, openTo) : []
  const closedBefore = toMinutes(openFrom)
  const closedAfter = toMinutes(openTo)
  return (
    <div className="day-track" role="group" aria-label={`${label} — ${fmtDayMonth(day)}`}>
      {closedBefore > DAY_START && <span className="closed" style={{ left: 0, width: `${pct(closedBefore)}%` }} title="Hors horaires d’ouverture" aria-hidden="true" />}
      {closedAfter < DAY_END && <span className="closed" style={{ left: `${pct(closedAfter)}%`, right: 0 }} title="Hors horaires d’ouverture" aria-hidden="true" />}
      {gaps.map(([s, e]) => (
        <button key={`g${s}`} type="button" className="free-slot" style={{ left: `${pct(s)}%`, width: `${pct(e) - pct(s)}%` }}
          onClick={() => onFree?.(fromMinutes(s), fromMinutes(e))} aria-label={`Libre de ${fromMinutes(s)} à ${fromMinutes(e)} : réserver ce créneau`} title={`Libre ${fromMinutes(s)}–${fromMinutes(e)}`}>
          <span>Libre</span>
        </button>
      ))}
      {items.map((b, i) => {
        const s = clipMinutes(b.starts_at, day)
        const e = clipMinutes(b.ends_at, day)
        const bs = clipMinutes(b.blocking_start || b.starts_at, day)
        const be = clipMinutes(b.blocking_end || b.ends_at, day)
        const text = `${BUSY_LABEL[b.type] ?? b.type} · ${b.label} · ${fmtTime(b.starts_at)}–${fmtTime(b.ends_at)}`
        const clickable = !!b.occurrence_id && !!onOpen
        return (
          <span key={i}>
            {b.type !== 'blocked' && bs < s && <span className="buffer" style={{ left: `${pct(bs)}%`, width: `${pct(s) - pct(bs)}%` }} title={`Préparation ${fmtTime(b.blocking_start)}–${fmtTime(b.starts_at)}`}><span className="sr-only">Préparation {fmtTime(b.blocking_start)}–{fmtTime(b.starts_at)}</span></span>}
            {b.type !== 'blocked' && be > e && <span className="buffer" style={{ left: `${pct(e)}%`, width: `${pct(be) - pct(e)}%` }} title={`Remise en état ${fmtTime(b.ends_at)}–${fmtTime(b.blocking_end)}`}><span className="sr-only">Remise en état {fmtTime(b.ends_at)}–{fmtTime(b.blocking_end)}</span></span>}
            {clickable ? (
              <button type="button" className={`busy ${b.type}`} style={{ left: `${pct(s)}%`, width: `${Math.max(1.5, pct(e) - pct(s))}%` }} onClick={() => onOpen?.(b)} title={text} aria-label={`${text} : ouvrir le détail`}>
                <b>{BUSY_LABEL[b.type]}</b> {b.label}
              </button>
            ) : (
              <span className={`busy ${b.type}`} style={{ left: `${pct(s)}%`, width: `${Math.max(1.5, pct(e) - pct(s))}%` }} title={text}>
                <span className="sr-only">{text}</span><span aria-hidden="true"><b>{BUSY_LABEL[b.type]}</b> {b.label}</span>
              </span>
            )}
          </span>
        )
      })}
    </div>
  )
}

export function HourScale() {
  const marks = ['07:30', '09:00', '10:30', '12:00', '13:30', '15:00', '16:30', '18:00']
  return (
    <div className="hour-scale" aria-hidden="true">
      {marks.map((m) => <span key={m} style={{ left: `${pct(toMinutes(m))}%` }}>{m}</span>)}
      <span style={{ left: '100%' }}>19:00</span>
    </div>
  )
}

export function CalendarLegend() {
  return (
    <div className="cal-legend" aria-label="Légende du calendrier">
      <span><i className="lg-free" aria-hidden="true" />Libre (cliquer pour réserver)</span>
      <span><i className="lg-buffer" aria-hidden="true" />Préparation / remise en état (hachuré)</span>
      <span><i className="lg-reserved" aria-hidden="true" />Réservé</span>
      <span><i className="lg-pending" aria-hidden="true" />En attente de validation</span>
      <span><i className="lg-blocked" aria-hidden="true" />Indisponible (fermeture)</span>
      <span className="muted">Réunions confidentielles : « Réservée » · {TZ_LABEL}</span>
    </div>
  )
}

/* ===== Parcours en étapes (maquette FlowModal) ===== */
export function FlowProgress({ steps, step }: { steps: string[]; step: number }) {
  return (
    <div className="flow-progress" role="list" aria-label="Étapes du parcours">
      {steps.map((s, i) => (
        <div role="listitem" className={i < step ? 'done' : i === step ? 'active' : ''} key={s} aria-current={i === step ? 'step' : undefined}>
          <span>{i < step ? <Icon name="check" size={13} /> : i + 1}</span>
          <b>{s}</b>
          <em className="sr-only">{i < step ? '(terminée)' : i === step ? '(en cours)' : '(à venir)'}</em>
        </div>
      ))}
    </div>
  )
}

/* ===== Aperçu du panneau de salle (T03, maquette RoomDisplay) ===== */
export function RoomDisplayPreview({ room, busy, floorLabel }: { room: { name: string; capacity: number }; busy: Busy[]; floorLabel?: string | null }) {
  const nowMs = Date.now()
  const nowIso = new Date(nowMs).toISOString()
  const sorted = [...busy].sort((a, b) => a.starts_at.localeCompare(b.starts_at))
  const current = sorted.find((b) => new Date(b.starts_at).getTime() <= nowMs && new Date(b.ends_at).getTime() > nowMs && b.type !== 'pending')
  const upcoming = sorted.filter((b) => new Date(b.starts_at).getTime() > nowMs && b.type !== 'pending')
  const next = upcoming[0]
  const state = current ? (current.type === 'blocked' ? 'unavailable' : 'busy') : 'free'
  const stateLabel = state === 'free' ? 'LIBRE' : state === 'busy' ? (current?.confidential ? 'RÉSERVÉE' : 'OCCUPÉE') : 'INDISPONIBLE'
  const sub = current ? `${current.type === 'blocked' ? 'Indisponible' : current.label} · jusqu’à ${fmtTime(current.ends_at)}` : next ? `Disponible jusqu’à ${fmtTime(next.starts_at)}` : 'Disponible toute la journée'
  return (
    <div className={`room-display room-display-preview ${state}`} role="img" aria-label={`Aperçu du panneau : ${room.name}, ${stateLabel.toLowerCase()}, ${sub}`}>
      <header>
        <img src="/ceeac-logo.jpg" alt="" />
        <span>SAIE–CEEAC</span>
        <time>{fmtTime(nowIso)}</time>
      </header>
      <main>
        <div>
          <small>SALLE DE RÉUNION{floorLabel ? ` · ${floorLabel.toUpperCase()}` : ''} · {room.capacity} PLACES</small>
          <h2>{room.name}</h2>
          <span className="room-state">{stateLabel}</span>
          <p>{sub}</p>
        </div>
        <div className="next-meeting">
          <small>PROCHAINE RÉSERVATION</small>
          {next ? (
            <>
              <b>{fmtTime(next.starts_at)} — {fmtTime(next.ends_at)}</b>
              <p>{next.type === 'blocked' ? 'Indisponible' : next.label}</p>
              {upcoming.length > 1 && <span>Puis {upcoming.slice(1, 3).map((u) => `${fmtTime(u.starts_at)} ${u.type === 'blocked' ? 'Indisponible' : u.label}`).join(' · ')}</span>}
            </>
          ) : (
            <><b>—</b><p>Aucune autre réservation aujourd’hui</p></>
          )}
        </div>
      </main>
    </div>
  )
}

export function Section({ title, description, actions, children, className }: { title: string; description?: ReactNode; actions?: ReactNode; children: ReactNode; className?: string }) {
  return (
    <section className={`panel section-gap ${className ?? ''}`}>
      <div className="panel-title">
        <div><h2>{title}</h2>{description && <p>{description}</p>}</div>
        {actions && <div className="row-actions">{actions}</div>}
      </div>
      {children}
    </section>
  )
}

/** Annulation avec portée (SAL-07) : cette occurrence, les suivantes ou toute la série ; motif obligatoire. */
export function CancelOccurrenceDialog({ reservation, occurrence, onClose, onDone }: { reservation: Reservation; occurrence: Occurrence; onClose: () => void; onDone?: (r: Reservation & { cancelled?: number }) => void }) {
  const toast = useToast()
  const series = reservation.occurrences.length > 1
  const [scope, setScope] = useState<'this' | 'following' | 'all'>('this')
  const scopeLabel = { this: 'cette occurrence', following: 'cette occurrence et les suivantes', all: 'toute la série' }[scope]
  return (
    <ConfirmDialog
      title="Annuler la réservation" danger
      object={`${reservation.reference} · ${reservation.subject} · ${slotLabel(occurrence.starts_at, occurrence.ends_at)}`}
      consequence={`L’annulation porte sur ${scopeLabel}. Le créneau est libéré, les prestations et ressources associées sont annulées et l’organisateur est notifié.`}
      confirmLabel={`Annuler ${scopeLabel}`} reasonLabel="Motif de l’annulation" reasonRequired onClose={onClose}
      onConfirm={async (reason) => {
        const r = await api.post<{ cancelled: number; data: Reservation }>(`/occurrences/${occurrence.id}/cancel`, { scope, reason })
        toast.success('Annulation enregistrée', `${r.cancelled} occurrence(s) annulée(s).`)
        onDone?.({ ...r.data, cancelled: r.cancelled })
      }}
    >
      {series && (
        <fieldset className="target-list" style={{ marginTop: 12, border: 0, padding: 0 }}>
          <legend className="small" style={{ marginBottom: 6, fontWeight: 600 }}>Portée de l’annulation</legend>
          {(['this', 'following', 'all'] as const).map((s) => (
            <label key={s}>
              <input type="radio" name="cancel-scope" checked={scope === s} onChange={() => setScope(s)} />
              <span><b>{{ this: 'Cette occurrence', following: 'Cette occurrence et les suivantes', all: 'Toute la série' }[s]}</b>{s === 'this' ? slotLabel(occurrence.starts_at, occurrence.ends_at) : s === 'following' ? `À partir du ${fmtDate(occurrence.starts_at)}` : `${reservation.occurrences.length} occurrence(s) au total`}</span>
            </label>
          ))}
        </fieldset>
      )}
    </ConfirmDialog>
  )
}
