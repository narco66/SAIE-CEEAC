import { useState } from 'react'
import { useNavigate } from 'react-router'
import { useQueryClient } from '@tanstack/react-query'
import { api, ApiError } from '../../lib/api'
import { Button, Field, Modal, Notice, useToast } from '../../components/ui'
import { useAllScreens } from './shared'

/**
 * §9.2 : réaffectation d'un lecteur à un autre écran sans révocation. Le lecteur garde son identité ; l'écran actuel
 * redevient « en attente de configuration ». Si l'écran cible a déjà un lecteur, son remplacement doit être confirmé.
 */
export default function ReassignDeviceDialog({ deviceId, deviceCode, screenId, onClose }: { deviceId: string; deviceCode: string; screenId: string; onClose: () => void }) {
  const screens = useAllScreens()
  const client = useQueryClient()
  const navigate = useNavigate()
  const toast = useToast()
  const [target, setTarget] = useState('')
  const [reason, setReason] = useState('')
  const [replace, setReplace] = useState(false)
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<ApiError | null>(null)
  const candidates = (screens.data?.data ?? []).filter((s) => s.id !== screenId && ['active', 'maintenance'].includes(s.status))
  const chosen = candidates.find((s) => s.id === target)
  const submit = async () => {
    setBusy(true)
    setError(null)
    try {
      const res = await api.post<{ message?: string }>(`/devices/${deviceId}/reassign`, { screen_id: target, reason, replace })
      toast.success('Lecteur réaffecté', res.message)
      await client.invalidateQueries({ queryKey: ['screen'] })
      await client.invalidateQueries({ queryKey: ['screens'] })
      onClose()
      navigate(`/affichage/ecrans/${target}`)
    } catch (e) {
      setError(e instanceof ApiError ? e : new ApiError(500, { message: String(e) }))
    } finally {
      setBusy(false)
    }
  }
  return (
    <Modal title="Réaffecter le lecteur" kicker={deviceCode} onClose={onClose} footer={<>
      <Button variant="secondary" onClick={onClose}>Annuler</Button>
      <Button icon="check" busy={busy} disabled={!target || reason.trim().length < 5 || (!!chosen?.device && !replace)} onClick={submit}>Réaffecter</Button>
    </>}>
      <p className="small">Le lecteur garde son identité et reçoit le programme du nouvel écran à sa prochaine synchronisation. L’écran actuel n’aura plus de lecteur ; les commandes en attente sont annulées.</p>
      <Field label="Nouvel écran" required error={error?.field('screen_id')}>
        <select value={target} onChange={(e) => { setTarget(e.target.value); setReplace(false) }}>
          <option value="">— Choisir —</option>
          {candidates.map((s) => <option key={s.id} value={s.id}>{s.code} · {s.name}{s.device ? ' (a déjà un lecteur)' : ''}</option>)}
        </select>
      </Field>
      {chosen?.device && (
        <label className="check-label"><input type="checkbox" checked={replace} onChange={(e) => setReplace(e.target.checked)} /> Révoquer le lecteur {chosen.device.code} actuellement associé à {chosen.code}</label>
      )}
      <Field label="Motif" required error={error?.field('reason')}><input value={reason} maxLength={300} onChange={(e) => setReason(e.target.value)} placeholder="Ex. boîtier déplacé au hall" /></Field>
      {error && !Object.keys(error.errors).length && <Notice tone="danger">{error.message}</Notice>}
    </Modal>
  )
}
