import { useState } from 'react'
import { useQueryClient } from '@tanstack/react-query'
import { api, ApiError } from '../../lib/api'
import { useData } from '../../lib/hooks'
import { Button, ConfirmDialog, EmptyState, ErrorState, Field, Loading, Modal, Notice, Status, useToast } from '../../components/ui'
import { useReferentials } from './shared'

interface MobileResource { id: string; code: string; name: string; type: string; status: 'active' | 'inactive'; manager_id: string | null; manager: { id: string; name: string } | null }

/**
 * Ressources mobiles (REF-01, SAL-13) : vidéoprojecteurs, kits de visioconférence… réservables avec une salle.
 * Elles se modifient et se mettent hors service ; jamais supprimées, l'historique des réservations les référence.
 */
export default function MobileResources() {
  const query = useData<MobileResource[]>(['mobile-resources', 'all'], '/mobile-resources', { status: 'all' })
  const client = useQueryClient()
  const toast = useToast()
  const [editing, setEditing] = useState<MobileResource | 'new' | null>(null)
  const [toggling, setToggling] = useState<MobileResource | null>(null)
  const refresh = () => client.invalidateQueries({ queryKey: ['mobile-resources'] })

  return (
    <section className="panel">
      <div className="panel-title">
        <div><h2>Ressources mobiles</h2><p>Matériel prêté avec une salle et réservé avec elle</p></div>
        <Button icon="plus" onClick={() => setEditing('new')}>Nouvelle ressource</Button>
      </div>
      {query.isLoading ? <Loading /> : query.isError ? <ErrorState error={query.error} onRetry={() => query.refetch()} /> : !query.data?.length ? (
        <EmptyState title="Aucune ressource mobile" text="Déclarez le matériel prêté avec les salles (vidéoprojecteur, kit de visioconférence…)." />
      ) : (
        <table>
          <thead><tr><th>Code</th><th>Nom</th><th>Type</th><th>Responsable</th><th>État</th><th /></tr></thead>
          <tbody>
            {query.data.map((r) => (
              <tr key={r.id} className={r.status === 'inactive' ? 'inactive-row' : ''}>
                <td className="mono">{r.code}</td><td>{r.name}</td><td>{r.type}</td><td>{r.manager?.name ?? '—'}</td>
                <td>{r.status === 'active' ? <Status tone="success">En service</Status> : <Status tone="neutral">Hors service</Status>}</td>
                <td style={{ textAlign: 'right', whiteSpace: 'nowrap' }}>
                  <Button size="small" variant="secondary" onClick={() => setEditing(r)}>Modifier</Button>{' '}
                  <Button size="small" variant="secondary" onClick={() => setToggling(r)}>{r.status === 'active' ? 'Mettre hors service' : 'Remettre en service'}</Button>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      )}
      {editing && <ResourceForm resource={editing === 'new' ? null : editing} onClose={() => setEditing(null)} onSaved={refresh} />}
      {toggling && <ToggleDialog resource={toggling} onClose={() => setToggling(null)} onDone={() => { refresh(); toast.success(toggling.status === 'active' ? 'Ressource mise hors service' : 'Ressource remise en service') }} />}
    </section>
  )
}

function ToggleDialog({ resource, onClose, onDone }: { resource: MobileResource; onClose: () => void; onDone: () => void }) {
  const [affected, setAffected] = useState<number | null>(null)
  const deactivating = resource.status === 'active'
  return (
    <ConfirmDialog
      title={deactivating ? 'Mettre la ressource hors service' : 'Remettre la ressource en service'} object={`${resource.code} · ${resource.name}`} danger={deactivating}
      consequence={deactivating
        ? (affected ? `Elle est réservée pour ${affected} réunion(s) à venir : ces réservations sont conservées. Confirmez pour continuer.` : 'Elle ne sera plus proposée dans les nouvelles réservations.')
        : 'Elle redevient proposée dans les nouvelles réservations.'}
      confirmLabel={deactivating ? 'Mettre hors service' : 'Remettre en service'} reasonLabel="Motif" reasonRequired={deactivating}
      onClose={onClose}
      onConfirm={async (reason) => {
        try {
          await api.patch(`/mobile-resources/${resource.id}`, { status: deactivating ? 'inactive' : 'active', reason: reason || null, confirm_affected: affected !== null })
          onDone()
        } catch (e) {
          if (e instanceof ApiError && e.code === 'CHANGE_AFFECTS_BOOKINGS') {
            setAffected(Number(e.details.affected) || 0)
          }
          throw e
        }
      }}
    />
  )
}

function ResourceForm({ resource, onClose, onSaved }: { resource: MobileResource | null; onClose: () => void; onSaved: () => void }) {
  const refs = useReferentials()
  const toast = useToast()
  const [form, setForm] = useState({ code: resource?.code ?? '', name: resource?.name ?? '', type: resource?.type ?? '', manager_id: resource?.manager_id ?? '' })
  const [error, setError] = useState<ApiError | null>(null)
  const [busy, setBusy] = useState(false)
  const set = (k: keyof typeof form, v: string) => setForm({ ...form, [k]: v })
  const submit = async () => {
    setBusy(true)
    setError(null)
    try {
      const body = { ...form, manager_id: form.manager_id || null }
      if (resource) await api.patch(`/mobile-resources/${resource.id}`, body)
      else await api.post('/mobile-resources', body)
      onSaved()
      toast.success(resource ? 'Ressource mise à jour' : 'Ressource créée', form.name)
      onClose()
    } catch (e) {
      setError(e instanceof ApiError ? e : new ApiError(500, { message: String(e) }))
    } finally {
      setBusy(false)
    }
  }
  return (
    <Modal title={resource ? 'Modifier la ressource' : 'Nouvelle ressource mobile'} kicker="SALLES" onClose={onClose} footer={<>
      <Button variant="secondary" onClick={onClose}>Annuler</Button><Button icon="check" busy={busy} onClick={submit}>Enregistrer</Button>
    </>}>
      <div className="form-grid">
        <Field label="Code" required error={error?.field('code')}><input value={form.code} maxLength={40} onChange={(e) => set('code', e.target.value.toUpperCase())} /></Field>
        <Field label="Nom" required error={error?.field('name')}><input value={form.name} maxLength={120} onChange={(e) => set('name', e.target.value)} /></Field>
        <Field label="Type" required error={error?.field('type')} help="Ex. vidéoprojecteur, visioconférence, sonorisation"><input value={form.type} maxLength={40} onChange={(e) => set('type', e.target.value)} /></Field>
        <Field label="Responsable" error={error?.field('manager_id')}>
          <select value={form.manager_id} onChange={(e) => set('manager_id', e.target.value)}>
            <option value="">— Aucun —</option>
            {(refs.data?.users ?? []).map((u) => <option key={u.id} value={u.id}>{u.name}</option>)}
          </select>
        </Field>
      </div>
      {error && !Object.keys(error.errors).length && <Notice tone="danger">{error.message}</Notice>}
    </Modal>
  )
}
