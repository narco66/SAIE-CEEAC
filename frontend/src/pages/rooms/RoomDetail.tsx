import { useState } from 'react'
import { useNavigate, useParams } from 'react-router'
import { useQueryClient } from '@tanstack/react-query'
import { api, ApiError, fileUrl } from '../../lib/api'
import { useAuth } from '../../lib/auth'
import { useData } from '../../lib/hooks'
import { addDays, fmtDate, fmtDateTime, toIsoFromLocal, todayLocal } from '../../lib/format'
import { Button, ConfirmDialog, EmptyState, ErrorState, Field, Icon, Kv, Loading, Notice, PageHeading, Status, Tabs, useToast } from '../../components/ui'
import {
  APPROVAL_LABEL, availabilityTone, CalendarLegend, DayTrack, fmtDayMonth, HourScale, mondayOf, RoomDisplayPreview, Section, TZ_LABEL,
  useOccurrenceIndex, useReferentials, type Busy, type Room,
} from './shared'

type Tab = 'presentation' | 'availability' | 'equipment' | 'rules' | 'closures' | 'checklists' | 'panel'

/** V31 Fiche et édition salle. */
export default function RoomDetail() {
  const { id = '' } = useParams()
  const navigate = useNavigate()
  const { can } = useAuth()
  const [tab, setTab] = useState<Tab>('presentation')
  const query = useData<Room>(['room', id], `/rooms/${id}`)
  if (query.isLoading) return <Loading />
  if (query.isError || !query.data) return <ErrorState error={query.error} onRetry={() => query.refetch()} />
  const room = query.data

  return (
    <>
      <PageHeading
        title={room.name}
        description={`${room.code} · ${room.floor?.label ?? 'Niveau non renseigné'} · ${room.capacity} places`}
        actions={<>
          <Button variant="secondary" icon="calendar" onClick={() => navigate('/salles/calendrier')}>Calendrier</Button>
          <a className="button secondary" href={fileUrl(`/rooms/${room.id}/calendar.ics`)} download title="Réservations de la semaine passée aux 8 semaines à venir ; les réunions confidentielles restent « Réservée » (SAL-11)"><Icon name="download" size={16} /><span>Exporter (.ics)</span></a>
          {can('reservations.create') && <Button icon="plus" disabled={room.status !== 'active'} onClick={() => navigate(`/reservations/nouvelle?room_id=${room.id}`)}>Réserver cette salle</Button>}
        </>}
      />
      <Tabs value={tab} onChange={setTab} items={[
        { value: 'presentation', label: 'Présentation' },
        { value: 'availability', label: 'Disponibilités' },
        { value: 'equipment', label: 'Équipements' },
        { value: 'rules', label: 'Règles' },
        { value: 'closures', label: 'Fermetures', count: room.blocks?.length ?? null },
        { value: 'checklists', label: 'Listes de contrôle (P1)' },
        { value: 'panel', label: 'Aperçu du panneau' },
      ]} />
      {tab === 'presentation' && <Presentation room={room} />}
      {tab === 'availability' && <Availability room={room} />}
      {tab === 'equipment' && <EquipmentTab room={room} />}
      {tab === 'rules' && <Rules room={room} />}
      {tab === 'closures' && <Closures room={room} />}
      {tab === 'checklists' && <Checklists />}
      {tab === 'panel' && <PanelPreview room={room} />}
    </>
  )
}

function Presentation({ room }: { room: Room }) {
  return (
    <div className="grid-2">
      <section className="panel">
        <div className="panel-title"><div><h2>Présentation</h2><p>Disponibilité à l’instant présent ({TZ_LABEL})</p></div><Status tone={availabilityTone(room.availability?.state)}>{room.availability?.label ?? '—'}</Status></div>
        <div className="panel-body">
          <Kv items={[
            ['Code', room.code],
            ['Niveau', room.floor?.label ?? '—'],
            ['Localisation', room.location_text || '—'],
            ['Capacité', `${room.capacity} personnes`],
            ['Gestionnaire', room.manager?.name ?? 'Non désigné'],
            ['Mode de validation', APPROVAL_LABEL[room.approval_mode] ?? room.approval_mode],
            ['État', room.status === 'active' ? <Status tone="success">Réservable</Status> : <Status tone="neutral">Inactive</Status>],
            ['Horaires', `${room.open_from}–${room.open_to}`],
          ]} />
        </div>
      </section>
      <section className="panel">
        <div className="panel-title"><div><h2>Panneaux et équipements</h2><p>Écrans de porte associés (T03)</p></div></div>
        <div className="panel-body">
          {room.photos?.filter((p) => /^(https?:|\/)/.test(p)).slice(0, 1).map((p) => <img key={p} src={p} alt={`Photo de ${room.name}`} style={{ width: '100%', borderRadius: 8, marginBottom: 10 }} />)}
          <p className="small" style={{ marginBottom: 8 }}><strong>Écrans :</strong> {room.screens?.length ? room.screens.map((s) => `${s.name} (${s.code})`).join(', ') : 'aucun panneau associé'}</p>
          <div className="chip-row">{room.equipment.length ? room.equipment.map((e) => <span className="chip" key={e.id}>{e.name}{e.quantity && e.quantity > 1 ? ` ×${e.quantity}` : ''}</span>) : <span className="muted small">Aucun équipement déclaré</span>}</div>
        </div>
      </section>
    </div>
  )
}

function Availability({ room }: { room: Room }) {
  const navigate = useNavigate()
  const { can } = useAuth()
  const index = useOccurrenceIndex()
  const [week, setWeek] = useState(mondayOf(todayLocal()))
  const days = Array.from({ length: 7 }, (_, i) => addDays(week, i))
  const range = { from: toIsoFromLocal(week, '00:00'), to: toIsoFromLocal(addDays(week, 7), '00:00') }
  const query = useData<{ busy: Busy[]; room: { open_from: string; open_to: string } }>(['room-availability', room.id, range], `/rooms/${room.id}/availability`, range)
  const today = todayLocal()
  const open = (b: Busy) => {
    const rid = b.reservation_id ?? (b.occurrence_id ? index.data?.get(b.occurrence_id) : undefined)
    if (rid) navigate(`/reservations/${rid}`)
  }
  return (
    <section className="panel room-calendar-panel">
      <div className="calendar-top">
        <div className="date-nav">
          <button type="button" onClick={() => setWeek(addDays(week, -7))} aria-label="Semaine précédente"><Icon name="chevron" size={16} /></button>
          <h2>Semaine du {fmtDayMonth(days[0])} au {fmtDayMonth(days[6])}</h2>
          <button type="button" onClick={() => setWeek(addDays(week, 7))} aria-label="Semaine suivante"><Icon name="chevron" size={16} /></button>
        </div>
        <Button variant="secondary" onClick={() => setWeek(mondayOf(today))}>Semaine courante</Button>
      </div>
      <CalendarLegend />
      {query.isLoading ? <Loading /> : query.isError || !query.data ? <ErrorState error={query.error} onRetry={() => query.refetch()} /> : (
        <div className="cal-scroll">
          <div className="day-grid">
            <div className="day-head" aria-hidden="true" />
            <HourScale />
            {days.map((d) => (
              <div key={d} style={{ display: 'contents' }}>
                <div className="day-label">{fmtDayMonth(d)}{d === today && <small>Aujourd’hui</small>}</div>
                <DayTrack busy={query.data!.busy} day={d} openFrom={room.open_from} openTo={room.open_to} label={room.name} onOpen={open}
                  onFree={can('reservations.create') && room.status === 'active' && d >= today ? (s, e) => navigate(`/reservations/nouvelle?room_id=${room.id}&date=${d}&start=${s}&end=${e}`) : undefined} />
              </div>
            ))}
          </div>
        </div>
      )}
      <p className="muted small" style={{ padding: '10px 17px' }}>Préparation {room.prep_minutes} min avant et remise en état {room.cleanup_minutes} min après chaque réunion · {TZ_LABEL}</p>
    </section>
  )
}

/** Gère le 409 CAPACITY_AFFECTS_BOOKINGS : la liste des réservations affectées est affichée avant confirmation. */
function useRoomPatch(room: Room) {
  const client = useQueryClient()
  const toast = useToast()
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<ApiError | null>(null)
  const [affected, setAffected] = useState<{ reference: string; starts_at: string; participants: number }[] | null>(null)
  const [pending, setPending] = useState<Record<string, unknown> | null>(null)
  const submit = async (body: Record<string, unknown>, confirm = false): Promise<boolean> => {
    setBusy(true)
    setError(null)
    try {
      await api.patch(`/rooms/${room.id}`, confirm ? { ...body, confirm_affected: true } : body)
      setAffected(null)
      setPending(null)
      await client.invalidateQueries({ queryKey: ['room', room.id] })
      client.invalidateQueries({ queryKey: ['rooms'] })
      toast.success('Salle mise à jour')
      return true
    } catch (e) {
      const err = e instanceof ApiError ? e : new ApiError(500, { message: String(e) })
      if (err.code === 'CAPACITY_AFFECTS_BOOKINGS') {
        setAffected((err.details.affected as typeof affected) ?? [])
        setPending(body)
      } else setError(err)
      return false
    } finally {
      setBusy(false)
    }
  }
  return { submit, busy, error, affected, pending, cancelAffected: () => { setAffected(null); setPending(null) } }
}

function EquipmentTab({ room }: { room: Room }) {
  const refs = useReferentials()
  const patch = useRoomPatch(room)
  const [editing, setEditing] = useState(false)
  const [values, setValues] = useState<Record<string, number>>(() => Object.fromEntries(room.equipment.map((e) => [e.id, e.quantity ?? 1])))
  return (
    <Section title="Équipements" description="Équipements fixes de la salle" actions={room.can_manage && !editing ? <Button variant="secondary" icon="edit" onClick={() => setEditing(true)}>Modifier</Button> : undefined}>
      <div className="panel-body">
        {!editing ? (
          room.equipment.length === 0 ? <EmptyState title="Aucun équipement déclaré" /> : (
            <table><thead><tr><th>Équipement</th><th>Code</th><th>Quantité</th></tr></thead>
              <tbody>{room.equipment.map((e) => <tr key={e.id}><td>{e.name}</td><td className="mono">{e.code}</td><td>{e.quantity ?? 1}</td></tr>)}</tbody></table>
          )
        ) : refs.isLoading ? <Loading /> : refs.isError ? <ErrorState error={refs.error} /> : (
          <>
            <div className="check-grid">
              {(refs.data?.equipment ?? []).filter((e) => e.is_active !== false || values[e.id]).map((e) => (
                <label key={e.id}>
                  <input type="checkbox" checked={e.id in values} onChange={(ev) => setValues((v) => { const n = { ...v }; if (ev.target.checked) n[e.id] = 1; else delete n[e.id]; return n })} />
                  <span style={{ flex: 1 }}>{e.name}</span>
                  {e.id in values && <input className="input" type="number" min={1} value={values[e.id]} aria-label={`Quantité de ${e.name}`} style={{ width: 64, minHeight: 28 }} onChange={(ev) => setValues((v) => ({ ...v, [e.id]: Math.max(1, Number(ev.target.value) || 1) }))} />}
                </label>
              ))}
            </div>
            {patch.error && <div style={{ marginTop: 10 }}><Notice tone="danger">{patch.error.message}</Notice></div>}
            <div className="form-actions">
              <Button variant="secondary" onClick={() => setEditing(false)}>Annuler</Button>
              <Button busy={patch.busy} onClick={async () => { if (await patch.submit({ equipment: Object.entries(values).map(([id, quantity]) => ({ id, quantity })) })) setEditing(false) }}>Enregistrer</Button>
            </div>
          </>
        )}
      </div>
    </Section>
  )
}

interface RulesForm {
  name: string; location_text: string; capacity: string; open_from: string; open_to: string; prep_minutes: string; cleanup_minutes: string
  min_minutes: string; max_minutes: string; lead_days: string; approval_mode: 'auto' | 'manual'; pending_hold_hours: string; auto_release_enabled: boolean; status: string
}

function Rules({ room }: { room: Room }) {
  const patch = useRoomPatch(room)
  const [editing, setEditing] = useState(false)
  const initial: RulesForm = {
    name: room.name, location_text: room.location_text ?? '', capacity: String(room.capacity), open_from: room.open_from, open_to: room.open_to,
    prep_minutes: String(room.prep_minutes), cleanup_minutes: String(room.cleanup_minutes), min_minutes: String(room.min_minutes), max_minutes: String(room.max_minutes),
    lead_days: String(room.lead_days), approval_mode: room.approval_mode, pending_hold_hours: String(room.pending_hold_hours), auto_release_enabled: room.auto_release_enabled, status: room.status,
  }
  const [f, setF] = useState<RulesForm>(initial)
  const upd = <K extends keyof RulesForm>(k: K, v: RulesForm[K]) => setF((x) => ({ ...x, [k]: v }))
  const err = (k: string) => patch.error?.field(k)
  const body = () => ({
    name: f.name, location_text: f.location_text || null, capacity: Number(f.capacity), open_from: f.open_from, open_to: f.open_to,
    prep_minutes: Number(f.prep_minutes), cleanup_minutes: Number(f.cleanup_minutes), min_minutes: Number(f.min_minutes), max_minutes: Number(f.max_minutes),
    lead_days: Number(f.lead_days), approval_mode: f.approval_mode, pending_hold_hours: Number(f.pending_hold_hours), auto_release_enabled: f.auto_release_enabled, status: f.status,
  })
  const save = async (confirm = false) => {
    if (await patch.submit(confirm && patch.pending ? patch.pending : body(), confirm)) setEditing(false)
  }

  if (!editing) {
    return (
      <Section title="Règles de réservation" description="Contrôlées par le serveur à chaque demande et modification" actions={room.can_manage ? <Button variant="secondary" icon="edit" onClick={() => { setF(initial); setEditing(true) }}>Modifier</Button> : undefined}>
        <div className="panel-body">
          <Kv items={[
            ['Horaires d’ouverture', `${room.open_from}–${room.open_to} (${TZ_LABEL})`],
            ['Temps de préparation', `${room.prep_minutes} min avant`],
            ['Remise en état', `${room.cleanup_minutes} min après`],
            ['Durée', `de ${room.min_minutes} à ${room.max_minutes} min`],
            ['Délai maximal de réservation', `${room.lead_days} jour(s) à l’avance`],
            ['Mode de validation', APPROVAL_LABEL[room.approval_mode] ?? room.approval_mode],
            ['Délai de blocage d’une demande', `${room.pending_hold_hours} h (la demande non validée est libérée ensuite)`],
            ['Libération automatique (SAL-10)', room.auto_release_enabled ? 'Activée' : 'Désactivée — non-présentation déclarée manuellement'],
            ['Capacité', `${room.capacity} personnes`],
          ]} />
        </div>
      </Section>
    )
  }
  return (
    <Section title="Modifier la salle" description="Une réduction de capacité signale les réservations futures affectées.">
      <div className="panel-body">
        <div className="form-grid">
          <Field label="Nom" required error={err('name')}><input value={f.name} onChange={(e) => upd('name', e.target.value)} aria-invalid={!!err('name')} /></Field>
          <Field label="Localisation" error={err('location_text')}><input value={f.location_text} onChange={(e) => upd('location_text', e.target.value)} /></Field>
          <Field label="Capacité" required error={err('capacity')}><input type="number" min={1} value={f.capacity} onChange={(e) => upd('capacity', e.target.value)} aria-invalid={!!err('capacity')} /></Field>
          <Field label="État" error={err('status')}>
            <select value={f.status} onChange={(e) => upd('status', e.target.value)}><option value="active">Réservable</option><option value="inactive">Inactive</option></select>
          </Field>
          <Field label="Ouverture" required error={err('open_from')}><input type="time" value={f.open_from} onChange={(e) => upd('open_from', e.target.value)} aria-invalid={!!err('open_from')} /></Field>
          <Field label="Fermeture" required error={err('open_to')}><input type="time" value={f.open_to} onChange={(e) => upd('open_to', e.target.value)} aria-invalid={!!err('open_to')} /></Field>
          <Field label="Préparation (min)" error={err('prep_minutes')} help="0 à 240"><input type="number" min={0} max={240} value={f.prep_minutes} onChange={(e) => upd('prep_minutes', e.target.value)} /></Field>
          <Field label="Remise en état (min)" error={err('cleanup_minutes')} help="0 à 240"><input type="number" min={0} max={240} value={f.cleanup_minutes} onChange={(e) => upd('cleanup_minutes', e.target.value)} /></Field>
          <Field label="Durée minimale (min)" error={err('min_minutes')}><input type="number" min={15} max={600} value={f.min_minutes} onChange={(e) => upd('min_minutes', e.target.value)} /></Field>
          <Field label="Durée maximale (min)" error={err('max_minutes')}><input type="number" min={15} max={720} value={f.max_minutes} onChange={(e) => upd('max_minutes', e.target.value)} /></Field>
          <Field label="Délai maximal (jours)" error={err('lead_days')}><input type="number" min={1} max={365} value={f.lead_days} onChange={(e) => upd('lead_days', e.target.value)} /></Field>
          <Field label="Délai de blocage (heures)" error={err('pending_hold_hours')}><input type="number" min={1} max={168} value={f.pending_hold_hours} onChange={(e) => upd('pending_hold_hours', e.target.value)} /></Field>
          <Field label="Mode de validation" error={err('approval_mode')}>
            <select value={f.approval_mode} onChange={(e) => upd('approval_mode', e.target.value as 'auto' | 'manual')}><option value="auto">Confirmation automatique</option><option value="manual">Validation par le gestionnaire</option></select>
          </Field>
          <label className="check-label"><input type="checkbox" checked={f.auto_release_enabled} onChange={(e) => upd('auto_release_enabled', e.target.checked)} /> Libération automatique (SAL-10)</label>
        </div>
        {patch.error && <div style={{ marginTop: 12 }}><Notice tone="danger">{patch.error.message}</Notice></div>}
        <div className="form-actions">
          <Button variant="secondary" onClick={() => setEditing(false)}>Annuler</Button>
          <Button busy={patch.busy} onClick={() => save(false)}>Enregistrer</Button>
        </div>
      </div>
      {patch.affected && (
        <ConfirmDialog title="Réduction de capacité" object={`Nouvelle capacité : ${f.capacity} personnes`} danger
          consequence="Les réservations futures ci-dessous dépassent la nouvelle capacité. Elles sont conservées : prévenez les organisateurs ou déplacez-les depuis « À valider »."
          confirmLabel="Confirmer la réduction" onClose={patch.cancelAffected} onConfirm={async () => { await save(true) }}>
          <table style={{ marginTop: 12 }}>
            <thead><tr><th>Réservation</th><th>Début</th><th>Participants</th></tr></thead>
            <tbody>{patch.affected.map((a) => <tr key={a.reference + a.starts_at}><td className="mono">{a.reference}</td><td>{fmtDateTime(a.starts_at)}</td><td>{a.participants}</td></tr>)}</tbody>
          </table>
        </ConfirmDialog>
      )}
    </Section>
  )
}

function Closures({ room }: { room: Room }) {
  const client = useQueryClient()
  const toast = useToast()
  const today = todayLocal()
  const [form, setForm] = useState({ start_date: today, start_time: room.open_from, end_date: today, end_time: room.open_to, kind: 'technical', reason: '' })
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<ApiError | null>(null)
  const [removing, setRemoving] = useState<NonNullable<Room['blocks']>[number] | null>(null)
  const blocks = room.blocks ?? []
  const add = async () => {
    setBusy(true)
    setError(null)
    try {
      const r = await api.post<{ impacted_reservations: number; message: string | null }>(`/rooms/${room.id}/blocks`, {
        starts_at: toIsoFromLocal(form.start_date, form.start_time), ends_at: toIsoFromLocal(form.end_date, form.end_time), kind: form.kind, reason: form.reason,
      })
      toast.success('Fermeture enregistrée', r.message ?? undefined)
      setForm((f) => ({ ...f, reason: '' }))
      client.invalidateQueries({ queryKey: ['room', room.id] })
      client.invalidateQueries({ queryKey: ['rooms-calendar'] })
      client.invalidateQueries({ queryKey: ['room-availability', room.id] })
    } catch (e) {
      setError(e instanceof ApiError ? e : new ApiError(500, { message: String(e) }))
    } finally {
      setBusy(false)
    }
  }
  return (
    <>
      <Section title="Fermetures programmées" description="Périodes de maintenance ou d’indisponibilité institutionnelle à venir">
        {blocks.length === 0 ? <EmptyState title="Aucune fermeture à venir" /> : (
          <div className="table-scroll">
            <table>
              <thead><tr><th>Période</th><th>Nature</th><th>Motif</th>{room.can_manage && <th><span className="sr-only">Actions</span></th>}</tr></thead>
              <tbody>
                {blocks.map((b) => (
                  <tr key={b.id}>
                    <td>{fmtDateTime(b.starts_at)} → {fmtDateTime(b.ends_at)}</td>
                    <td><Status tone={b.kind === 'technical' ? 'danger' : 'warning'}>{b.kind === 'technical' ? 'Technique' : 'Institutionnelle'}</Status></td>
                    <td>{b.reason}</td>
                    {room.can_manage && <td><Button size="small" variant="secondary" icon="trash" onClick={() => setRemoving(b)}>Supprimer</Button></td>}
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </Section>
      {room.can_manage && (
        <Section title="Programmer une fermeture" description={`Saisie en ${TZ_LABEL}. Les réservations existantes qui chevauchent sont signalées.`}>
          <div className="panel-body">
            <div className="form-grid">
              <Field label="Date de début" required><input type="date" value={form.start_date} onChange={(e) => setForm({ ...form, start_date: e.target.value })} aria-invalid={!!error?.field('starts_at')} /></Field>
              <Field label="Heure de début" required error={error?.field('starts_at')}><input type="time" value={form.start_time} onChange={(e) => setForm({ ...form, start_time: e.target.value })} /></Field>
              <Field label="Date de fin" required><input type="date" value={form.end_date} min={form.start_date} onChange={(e) => setForm({ ...form, end_date: e.target.value })} aria-invalid={!!error?.field('ends_at')} /></Field>
              <Field label="Heure de fin" required error={error?.field('ends_at')}><input type="time" value={form.end_time} onChange={(e) => setForm({ ...form, end_time: e.target.value })} /></Field>
              <Field label="Nature" required error={error?.field('kind')}>
                <select value={form.kind} onChange={(e) => setForm({ ...form, kind: e.target.value })}><option value="technical">Technique (maintenance)</option><option value="institutional">Institutionnelle</option></select>
              </Field>
              <Field label="Motif" required error={error?.field('reason')} help="5 caractères minimum, inscrit au journal" wide>
                <input value={form.reason} onChange={(e) => setForm({ ...form, reason: e.target.value })} aria-invalid={!!error?.field('reason')} />
              </Field>
            </div>
            {error && !Object.keys(error.errors).length && <div style={{ marginTop: 10 }}><Notice tone="danger">{error.message}</Notice></div>}
            <div className="form-actions"><Button icon="plus" busy={busy} disabled={form.reason.trim().length < 5} onClick={add}>Programmer la fermeture</Button></div>
          </div>
        </Section>
      )}
      {removing && (
        <ConfirmDialog title="Supprimer la fermeture" object={`${fmtDateTime(removing.starts_at)} → ${fmtDateTime(removing.ends_at)}`} danger
          consequence="La salle redeviendra réservable sur cette période." confirmLabel="Supprimer la fermeture" onClose={() => setRemoving(null)}
          onConfirm={async () => {
            await api.delete(`/room-blocks/${removing.id}`)
            toast.success('Fermeture supprimée')
            client.invalidateQueries({ queryKey: ['room', room.id] })
            client.invalidateQueries({ queryKey: ['rooms-calendar'] })
          }} />
      )}
    </>
  )
}

function Checklists() {
  const navigate = useNavigate()
  const { can } = useAuth()
  return (
    <Section title="Listes de contrôle d’ouverture et de remise en état" description="Fonction P1 (SAL-14) — constats horodatés par réunion">
      <div className="panel-body">
        <Notice tone="info">
          Les listes de contrôle sont tenues pour chaque réunion par l’équipe disposant du pouvoir « prestations » : ouverture (avant la réunion) et remise en état (après).
          Chaque point coché est horodaté et attribué ; il figure sur la fiche de préparation PDF03.
        </Notice>
        {can('services.process') && <div className="form-actions"><Button variant="secondary" icon="check" onClick={() => navigate('/prestations')}>Ouvrir les prestations</Button></div>}
      </div>
    </Section>
  )
}

function PanelPreview({ room }: { room: Room }) {
  const today = todayLocal()
  const range = { from: toIsoFromLocal(today, '00:00'), to: toIsoFromLocal(addDays(today, 1), '00:00') }
  const query = useData<{ busy: Busy[] }>(['room-availability', room.id, range], `/rooms/${room.id}/availability`, range)
  return (
    <Section title="Aperçu du panneau de salle (T03)" description={`Réservations du ${fmtDate(new Date().toISOString())} · réunions confidentielles affichées « Réservée »`}>
      <div className="panel-body">
        {query.isLoading ? <Loading /> : query.isError || !query.data ? <ErrorState error={query.error} onRetry={() => query.refetch()} /> : (
          <RoomDisplayPreview room={room} busy={query.data.busy} floorLabel={room.floor?.label} />
        )}
        <p className="muted small" style={{ marginTop: 10 }}>
          Rendu indicatif à partir des données du jour. L’état réseau réel du panneau (« Statut non actualisé » en cas de perte de contact) est suivi dans la fiche de l’écran.
        </p>
      </div>
    </Section>
  )
}
