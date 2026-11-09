import { useState } from 'react'
import { useQueryClient } from '@tanstack/react-query'
import { api, ApiError } from '../../lib/api'
import { useList } from '../../lib/hooks'
import { Button, Field, Modal, Notice, useToast } from '../../components/ui'

interface PlaylistRow { id: string; name: string; status?: string; items: { content_id: string }[] }

/**
 * §12-a : affectation directe d'une liste de lecture à un écran ou à un groupe. Le serveur crée un « programme permanent »
 * (campagne liée à la liste, priorité « contenus permanents » par défaut) soumis aux contrôles habituels.
 */
export default function AssignPlaylistDialog({ target, label, onClose }: { target: { type: 'screen' | 'group'; id: string }; label: string; onClose: () => void }) {
  const client = useQueryClient()
  const toast = useToast()
  const lists = useList<PlaylistRow>('playlists', '/playlists', {}, 50)
  const [playlist, setPlaylist] = useState('')
  const [priority, setPriority] = useState(10)
  const [until, setUntil] = useState('')
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<ApiError | null>(null)
  const submit = async () => {
    setBusy(true)
    setError(null)
    try {
      const res = await api.post<{ data: { reference: string; status: string } }>(`/playlists/${playlist}/assign`, {
        targets: [target], priority, ...(until ? { ends_at: new Date(`${until}T23:59:00`).toISOString() } : {}),
      })
      toast.success('Liste affectée', `Programme permanent ${res.data.reference} (${res.data.status === 'active' || res.data.status === 'scheduled' ? 'publié' : res.data.status})`)
      client.invalidateQueries({ queryKey: ['campaigns'] })
      client.invalidateQueries({ queryKey: ['screen-preview'] })
      onClose()
    } catch (e) {
      setError(e instanceof ApiError ? e : null)
      toast.error(e)
    } finally {
      setBusy(false)
    }
  }
  return (
    <Modal title="Affecter une liste de lecture" kicker={label} onClose={onClose} footer={<>
      <Button variant="secondary" onClick={onClose}>Annuler</Button>
      <Button icon="check" busy={busy} disabled={!playlist} onClick={submit}>Affecter et publier</Button>
    </>}>
      <p>La liste devient le programme permanent de la cible : elle passe quand aucune campagne plus prioritaire ne s’applique. Les contenus doivent être approuvés et compatibles avec la zone.</p>
      <div className="form-grid">
        <Field label="Liste de lecture" required error={error?.field('playlist_id')}>
          <select value={playlist} onChange={(e) => setPlaylist(e.target.value)}>
            <option value="">Choisir…</option>
            {lists.items.filter((p) => p.status !== 'archived').map((p) => <option key={p.id} value={p.id}>{p.name} ({p.items.length})</option>)}
          </select>
        </Field>
        <Field label="Priorité" error={error?.field('priority')} help="1 à 19 : contenus permanents (par défaut 10)">
          <input type="number" min={1} max={99} value={priority} onChange={(e) => setPriority(Number(e.target.value))} />
        </Field>
        <Field label="Jusqu’au (facultatif)" error={error?.field('ends_at')} help="Vide : un an">
          <input type="date" value={until} onChange={(e) => setUntil(e.target.value)} />
        </Field>
      </div>
      {error && !Object.keys(error.errors).length && <Notice tone="danger">{error.message}</Notice>}
    </Modal>
  )
}
