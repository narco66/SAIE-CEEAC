import { useState } from 'react'
import { useNavigate } from 'react-router'
import { useQueryClient } from '@tanstack/react-query'
import { api, ApiError } from '../../lib/api'
import { Button, Field, Modal, Notice, useToast } from '../../components/ui'
import { useReferentials } from '../display/shared'

/**
 * Création d'une salle (§6 : « créer les salles et espaces »), réservée par le serveur à l'administrateur fonctionnel.
 * Les règles de réservation détaillées se règlent ensuite dans la fiche de la salle.
 */
export default function NewRoomDialog({ onClose }: { onClose: () => void }) {
  const refs = useReferentials()
  const client = useQueryClient()
  const navigate = useNavigate()
  const toast = useToast()
  const [form, setForm] = useState({ code: '', name: '', floor_id: '', capacity: '10', approval_mode: 'manual', manager_id: '', location_text: '' })
  const [error, setError] = useState<ApiError | null>(null)
  const [busy, setBusy] = useState(false)
  const set = (k: keyof typeof form, v: string) => setForm({ ...form, [k]: v })
  const submit = async () => {
    setBusy(true)
    setError(null)
    try {
      const res = await api.post<{ data: { id: string; name: string } }>('/rooms', { ...form, capacity: Number(form.capacity), manager_id: form.manager_id || null, location_text: form.location_text || null })
      await client.invalidateQueries({ queryKey: ['rooms'] })
      await client.invalidateQueries({ queryKey: ['referentials'] })
      toast.success('Salle créée', res.data.name)
      onClose()
      navigate(`/salles/${res.data.id}`)
    } catch (e) {
      setError(e instanceof ApiError ? e : new ApiError(500, { message: String(e) }))
    } finally {
      setBusy(false)
    }
  }
  return (
    <Modal title="Nouvelle salle" kicker="LOCALISATION" onClose={onClose} footer={<>
      <Button variant="secondary" onClick={onClose}>Annuler</Button><Button icon="check" busy={busy} onClick={submit}>Créer la salle</Button>
    </>}>
      <div className="form-grid">
        <Field label="Code" required error={error?.field('code')}><input value={form.code} maxLength={40} onChange={(e) => set('code', e.target.value.toUpperCase())} /></Field>
        <Field label="Nom" required error={error?.field('name')}><input value={form.name} maxLength={120} onChange={(e) => set('name', e.target.value)} /></Field>
        <Field label="Niveau" required error={error?.field('floor_id')}>
          <select value={form.floor_id} onChange={(e) => set('floor_id', e.target.value)}>
            <option value="">— Choisir —</option>
            {(refs.data?.floors ?? []).map((f) => <option key={f.id} value={f.id}>{f.building?.name ? `${f.building.name} · ` : ''}{f.label}</option>)}
          </select>
        </Field>
        <Field label="Capacité (personnes)" required error={error?.field('capacity')}><input type="number" min={1} max={2000} value={form.capacity} onChange={(e) => set('capacity', e.target.value)} /></Field>
        <Field label="Validation des réservations" error={error?.field('approval_mode')}>
          <select value={form.approval_mode} onChange={(e) => set('approval_mode', e.target.value)}><option value="manual">Par un gestionnaire</option><option value="auto">Automatique</option></select>
        </Field>
        <Field label="Gestionnaire" required={form.approval_mode === 'manual'} error={error?.field('manager_id')} help="Reçoit et valide les demandes de réservation">
          <select value={form.manager_id} onChange={(e) => set('manager_id', e.target.value)}>
            <option value="">— Aucun —</option>
            {(refs.data?.users ?? []).map((u) => <option key={u.id} value={u.id}>{u.name}</option>)}
          </select>
        </Field>
        <Field label="Situation" error={error?.field('location_text')} help="Ex. « aile B, porte 204 »"><input value={form.location_text} maxLength={200} onChange={(e) => set('location_text', e.target.value)} /></Field>
      </div>
      {error && !Object.keys(error.errors).length && <Notice tone="danger">{error.message}</Notice>}
    </Modal>
  )
}
