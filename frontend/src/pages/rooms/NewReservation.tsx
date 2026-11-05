import { useMemo, useState } from 'react'
import { useNavigate, useSearchParams } from 'react-router'
import { useQuery, useQueryClient } from '@tanstack/react-query'
import { api, ApiError, type Paginated } from '../../lib/api'
import { useAuth } from '../../lib/auth'
import { fmtDate, fmtLongDate, fmtTime, localParts, OCC_STATUS, toIsoFromLocal, todayLocal } from '../../lib/format'
import { Button, ErrorState, Field, Icon, Loading, Notice, PageHeading, Status, StatusOf, useUnsavedWarning } from '../../components/ui'
import {
  availabilityTone, ensureArray, FlowProgress, freeGaps, fromMinutes, GOV02_NOTICE, recurrenceLabel, slotLabel, toMinutes, TZ_LABEL, useExportPdf, useReferentials,
  weekdayOf, WEEKDAYS_LONG, type Busy, type Recurrence, type Reservation, type Room,
} from './shared'

const STEPS = ['Besoin', 'Créneau', 'Vérification', 'Confirmation']

interface Form {
  subject: string; date: string; start: string; end: string; participants: string; confidential: boolean; description: string
  freq: Recurrence['freq']; interval: string; byweekday: number[]; endType: 'until' | 'count'; until: string; count: string
  mode: 'all_or_nothing' | 'partial'
  services: Record<string, { on: boolean; details: string; headcount?: string; serve_time?: string }>
  resource_ids: string[]
  externals: { name: string; organization: string }[]
  room_id: string
}
interface Conflict { start_at: string; end_at: string; unavailable_from?: string; unavailable_to?: string; reason?: string }
interface CheckResult {
  occurrences: { start_at: string; end_at: string }[]; conflicts: Conflict[]
  alternatives: { room_id: string; name: string; floor: string; capacity: number; start_at: string; end_at: string }[]
  approval_mode: string; capacity_ok: boolean
}
interface MobileResource { id: string; code: string; name: string; type: string; status: string }

const addMonths = (date: string, months: number) => {
  const [y, m, d] = date.split('-').map(Number)
  const t = new Date(Date.UTC(y, m - 1 + months, d))
  return t.toISOString().slice(0, 10)
}
const sameInstant = (a: string, b: string) => new Date(a).getTime() === new Date(b).getTime()
const asError = (e: unknown) => (e instanceof ApiError ? e : new ApiError(500, { message: String(e) }))

/** V27 Nouvelle réservation (parcours P03/P04) : contrôle serveur, aperçu des occurrences, saisie conservée en cas de conflit. */
export default function NewReservation() {
  const navigate = useNavigate()
  const client = useQueryClient()
  const { me } = useAuth()
  const [params] = useSearchParams()
  const refs = useReferentials()
  const pdf = useExportPdf()

  const initial = useMemo<Form>(() => {
    const date = params.get('date') && params.get('date')! >= todayLocal() ? params.get('date')! : todayLocal()
    const start = /^\d{2}:\d{2}$/.test(params.get('start') ?? '') ? params.get('start')! : ''
    let end = /^\d{2}:\d{2}$/.test(params.get('end') ?? '') ? params.get('end')! : ''
    if (start && (!end || toMinutes(end) - toMinutes(start) > 120)) end = fromMinutes(Math.min(toMinutes(start) + 60, 23 * 60 + 45))
    return {
      subject: '', date, start, end, participants: params.get('participants') ?? '', confidential: false, description: '',
      freq: 'none', interval: '1', byweekday: [weekdayOf(date)], endType: 'count', until: '', count: '4', mode: 'all_or_nothing',
      services: {}, resource_ids: [], externals: [], room_id: params.get('room_id') ?? '',
    }
  }, [params])
  const [f, setF] = useState<Form>(initial)
  const [step, setStep] = useState(0)
  const [local, setLocal] = useState<Record<string, string>>({})
  const [serverError, setServerError] = useState<ApiError | null>(null)
  const [check, setCheck] = useState<CheckResult | null>(null)
  const [checking, setChecking] = useState(false)
  const [submitting, setSubmitting] = useState(false)
  const [raceConflicts, setRaceConflicts] = useState<Conflict[] | null>(null)
  const [result, setResult] = useState<{ data: Reservation; skipped: { start_at: string; end_at: string }[] } | null>(null)
  const dirty = JSON.stringify(f) !== JSON.stringify(initial)
  useUnsavedWarning(dirty && !result)

  const upd = <K extends keyof Form>(k: K, v: Form[K]) => setF((x) => ({ ...x, [k]: v }))
  const slotOk = !!f.date && !!f.start && !!f.end && f.end > f.start
  const from = slotOk ? toIsoFromLocal(f.date, f.start) : undefined
  const to = slotOk ? toIsoFromLocal(f.date, f.end) : undefined
  const participants = Number(f.participants) || 0

  const resources = useQuery({ queryKey: ['mobile-resources'], queryFn: ({ signal }) => api.get<{ data: MobileResource[] }>('/mobile-resources', undefined, signal).then((r) => r.data), staleTime: 300_000 })
  const rooms = useQuery({
    queryKey: ['rooms', 'booking', from, to, participants],
    queryFn: ({ signal }) => api.get<Paginated<Room>>('/rooms', { from, to, capacity: participants || undefined, per_page: 500 }, signal).then((r) => r.data),
    enabled: step >= 1 && slotOk,
  })
  const sortedRooms = useMemo(() => {
    const rank = (r: Room) => (r.availability?.state === 'free' ? 0 : r.availability?.state === 'busy' ? 1 : 2)
    return [...(rooms.data ?? [])].sort((a, b) => rank(a) - rank(b) || a.capacity - b.capacity)
  }, [rooms.data])
  const selectedRoom = (rooms.data ?? []).find((r) => r.id === f.room_id)

  /* Créneaux décalés dans la même salle (alternative « horaire décalé »), calculés à partir des disponibilités du jour. */
  const sameRoomAvailability = useQuery({
    queryKey: ['room-availability', f.room_id, f.date],
    queryFn: ({ signal }) => api.get<{ data: { busy: Busy[] } }>(`/rooms/${f.room_id}/availability`, { from: toIsoFromLocal(f.date, '00:00'), to: toIsoFromLocal(f.date, '23:59') }, signal).then((r) => r.data.busy),
    enabled: step === 2 && !!check && (check.conflicts.length > 0 || !!raceConflicts) && f.freq === 'none' && !!f.room_id,
  })
  const shifted = useMemo(() => {
    if (!sameRoomAvailability.data || !selectedRoom || !slotOk) return []
    const duration = toMinutes(f.end) - toMinutes(f.start)
    const need = duration + selectedRoom.prep_minutes + selectedRoom.cleanup_minutes
    return freeGaps(sameRoomAvailability.data, f.date, fromMinutes(Math.max(0, toMinutes(selectedRoom.open_from) - selectedRoom.prep_minutes)), fromMinutes(Math.min(24 * 60 - 1, toMinutes(selectedRoom.open_to) + selectedRoom.cleanup_minutes)), need)
      .map(([s]) => {
        const start = Math.max(s + selectedRoom.prep_minutes, toMinutes(selectedRoom.open_from))
        return [start, start + duration] as [number, number]
      })
      .filter(([s, e]) => e <= toMinutes(selectedRoom.open_to) && s !== toMinutes(f.start))
      .slice(0, 2)
  }, [sameRoomAvailability.data, selectedRoom, slotOk, f.start, f.end, f.date])

  const recurrence = (): Record<string, unknown> | null => {
    if (f.freq === 'none') return null
    return {
      freq: f.freq, interval: Number(f.interval) || 1,
      ...(f.freq === 'weekly' ? { byweekday: f.byweekday } : {}),
      ...(f.endType === 'until' ? { until: f.until } : { count: Number(f.count) }),
    }
  }
  const payload = (roomId = f.room_id) => ({
    room_id: roomId, start_at: from, end_at: to, confidentiality: f.confidential, subject: f.subject.trim(), participant_count: participants,
    description: f.description.trim() || null, recurrence: recurrence(), recurrence_mode: f.freq === 'none' ? null : f.mode,
    services: Object.entries(f.services).filter(([, v]) => v.on).map(([service_type_id, v]) => ({
      service_type_id, details: v.details.trim() || null, headcount: v.headcount ? Number(v.headcount) : null, serve_time: v.serve_time || null,
    })),
    resource_ids: f.resource_ids,
    external_participants: f.externals.filter((x) => x.name.trim()).map((x) => ({ name: x.name.trim(), organization: x.organization.trim() || null })),
  })

  const validateNeed = (): boolean => {
    const e: Record<string, string> = {}
    if (!f.subject.trim()) e.subject = 'L’objet est obligatoire.'
    if (!f.date) e.date = 'La date est obligatoire.'
    else if (f.date < todayLocal()) e.date = 'La date est passée.'
    if (!f.start) e.start = 'L’heure de début est obligatoire.'
    if (!f.end) e.end = 'L’heure de fin est obligatoire.'
    else if (f.start && f.end <= f.start) e.end = 'La fin doit être postérieure au début.'
    if (f.date === todayLocal() && f.start && toMinutes(f.start) <= localParts(new Date()).minutes) e.start = 'Ce créneau est déjà commencé.'
    if (!participants || participants < 1) e.participants = 'Indiquez au moins 1 participant.'
    if (f.freq !== 'none') {
      if (f.freq === 'weekly' && f.byweekday.length === 0) e.byweekday = 'Choisissez au moins un jour.'
      if (f.endType === 'until') {
        if (!f.until) e.until = 'Indiquez la date de fin de série.'
        else if (f.until < f.date) e.until = 'La fin de série précède la première occurrence.'
        else if (f.until > addMonths(f.date, 6)) e.until = 'Une série ne peut dépasser 6 mois.'
      } else {
        const n = Number(f.count)
        if (!n || n < 1 || n > 100) e.count = 'Entre 1 et 100 occurrences.'
      }
    }
    f.externals.forEach((x, i) => { if (!x.name.trim() && x.organization.trim()) e[`external_${i}`] = 'Le nom est obligatoire.' })
    for (const type of serviceTypes.filter((s) => s.requires_headcount)) {
      const v = f.services[type.id]
      if (!v?.on) continue
      const n = Number(v.headcount)
      if (!n || n < 1 || n > 5000) e[`service_${type.id}_headcount`] = 'Indiquez le nombre de couverts.'
      if (!v.serve_time) e[`service_${type.id}_time`] = 'Indiquez l’heure de service.'
      else if (f.start && f.end && (v.serve_time < f.start || v.serve_time >= f.end)) e[`service_${type.id}_time`] = `L’heure doit se situer pendant la réunion (${f.start}–${f.end}).`
    }
    setLocal(e)
    return Object.keys(e).length === 0
  }

  /** Erreur de champ serveur ramenée au champ du formulaire correspondant. */
  const fieldErr = (name: string): string | undefined => {
    if (local[name]) return local[name]
    const map: Record<string, string[]> = {
      subject: ['subject'], start: ['start_at'], end: ['end_at'], participants: ['participant_count'], description: ['description'],
      until: ['recurrence.until'], count: ['recurrence.count'], byweekday: ['recurrence.byweekday'], room: ['room_id'],
    }
    for (const k of map[name] ?? []) { const m = serverError?.field(k); if (m) return m }
    return undefined
  }

  const runCheck = async (roomId = f.room_id): Promise<CheckResult | null> => {
    setChecking(true)
    setServerError(null)
    try {
      const r = await api.post<{ data: CheckResult }>('/reservations/check', payload(roomId), false)
      setCheck(r.data)
      return r.data
    } catch (e) {
      setCheck(null)
      setServerError(asError(e))
      return null
    } finally {
      setChecking(false)
    }
  }

  const next = async () => {
    if (step === 0) {
      if (!validateNeed()) return
      setServerError(null)
      setStep(1)
    } else if (step === 1) {
      if (!f.room_id) { setLocal({ room: 'Sélectionnez une salle.' }); return }
      setRaceConflicts(null)
      setStep(2)
      await runCheck()
    } else if (step === 2) {
      await submit()
    }
  }

  const submit = async () => {
    setSubmitting(true)
    setServerError(null)
    try {
      const r = await api.post<{ data: Reservation; skipped: { start_at: string; end_at: string }[] }>('/reservations', payload())
      setResult(r)
      setStep(3)
      client.invalidateQueries({ queryKey: ['reservations'] })
      client.invalidateQueries({ queryKey: ['rooms-calendar'] })
      client.invalidateQueries({ queryKey: ['rooms'] })
    } catch (e) {
      const err = asError(e)
      setServerError(err)
      if (err.code === 'BOOKING_CONFLICT') {
        setRaceConflicts(ensureArray(err.details.conflicts as Conflict[] | undefined))
        await runCheck().then((c) => c && setServerError(err))
      }
    } finally {
      setSubmitting(false)
    }
  }

  const pickAlternative = async (roomId: string) => {
    upd('room_id', roomId)
    setRaceConflicts(null)
    await runCheck(roomId)
  }
  const pickShift = async (s: number, e: number) => {
    const next = { ...f, start: fromMinutes(s), end: fromMinutes(e) }
    setF(next)
    setRaceConflicts(null)
    setChecking(true)
    setServerError(null)
    try {
      const r = await api.post<{ data: CheckResult }>('/reservations/check', { ...payload(), start_at: toIsoFromLocal(next.date, next.start), end_at: toIsoFromLocal(next.date, next.end) }, false)
      setCheck(r.data)
    } catch (err) {
      setServerError(asError(err))
    } finally {
      setChecking(false)
    }
  }

  const reset = () => {
    setF(initial)
    setStep(0)
    setCheck(null)
    setResult(null)
    setServerError(null)
    setRaceConflicts(null)
  }

  const conflicts = raceConflicts && raceConflicts.length ? raceConflicts : check?.conflicts ?? []
  const blocking = conflicts.length > 0 && (f.freq === 'none' || f.mode === 'all_or_nothing' || (check && conflicts.length >= check.occurrences.length))
  const capacityKo = check ? !check.capacity_ok : false

  if (refs.isLoading) return <Loading />
  if (refs.isError) return <ErrorState error={refs.error} onRetry={() => refs.refetch()} />
  const serviceTypes = (refs.data?.service_types ?? []).filter((s) => s.is_active !== false)

  return (
    <>
      <PageHeading title="Nouvelle réservation" description={`Saisie en ${TZ_LABEL}. La disponibilité inclut les temps de préparation et de remise en état.`} demo={me.demo_mode} />
      <section className="panel flow-page">
        <FlowProgress steps={STEPS} step={step} />
        <div className="flow-content">
          <div className="step-label">ÉTAPE {step + 1} SUR {STEPS.length}</div>

          {step === 0 && (
            <>
              <h3>Quel est votre besoin ?</h3>
              <p>Les champs marqués d’un astérisque sont nécessaires au contrôle de disponibilité.</p>
              <div className="form-grid">
                <Field label="Objet interne" required error={fieldErr('subject')} wide>
                  <input value={f.subject} maxLength={200} onChange={(e) => upd('subject', e.target.value)} aria-invalid={!!fieldErr('subject')} />
                </Field>
                <Field label="Date" required error={fieldErr('date')}>
                  <input type="date" value={f.date} min={todayLocal()} onChange={(e) => setF((x) => ({ ...x, date: e.target.value, byweekday: x.byweekday.length <= 1 && e.target.value ? [weekdayOf(e.target.value)] : x.byweekday }))} aria-invalid={!!fieldErr('date')} />
                </Field>
                <Field label="Participants" required error={fieldErr('participants')}>
                  <input type="number" min={1} max={5000} value={f.participants} onChange={(e) => upd('participants', e.target.value)} aria-invalid={!!fieldErr('participants')} />
                </Field>
                <Field label="Début" required error={fieldErr('start')} help={TZ_LABEL}>
                  <input type="time" step={900} value={f.start} onChange={(e) => upd('start', e.target.value)} aria-invalid={!!fieldErr('start')} />
                </Field>
                <Field label="Fin" required error={fieldErr('end')} help={TZ_LABEL}>
                  <input type="time" step={900} value={f.end} onChange={(e) => upd('end', e.target.value)} aria-invalid={!!fieldErr('end')} />
                </Field>
                <fieldset className="wide">
                  <legend>Confidentialité *</legend>
                  <label className="check-label">
                    <input type="checkbox" checked={f.confidential} onChange={(e) => upd('confidential', e.target.checked)} />
                    Réunion confidentielle : le panneau affichera uniquement « Réservée »
                  </label>
                  <small>Décochée, l’objet est visible sur le panneau de salle, l’agenda des écrans et le planning. Cochée, aucun message de bienvenue n’est possible.</small>
                </fieldset>
                <Field label="Description" error={fieldErr('description')} wide>
                  <textarea value={f.description} maxLength={2000} onChange={(e) => upd('description', e.target.value)} />
                </Field>

                <fieldset className="wide">
                  <legend>Récurrence</legend>
                  <div className="radio-row" role="radiogroup" aria-label="Fréquence">
                    {([['none', 'Aucune'], ['daily', 'Quotidienne'], ['weekly', 'Hebdomadaire'], ['monthly', 'Mensuelle']] as const).map(([v, l]) => (
                      <label key={v}><input type="radio" name="freq" checked={f.freq === v} onChange={() => upd('freq', v)} /> {l}</label>
                    ))}
                  </div>
                  {f.freq !== 'none' && (
                    <div className="form-grid" style={{ marginTop: 10 }}>
                      <Field label={f.freq === 'daily' ? 'Tous les … jours' : f.freq === 'weekly' ? 'Toutes les … semaines' : 'Tous les … mois'}>
                        <input type="number" min={1} max={12} value={f.interval} onChange={(e) => upd('interval', e.target.value)} />
                      </Field>
                      {f.freq === 'weekly' && (
                        <div>
                          <span className="small" style={{ fontWeight: 600 }}>Jours *</span>
                          <div className="weekday-picker">
                            {[1, 2, 3, 4, 5, 6, 7].map((d) => (
                              <label key={d}><input type="checkbox" checked={f.byweekday.includes(d)} onChange={(e) => upd('byweekday', e.target.checked ? [...f.byweekday, d].sort() : f.byweekday.filter((x) => x !== d))} />{WEEKDAYS_LONG[d]}</label>
                            ))}
                          </div>
                          {fieldErr('byweekday') && <span className="field-error" role="alert">{fieldErr('byweekday')}</span>}
                        </div>
                      )}
                      <div className="wide">
                        <div className="radio-row" role="radiogroup" aria-label="Fin de la série">
                          <label><input type="radio" name="endType" checked={f.endType === 'count'} onChange={() => upd('endType', 'count')} /> Après un nombre d’occurrences</label>
                          <label><input type="radio" name="endType" checked={f.endType === 'until'} onChange={() => upd('endType', 'until')} /> À une date</label>
                        </div>
                      </div>
                      {f.endType === 'count' ? (
                        <Field label="Nombre d’occurrences" required error={fieldErr('count')} help="100 au maximum, sur 6 mois au plus">
                          <input type="number" min={1} max={100} value={f.count} onChange={(e) => upd('count', e.target.value)} aria-invalid={!!fieldErr('count')} />
                        </Field>
                      ) : (
                        <Field label="Dernière date" required error={fieldErr('until')} help="6 mois au plus après la première occurrence">
                          <input type="date" value={f.until} min={f.date} max={f.date ? addMonths(f.date, 6) : undefined} onChange={(e) => upd('until', e.target.value)} aria-invalid={!!fieldErr('until')} />
                        </Field>
                      )}
                      <div className="wide">
                        <span className="small" style={{ fontWeight: 600 }}>En cas de conflit sur une occurrence</span>
                        <div className="radio-row" role="radiogroup" aria-label="Mode de création de la série">
                          <label><input type="radio" name="mode" checked={f.mode === 'all_or_nothing'} onChange={() => upd('mode', 'all_or_nothing')} /> Tout ou rien : aucune occurrence n’est créée</label>
                          <label><input type="radio" name="mode" checked={f.mode === 'partial'} onChange={() => upd('mode', 'partial')} /> Partiel : créer uniquement les occurrences disponibles</label>
                        </div>
                      </div>
                    </div>
                  )}
                </fieldset>

                <fieldset className="wide">
                  <legend>Prestations</legend>
                  {serviceTypes.length === 0 ? <small>Aucun type de prestation n’est configuré.</small> : serviceTypes.map((s) => {
                    const v = f.services[s.id] ?? { on: false, details: '' }
                    return (
                      <div className="service-row" key={s.id}>
                        <label><input type="checkbox" checked={v.on} onChange={(e) => upd('services', { ...f.services, [s.id]: { ...v, on: e.target.checked, ...(s.requires_headcount && !v.headcount && participants ? { headcount: String(participants) } : {}) } })} /> {s.name}</label>
                        {v.on && <input type="text" aria-label={`Précisions pour ${s.name}`} placeholder={s.requires_headcount ? 'Précisions (régimes, allergies…)' : 'Précisions (quantité, horaire, langue…)'} maxLength={500} value={v.details} onChange={(e) => upd('services', { ...f.services, [s.id]: { ...v, details: e.target.value } })} />}
                        {v.on && s.requires_headcount && (
                          <div className="service-extra">
                            <Field label="Nombre de couverts" required error={local[`service_${s.id}_headcount`]}>
                              <input type="number" min={1} max={5000} value={v.headcount ?? ''} onChange={(e) => upd('services', { ...f.services, [s.id]: { ...v, headcount: e.target.value } })} />
                            </Field>
                            <Field label="Heure de service" required error={local[`service_${s.id}_time`]} help="Pendant la réunion, heure de Libreville">
                              <input type="time" value={v.serve_time ?? ''} onChange={(e) => upd('services', { ...f.services, [s.id]: { ...v, serve_time: e.target.value } })} />
                            </Field>
                          </div>
                        )}
                      </div>
                    )
                  })}
                </fieldset>

                <fieldset className="wide">
                  <legend>Ressources mobiles (P1)</legend>
                  {resources.isLoading ? <small>Chargement…</small> : resources.isError ? <small>Ressources indisponibles : {(resources.error as Error).message}</small> : (() => {
                    const items = (resources.data ?? []).filter((r) => r.status === 'active')
                    return items.length === 0 ? <small>Aucune ressource mobile disponible.</small> : (
                      <div className="check-grid">
                        {items.map((r) => (
                          <label key={r.id}><input type="checkbox" checked={f.resource_ids.includes(r.id)} onChange={(e) => upd('resource_ids', e.target.checked ? [...f.resource_ids, r.id] : f.resource_ids.filter((x) => x !== r.id))} /> {r.name} <span className="muted">({r.type})</span></label>
                        ))}
                      </div>
                    )
                  })()}
                </fieldset>

                <fieldset className="wide">
                  <legend>Participants externes</legend>
                  <Notice tone="info" icon="shield">{GOV02_NOTICE}</Notice>
                  {f.externals.map((x, i) => (
                    <div className="external-row" key={i}>
                      <Field label={`Nom (${i + 1})`} required error={local[`external_${i}`]}><input value={x.name} maxLength={120} onChange={(e) => upd('externals', f.externals.map((y, j) => (j === i ? { ...y, name: e.target.value } : y)))} /></Field>
                      <Field label="Organisation"><input value={x.organization} maxLength={160} onChange={(e) => upd('externals', f.externals.map((y, j) => (j === i ? { ...y, organization: e.target.value } : y)))} /></Field>
                      <Button variant="ghost" icon="trash" ariaLabel={`Retirer le participant ${i + 1}`} onClick={() => upd('externals', f.externals.filter((_, j) => j !== i))} />
                    </div>
                  ))}
                  <div style={{ marginTop: 8 }}><Button variant="secondary" size="small" icon="plus" onClick={() => upd('externals', [...f.externals, { name: '', organization: '' }])}>Ajouter un participant externe</Button></div>
                </fieldset>
              </div>
            </>
          )}

          {step === 1 && (
            <>
              <h3>Sélectionner une salle</h3>
              <p>{fmtLongDate(`${f.date}T12:00:00+01:00`)} · {f.start}–{f.end} · {participants} participant(s). Les salles disponibles sur ce créneau apparaissent en premier.</p>
              {fieldErr('room') && <div style={{ marginBottom: 10 }}><Notice tone="danger">{fieldErr('room')}</Notice></div>}
              {f.room_id && rooms.data && !selectedRoom && <div style={{ marginBottom: 10 }}><Notice tone="warning">La salle présélectionnée ne peut pas accueillir {participants} participant(s) ou n’est pas réservable. Choisissez une autre salle.</Notice></div>}
              {rooms.isLoading ? <Loading label="Recherche des salles disponibles…" /> : rooms.isError ? <ErrorState error={rooms.error} onRetry={() => rooms.refetch()} /> : sortedRooms.length === 0 ? (
                <Notice tone="warning">Aucune salle active n’accueille {participants} participant(s). Réduisez le nombre de participants ou contactez un gestionnaire.</Notice>
              ) : (
                <div className="room-options" role="radiogroup" aria-label="Salles">
                  {sortedRooms.map((r) => (
                    <button key={r.id} type="button" role="radio" aria-checked={f.room_id === r.id} aria-pressed={f.room_id === r.id} className={f.room_id === r.id ? 'selected' : ''}
                      disabled={r.availability?.state === 'unavailable'} onClick={() => { upd('room_id', r.id); setLocal({}) }}>
                      <span><Icon name="door" /></span>
                      <div>
                        <strong>{r.name}</strong>
                        <small>{r.floor?.label ?? '—'} · {r.capacity} places{r.equipment.length ? ` · ${r.equipment.map((e) => e.name).join(', ')}` : ''}</small>
                        <small>Horaires {r.open_from}–{r.open_to} · durée {r.min_minutes}–{r.max_minutes} min · préparation {r.prep_minutes} + {r.cleanup_minutes} min · {r.approval_mode === 'manual' ? 'sur validation' : 'confirmation immédiate'}</small>
                      </div>
                      <Status tone={availabilityTone(r.availability?.state)}>{r.availability?.label ?? '—'}</Status>
                    </button>
                  ))}
                </div>
              )}
            </>
          )}

          {step === 2 && (
            <>
              <h3>{conflicts.length ? (raceConflicts ? 'Conflit détecté lors de la confirmation' : 'Créneau indisponible') : 'Vérification de la demande'}</h3>
              <p>{raceConflicts ? 'Une autre réservation a été confirmée simultanément. Votre saisie est conservée.' : 'Contrôle effectué par le serveur sans enregistrement : règles de la salle, capacité, tampons et conflits.'}</p>
              {checking && <Loading label="Vérification en cours…" />}
              {serverError && !(serverError.code === 'BOOKING_CONFLICT') && (
                <Notice tone="danger">
                  <strong>{serverError.message}</strong>
                  {Object.values(serverError.errors).flat().length > 0 && <ul style={{ margin: '6px 0 0 16px' }}>{Object.values(serverError.errors).flat().map((m) => <li key={m}>{m}</li>)}</ul>}
                  <div style={{ marginTop: 6 }}><Button size="small" variant="secondary" onClick={() => setStep(serverError.field('room_id') ? 1 : 0)}>Corriger la saisie</Button></div>
                </Notice>
              )}
              {!checking && check && (
                <>
                  {selectedRoom && (
                    <div className="booking-link" style={{ marginBottom: 12 }}>
                      <Icon name="door" />
                      <span><strong>{selectedRoom.name} · {selectedRoom.floor?.label}</strong><small>{selectedRoom.capacity} places · {check.approval_mode === 'manual' ? 'Demande soumise à validation du gestionnaire' : 'Confirmation immédiate si disponible'}</small></span>
                      <Status tone={conflicts.length ? 'danger' : 'success'}>{conflicts.length ? `${conflicts.length} conflit(s)` : 'Disponible'}</Status>
                    </div>
                  )}
                  {capacityKo && <div style={{ marginBottom: 10 }}><Notice tone="danger">La salle ne peut pas accueillir {participants} participant(s). Choisissez une salle plus grande.</Notice></div>}
                  {conflicts.length > 0 && (
                    <div className="confirmation-box danger">
                      <Icon name="alert" />
                      <div>
                        <strong>{raceConflicts ? 'Aucune double réservation n’a été créée.' : `${conflicts.length} créneau(x) indisponible(s)`}</strong>
                        <p>{serverError?.code === 'BOOKING_CONFLICT' ? serverError.message : 'Les créneaux occupés incluent les temps de préparation et de remise en état ; les objets des autres réunions ne sont pas affichés.'}</p>
                      </div>
                    </div>
                  )}
                  {conflicts.slice(0, 6).map((c, i) => (
                    <div className="conflict-slot" key={i}>
                      <span>Votre demande<b>{slotLabel(c.start_at, c.end_at)}</b></span>
                      <span>{c.reason ?? 'Créneau indisponible'}<b>{c.unavailable_from ? `${fmtTime(c.unavailable_from)} — ${fmtTime(c.unavailable_to)}` : 'Occupé'}</b></span>
                    </div>
                  ))}
                  {conflicts.length > 6 && <p className="muted small" style={{ marginTop: 6 }}>… et {conflicts.length - 6} autre(s) conflit(s).</p>}

                  {check.occurrences.length > 1 && (
                    <>
                      <h4 style={{ marginTop: 16, fontSize: 12 }}>Aperçu des {check.occurrences.length} occurrences · {recurrenceLabel(recurrence() as Recurrence | null)}</h4>
                      <div className="occ-preview">
                        {check.occurrences.map((o, i) => {
                          const conflict = conflicts.some((c) => sameInstant(c.start_at, o.start_at))
                          return (
                            <div key={o.start_at} className={conflict ? 'conflict' : ''}>
                              <span>{i + 1}. {fmtLongDate(o.start_at)} · {fmtTime(o.start_at)}–{fmtTime(o.end_at)}</span>
                              <Status tone={conflict ? 'danger' : 'success'}>{conflict ? (f.mode === 'partial' ? 'Conflit — ne sera pas créée' : 'Conflit') : 'Disponible'}</Status>
                            </div>
                          )
                        })}
                      </div>
                      {conflicts.length > 0 && (
                        <div className="radio-row" style={{ marginTop: 10, display: 'flex', gap: 14, flexWrap: 'wrap' }} role="radiogroup" aria-label="Mode de création">
                          <label className="small"><input type="radio" name="mode2" checked={f.mode === 'all_or_nothing'} onChange={() => upd('mode', 'all_or_nothing')} /> Tout ou rien (aucune création tant qu’un conflit subsiste)</label>
                          <label className="small"><input type="radio" name="mode2" checked={f.mode === 'partial'} onChange={() => upd('mode', 'partial')} /> Partiel explicite : créer les {check.occurrences.length - conflicts.length} occurrence(s) disponibles</label>
                        </div>
                      )}
                    </>
                  )}

                  {conflicts.length > 0 && (check.alternatives.length > 0 || shifted.length > 0) && (
                    <>
                      <h4 style={{ margin: '16px 0 8px', fontSize: 12 }}>Créneaux alternatifs</h4>
                      <div className="room-options">
                        {check.alternatives.map((a, i) => (
                          <button key={a.room_id} type="button" onClick={() => pickAlternative(a.room_id)} className={i === 0 ? 'selected' : ''}>
                            <span><Icon name="door" /></span>
                            <div><strong>{a.name} · même horaire</strong><small>{a.floor} · {a.capacity} places · {fmtTime(a.start_at)}–{fmtTime(a.end_at)}</small></div>
                            <Status tone="success">{i === 0 ? 'Recommandé' : 'Disponible'}</Status>
                          </button>
                        ))}
                        {shifted.map(([s, e]) => (
                          <button key={s} type="button" onClick={() => pickShift(s, e)}>
                            <span><Icon name="clock" /></span>
                            <div><strong>{selectedRoom?.name} · horaire décalé</strong><small>{selectedRoom?.floor?.label} · {selectedRoom?.capacity} places · {fromMinutes(s)}–{fromMinutes(e)}</small></div>
                            <Status tone="blue">Disponible</Status>
                          </button>
                        ))}
                      </div>
                    </>
                  )}
                  {conflicts.length > 0 && check.alternatives.length === 0 && shifted.length === 0 && !sameRoomAvailability.isLoading && (
                    <div style={{ marginTop: 12 }}><Notice tone="warning">Aucune alternative automatique. Modifiez la date ou l’horaire, ou choisissez une autre salle.</Notice></div>
                  )}
                  {!conflicts.length && !capacityKo && (
                    <div className="confirmation-box" style={{ background: 'var(--green-soft)', color: '#145f47', marginTop: 12 }}>
                      <Icon name="check" />
                      <div>
                        <strong>{f.subject} · {check.occurrences.length > 1 ? `${check.occurrences.length} occurrences` : slotLabel(check.occurrences[0]?.start_at, check.occurrences[0]?.end_at)}</strong>
                        <p>{f.confidential ? 'Réunion confidentielle : affichée « Réservée ». ' : ''}{check.approval_mode === 'manual' ? 'La demande sera bloquée en attente de validation par le gestionnaire.' : 'La réservation sera confirmée immédiatement.'}</p>
                      </div>
                    </div>
                  )}
                </>
              )}
            </>
          )}

          {step === 3 && result && (() => {
            const r = result.data
            const pending = r.occurrences.some((o) => o.status === 'pending')
            const first = r.occurrences[0]
            return (
              <>
                <h3>{pending ? 'Demande enregistrée' : 'Réservation confirmée'}</h3>
                <p>{pending ? 'Le gestionnaire de la salle a été notifié. Le créneau est bloqué jusqu’à sa décision.' : 'Les agendas autorisés et le panneau de salle seront mis à jour.'}</p>
                <div className={`booking-confirmed ${pending ? 'pending' : ''}`} role="status">
                  <span><Icon name={pending ? 'clock' : 'check'} size={30} /></span>
                  <h4>{r.subject}</h4>
                  <p>{first ? `${fmtLongDate(first.starts_at)} · ${fmtTime(first.starts_at)}–${fmtTime(first.ends_at)}` : ''}{r.occurrences.length > 1 ? ` · ${r.occurrences.length} occurrences` : ''}</p>
                  <strong>{first?.room.name} · {first?.room.floor}</strong>
                  <div style={{ marginTop: 8 }}>{pending ? <Status tone="warning">Demande non confirmée — en attente de validation</Status> : first && <StatusOf map={OCC_STATUS} value={first.status} />}</div>
                  <small>Référence {r.reference} · Africa/Libreville</small>
                  <div className="row-actions">
                    <Button variant="secondary" icon="download" busy={pdf.busy === 'reservation_confirmation'} onClick={() => pdf.run('reservation_confirmation', { reservation_id: r.id }, 'Confirmation PDF01')}>Confirmation PDF01</Button>
                    <Button icon="eye" onClick={() => navigate(`/reservations/${r.id}`)}>Ouvrir la réservation</Button>
                  </div>
                </div>
                {result.skipped.length > 0 && (
                  <div style={{ marginTop: 12 }}>
                    <Notice tone="warning">
                      <strong>{result.skipped.length} occurrence(s) non créée(s) (mode partiel)</strong>
                      <ul style={{ margin: '6px 0 0 16px' }}>{result.skipped.map((s) => <li key={s.start_at}>{slotLabel(s.start_at, s.end_at)}</li>)}</ul>
                    </Notice>
                  </div>
                )}
              </>
            )
          })()}
        </div>
        <footer className="flow-footer">
          {step < 3 ? (
            <>
              <Button variant="secondary" onClick={step ? () => { setStep(step - 1); setServerError(null) } : () => navigate(-1)}>{step ? 'Retour' : 'Annuler'}</Button>
              <div className="footer-note"><Icon name="shield" size={14} /> Action tracée dans le journal · saisie conservée en cas d’erreur</div>
              <div className="spacer" />
              <Button onClick={next} busy={checking || submitting}
                disabled={(step === 1 && !f.room_id) || (step === 2 && (!check || !!blocking || capacityKo))}>
                {step === 0 ? 'Continuer' : step === 1 ? 'Vérifier la disponibilité' : check?.approval_mode === 'manual' ? 'Soumettre la demande' : 'Confirmer la réservation'}
                <Icon name="arrow" size={15} />
              </Button>
            </>
          ) : (
            <>
              <Button variant="secondary" onClick={() => navigate('/mes-reservations')}>Mes réservations</Button>
              <div className="spacer" />
              <Button icon="plus" onClick={reset}>Nouvelle réservation</Button>
            </>
          )}
        </footer>
      </section>
      {step === 2 && f.date && <p className="muted small" style={{ marginTop: 8 }}>Première occurrence : {fmtDate(`${f.date}T12:00:00+01:00`)} · {TZ_LABEL}.</p>}
    </>
  )
}
