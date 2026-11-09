import { useState } from 'react'
import { useQuery, useQueryClient } from '@tanstack/react-query'
import { api, ApiError } from '../../lib/api'
import { fmtDateTime } from '../../lib/format'
import { Button, ErrorState, Field, Loading, Modal, Notice } from '../../components/ui'
import { useReferentials, type UserRef } from './shared'

interface Broadcast { id: string; created_at: string | null; message: string | null; structures: string[]; recipients_count: number; excluded_count: number; created_by: UserRef | null }

/** Historique des diffusions par courriel (DOC-09), visible du propriétaire du document. */
export function BroadcastHistory({ documentId }: { documentId: string }) {
  const query = useQuery({
    queryKey: ['document-broadcasts', documentId],
    queryFn: ({ signal }) => api.get<{ data: Broadcast[] }>(`/documents/${documentId}/broadcasts`, undefined, signal).then((r) => r.data),
  })
  return (
    <section className="panel">
      <div className="panel-title"><div><h2>Diffusions par courriel</h2><p>Lien authentifié, sans pièce jointe</p></div></div>
      <div className="panel-body">
        {query.isLoading ? <Loading /> : query.isError || !query.data ? <ErrorState error={query.error} onRetry={() => query.refetch()} /> : query.data.length === 0 ? (
          <p className="muted small">Aucune diffusion.</p>
        ) : (
          <div className="timeline-list">
            {query.data.map((b) => (
              <div key={b.id}>
                <time>{fmtDateTime(b.created_at)}</time>
                <span>
                  <strong className="small">{b.recipients_count} destinataire(s)</strong>{b.excluded_count > 0 && <small className="muted"> · {b.excluded_count} non habilité(s) écarté(s)</small>}
                  <small style={{ display: 'block' }}>{b.structures.join(', ')}</small>
                  <small className="muted" style={{ display: 'block' }}>{b.created_by?.name ?? 'Système'}</small>
                </span>
              </div>
            ))}
          </div>
        )}
      </div>
    </section>
  )
}

/** Diffusion par courriel d'un document publié à des structures (DOC-09) : seuls les agents habilités à le lire le reçoivent. */
export function BroadcastDialog({ document, onClose, onDone }: {
  document: { id: string; reference: string; title: string; classification: string }
  onClose: () => void
  onDone: (result: Broadcast) => void
}) {
  const refs = useReferentials()
  const client = useQueryClient()
  const [structures, setStructures] = useState<string[]>([])
  const [message, setMessage] = useState('')
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<ApiError | null>(null)
  const available = (refs.data?.structures ?? []).filter((s) => s.is_active !== false)

  const send = async () => {
    setBusy(true)
    setError(null)
    try {
      const res = await api.post<{ data: Broadcast }>(`/documents/${document.id}/broadcasts`, { structure_ids: structures, message: message.trim() || null })
      await client.invalidateQueries({ queryKey: ['document-broadcasts', document.id] })
      onDone(res.data)
      onClose()
    } catch (e) {
      setError(e instanceof ApiError ? e : new ApiError(500, { message: String(e) }))
    } finally {
      setBusy(false)
    }
  }

  return (
    <Modal title="Diffuser par courriel" kicker={document.reference} wide onClose={onClose} footer={<>
      <Button variant="secondary" onClick={onClose}>Annuler</Button>
      <Button icon="check" busy={busy} disabled={structures.length === 0} onClick={send}>Envoyer</Button>
    </>}>
      <Notice tone="info">
        Chaque agent actif des structures choisies reçoit une notification et un courriel contenant un lien vers la fiche, ouvert après authentification.
        Aucune pièce jointe n’est envoyée ; les agents non habilités à lire le document sont écartés.
        {document.classification === 'restreint' && ' Document restreint : son titre n’apparaît pas dans le courriel.'}
      </Notice>
      {refs.isLoading ? <Loading /> : (
        <fieldset className="wide" style={{ marginTop: 12 }}>
          <legend>Structures destinataires *</legend>
          <div className="check-grid">
            {available.map((s) => (
              <label key={s.id}>
                <input type="checkbox" checked={structures.includes(s.id)} onChange={(e) => setStructures(e.target.checked ? [...structures, s.id] : structures.filter((x) => x !== s.id))} />
                <span>{s.name}</span>
              </label>
            ))}
          </div>
          {error?.field('structure_ids') && <span className="field-error">{error.field('structure_ids')}</span>}
        </fieldset>
      )}
      <div style={{ marginTop: 12 }}>
        <Field label="Message d’accompagnement" error={error?.field('message')} help="1 000 caractères au plus" wide>
          <textarea value={message} maxLength={1000} onChange={(e) => setMessage(e.target.value)} />
        </Field>
      </div>
      {error && !error.field('structure_ids') && !error.field('message') && <div style={{ marginTop: 12 }}><Notice tone="danger">{error.message}</Notice></div>}
    </Modal>
  )
}
