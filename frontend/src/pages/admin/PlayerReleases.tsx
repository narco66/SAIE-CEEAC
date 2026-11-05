import { useState } from 'react'
import { useQuery, useQueryClient } from '@tanstack/react-query'
import { api, ApiError } from '../../lib/api'
import { useAuth } from '../../lib/auth'
import { fmtDateTime } from '../../lib/format'
import { Button, ConfirmDialog, EmptyState, ErrorState, Field, Loading, Notice, Status, useToast } from '../../components/ui'

interface Release { id: string; version: string; channel: 'pilot' | 'stable'; base_url: string; sha256: string; notes: string | null; status: string; created_at: string | null }
interface ReleasesMeta {
  targets: Record<'pilot' | 'stable', string | null>
  devices: { release_channel: string | null; app_version: string | null; total: number }[]
}

const CHANNEL_LABEL: Record<string, string> = { pilot: 'Pilote', stable: 'Stable' }

/** Versions du lecteur par canal (V40, PLY-12) : pilote avant stable, retrait = retour à la version précédente en une action. */
export function PlayerReleases() {
  const { can } = useAuth()
  const client = useQueryClient()
  const toast = useToast()
  const query = useQuery({ queryKey: ['player-releases'], queryFn: ({ signal }) => api.get<{ data: Release[]; meta: ReleasesMeta }>('/player-releases', undefined, signal) })
  const [dialog, setDialog] = useState<{ kind: 'publish'; version?: string } | { kind: 'withdraw'; release: Release } | null>(null)
  const manage = can('incidents.manage')
  const refresh = () => {
    client.invalidateQueries({ queryKey: ['player-releases'] })
    client.invalidateQueries({ queryKey: ['supervision'] })
  }

  return (
    <section className="panel table-panel section-gap">
      <div className="panel-title">
        <div><h2>Versions du lecteur</h2><p>Une version passe par le canal pilote avant le canal stable ; la version cible est portée par le manifeste signé</p></div>
        {manage && <Button size="small" icon="plus" onClick={() => setDialog({ kind: 'publish' })}>Publier une version</Button>}
      </div>
      {query.isLoading ? <Loading /> : query.isError || !query.data ? <ErrorState error={query.error} onRetry={() => query.refetch()} /> : (
        <>
          <div className="panel-body" style={{ display: 'grid', gap: 10, gridTemplateColumns: 'repeat(auto-fit, minmax(220px, 1fr))' }}>
            {(['pilot', 'stable'] as const).map((channel) => {
              const fleet = query.data.meta.devices.filter((d) => (d.release_channel ?? 'stable') === channel)
              const total = fleet.reduce((n, d) => n + Number(d.total), 0)
              const target = query.data.meta.targets[channel]
              const upToDate = fleet.filter((d) => target && d.app_version === target).reduce((n, d) => n + Number(d.total), 0)
              return (
                <div key={channel} className="booking-link" style={{ padding: 12, display: 'block' }}>
                  <strong>Canal {CHANNEL_LABEL[channel]}</strong>
                  <small style={{ display: 'block' }}>Version cible : <span className="mono">{target ?? 'aucune (version embarquée)'}</span></small>
                  <small style={{ display: 'block' }} className="muted">{total} lecteur(s) actif(s){target ? ` · ${upToDate} à jour` : ''}</small>
                  {fleet.length > 0 && <small style={{ display: 'block' }} className="muted">{fleet.map((d) => `${d.app_version ?? 'version inconnue'} : ${d.total}`).join(' · ')}</small>}
                </div>
              )
            })}
          </div>
          {query.data.data.length === 0 ? <EmptyState title="Aucune version publiée" text="Les lecteurs exécutent la version déployée avec le site. Construire une version avec « node scripts/release.mjs <version> » dans player/." /> : (
            <div className="table-scroll">
              <table>
                <thead><tr><th scope="col">Version</th><th scope="col">Canal</th><th scope="col">Empreinte SHA-256</th><th scope="col">Publiée le</th><th scope="col">État</th>{manage && <th scope="col"><span className="sr-only">Actions</span></th>}</tr></thead>
                <tbody>
                  {query.data.data.map((r) => {
                    const promotable = r.channel === 'pilot' && r.status === 'active' && !query.data.data.some((x) => x.version === r.version && x.channel === 'stable' && x.status === 'active')
                    return (
                      <tr key={r.id}>
                        <td><strong className="mono">{r.version}</strong>{r.notes && <small>{r.notes}</small>}</td>
                        <td>{CHANNEL_LABEL[r.channel] ?? r.channel}</td>
                        <td className="mono" title={r.sha256}>{r.sha256.slice(0, 16)}…</td>
                        <td>{fmtDateTime(r.created_at)}</td>
                        <td>{r.status === 'active' ? <Status tone="success">Active</Status> : <Status tone="neutral">Retirée</Status>}</td>
                        {manage && (
                          <td><div className="row-actions">
                            {promotable && <Button size="small" variant="secondary" icon="check" onClick={() => setDialog({ kind: 'publish', version: r.version })}>Promouvoir en stable</Button>}
                            {r.status === 'active' && <Button size="small" variant="secondary" icon="x" onClick={() => setDialog({ kind: 'withdraw', release: r })}>Retirer</Button>}
                          </div></td>
                        )}
                      </tr>
                    )
                  })}
                </tbody>
              </table>
            </div>
          )}
        </>
      )}
      {dialog?.kind === 'publish' && <PublishRelease promote={query.data?.data.find((r) => r.version === dialog.version && r.channel === 'pilot')} onClose={() => setDialog(null)} onDone={(r) => { refresh(); toast.success('Version publiée', `${r.version} · ${CHANNEL_LABEL[r.channel]}`) }} />}
      {dialog?.kind === 'withdraw' && (
        <ConfirmDialog title="Retirer la version" object={`${dialog.release.version} · canal ${CHANNEL_LABEL[dialog.release.channel]}`} danger
          consequence="Le canal revient immédiatement à la version active précédente ; les lecteurs l’appliquent à leur prochaine synchronisation."
          confirmLabel="Retirer la version" reasonLabel="Motif (anomalie constatée)" reasonRequired onClose={() => setDialog(null)}
          onConfirm={async (reason) => {
            const res = await api.post<{ target: string | null }>(`/player-releases/${dialog.release.id}/withdraw`, { reason })
            refresh()
            toast.success('Version retirée', res.target ? `Version cible : ${res.target}` : 'Retour à la version embarquée')
          }} />
      )}
    </section>
  )
}

function PublishRelease({ promote, onClose, onDone }: { promote?: Release; onClose: () => void; onDone: (r: Release) => void }) {
  const [version, setVersion] = useState(promote?.version ?? '')
  const [sha256, setSha256] = useState(promote?.sha256 ?? '')
  const [notes, setNotes] = useState(promote?.notes ?? '')
  const channel = promote ? 'stable' : 'pilot'
  const [error, setError] = useState<ApiError | null>(null)
  return (
    <ConfirmDialog
      title={promote ? 'Promouvoir en stable' : 'Publier sur le canal pilote'}
      object={version ? `Version ${version}` : undefined}
      consequence={promote
        ? 'Tous les lecteurs du canal stable recevront cette version à leur prochaine synchronisation.'
        : 'Seuls les lecteurs du canal pilote reçoivent cette version. Les fichiers doivent déjà être déposés sous /player/releases/<version>/.'}
      confirmLabel="Publier" onClose={onClose}
      onConfirm={async () => {
        setError(null)
        try {
          const res = await api.post<{ data: Release }>('/player-releases', {
            version: version.trim(), channel, base_url: promote?.base_url ?? `/player/releases/${version.trim()}/`, sha256: sha256.trim().toLowerCase(), notes: notes.trim() || null,
          })
          onDone(res.data)
        } catch (e) {
          if (e instanceof ApiError) {
            setError(e)
          }
          throw e
        }
      }}>
      {!promote && (
        <div className="form-grid" style={{ marginTop: 12 }}>
          <Field label="Version" required error={error?.field('version')} help="Exemple : 1.1.0"><input value={version} maxLength={40} onChange={(e) => setVersion(e.target.value)} /></Field>
          <Field label="Empreinte SHA-256 de index.html" required error={error?.field('sha256')} help="Affichée par scripts/release.mjs" wide>
            <input className="mono" value={sha256} maxLength={64} onChange={(e) => setSha256(e.target.value)} />
          </Field>
          <Field label="Notes de version" error={error?.field('notes')} wide><textarea value={notes} maxLength={1000} onChange={(e) => setNotes(e.target.value)} /></Field>
        </div>
      )}
      {!promote && <div style={{ marginTop: 10 }}><Notice tone="info">Construire la version : <span className="mono">cd player; node scripts/release.mjs {version || '<version>'}</span>, puis copier <span className="mono">dist-release/{version || '<version>'}</span> vers le serveur web.</Notice></div>}
    </ConfirmDialog>
  )
}
