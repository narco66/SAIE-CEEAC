import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { api, ApiError, type Paginated } from '../../lib/api'
import { fmtTime, localParts, toIsoFromLocal, todayLocal } from '../../lib/format'
import { Button, Field, Icon, Modal, Notice } from '../../components/ui'
import { ensureArray, slotLabel, TZ_LABEL, type Occurrence, type Reservation, type Room } from './shared'

/**
 * Modification ou déplacement d'une occurrence (SAL-07, V28, V29). Le serveur rejoue tous les contrôles ;
 * un échec laisse l'occurrence initiale intacte. « Les suivantes » applique la même salle et le même horaire à chaque occurrence.
 */
export function OccurrenceEditor({ reservation, occurrence, mode, onClose, onDone }: {
  reservation: Reservation; occurrence: Occurrence; mode: 'move' | 'modify'; onClose: () => void; onDone: (r: Reservation) => void
}) {
  const start = localParts(occurrence.starts_at)
  const end = localParts(occurrence.ends_at)
  const [roomId, setRoomId] = useState(occurrence.room.id)
  const [date, setDate] = useState(start.date)
  const [from, setFrom] = useState(start.time)
  const [to, setTo] = useState(end.time)
  const [reason, setReason] = useState('')
  const [scope, setScope] = useState<'this' | 'following'>('this')
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<ApiError | null>(null)
  const [progress, setProgress] = useState<string | null>(null)
  const rooms = useQuery({
    queryKey: ['rooms', 'editor', reservation.participant_count],
    queryFn: ({ signal }) => api.get<Paginated<Room>>('/rooms', { capacity: reservation.participant_count, per_page: 500 }, signal).then((r) => r.data),
  })
  const following = reservation.occurrences
    .filter((o) => o.starts_at >= occurrence.starts_at && ['pending', 'confirmed'].includes(o.status))
    .sort((a, b) => a.starts_at.localeCompare(b.starts_at))
  const series = following.length > 1
  const reasonRequired = mode === 'move'
  const invalid = !from || !to || to <= from || (reasonRequired && reason.trim().length < 5) || (scope === 'this' && !date)

  const submit = async () => {
    setBusy(true)
    setError(null)
    setProgress(null)
    try {
      // Série : une seule transaction côté serveur ; un conflit sur une date laisse toute la série inchangée (SAL-06).
      const r = scope === 'following' && following.length > 1
        ? await api.post<{ data: Reservation }>(`/occurrences/${occurrence.id}/modify-following`, { room_id: roomId, time_from: from, time_to: to, reason: reason.trim() || null, expected_version: occurrence.version })
        : await api.patch<{ data: Reservation }>(`/occurrences/${occurrence.id}`, { room_id: roomId, start_at: toIsoFromLocal(date, from), end_at: toIsoFromLocal(date, to), reason: reason.trim() || null, expected_version: occurrence.version })
      onDone(r.data)
      onClose()
    } catch (e) {
      const err = e instanceof ApiError ? e : new ApiError(500, { message: String(e) })
      setError(err)
      if (scope === 'following' && following.length > 1) setProgress(`Aucune des ${following.length} occurrences n’a été modifiée : la série est conservée à l’identique.`)
    } finally {
      setBusy(false)
    }
  }

  const conflicts = ensureArray(error?.details.conflicts as { start_at: string; end_at: string; unavailable_from?: string; unavailable_to?: string; reason?: string }[] | undefined)
  return (
    <Modal title={mode === 'move' ? 'Déplacer la réservation' : 'Modifier l’occurrence'} kicker={reservation.reference} onClose={onClose} wide footer={<>
      <Button variant="secondary" onClick={onClose}>Annuler</Button>
      <Button busy={busy} disabled={invalid} onClick={submit}>{mode === 'move' ? 'Déplacer' : scope === 'following' ? `Modifier ${following.length} occurrence(s)` : 'Enregistrer la modification'}</Button>
    </>}>
      <p className="small" style={{ marginBottom: 12 }}>Créneau actuel : <strong>{slotLabel(occurrence.starts_at, occurrence.ends_at)}</strong> · {occurrence.room.name ?? ''} · {TZ_LABEL}</p>
      {series && mode === 'modify' && (
        <div className="target-list" style={{ marginBottom: 12 }} role="radiogroup" aria-label="Portée de la modification">
          <label><input type="radio" name="scope" checked={scope === 'this'} onChange={() => setScope('this')} /><span><b>Cette occurrence uniquement</b>{slotLabel(occurrence.starts_at, occurrence.ends_at)}</span></label>
          <label><input type="radio" name="scope" checked={scope === 'following'} onChange={() => setScope('following')} /><span><b>Cette occurrence et les suivantes</b>{following.length} occurrence(s) : même salle et même horaire, chaque date conservée</span></label>
        </div>
      )}
      <div className="form-grid">
        <Field label="Salle" required error={error?.field('room_id')} wide>
          <select value={roomId} onChange={(e) => setRoomId(e.target.value)} disabled={rooms.isLoading}>
            {!rooms.data?.some((r) => r.id === occurrence.room.id) && <option value={occurrence.room.id}>{occurrence.room.name ?? 'Salle actuelle'}</option>}
            {(rooms.data ?? []).map((r) => <option key={r.id} value={r.id}>{r.name} · {r.floor?.label ?? ''} · {r.capacity} places</option>)}
          </select>
        </Field>
        {scope === 'this' && <Field label="Date" required><input type="date" value={date} min={todayLocal()} onChange={(e) => setDate(e.target.value)} /></Field>}
        <Field label="Début" required error={error?.field('start_at')} help={TZ_LABEL}><input type="time" step={900} value={from} onChange={(e) => setFrom(e.target.value)} /></Field>
        <Field label="Fin" required error={error?.field('end_at')}><input type="time" step={900} value={to} onChange={(e) => setTo(e.target.value)} aria-invalid={!!to && to <= from} /></Field>
        <Field label={mode === 'move' ? 'Motif du déplacement' : 'Motif (obligatoire pour un déplacement par le gestionnaire)'} required={reasonRequired} error={error?.field('reason')} help="Communiqué à l’organisateur et inscrit au journal" wide>
          <textarea value={reason} onChange={(e) => setReason(e.target.value)} maxLength={500} />
        </Field>
      </div>
      {error && (
        <div style={{ marginTop: 12 }}>
          <Notice tone="danger">
            <strong>{error.message}</strong>
            {progress && <p style={{ marginTop: 4 }}>{progress}</p>}
            {conflicts.length > 0 && <ul style={{ margin: '6px 0 0 16px' }}>{conflicts.map((c, i) => <li key={i}>{c.reason ?? 'Indisponible'} : {c.unavailable_from ? `${fmtTime(c.unavailable_from)}–${fmtTime(c.unavailable_to)}` : slotLabel(c.start_at, c.end_at)}</li>)}</ul>}
            {error.code === 'VERSION_CONFLICT' && <p style={{ marginTop: 4 }}>Fermez la fenêtre pour recharger la version courante.</p>}
          </Notice>
        </div>
      )}
      <div className="footer-note" style={{ marginTop: 12 }}><Icon name="shield" size={14} /> Contrôle de disponibilité, capacité, horaires et tampons effectué par le serveur</div>
    </Modal>
  )
}
