import { useState } from 'react'
import { useQuery, useQueryClient } from '@tanstack/react-query'
import { api, ApiError } from '../../lib/api'
import { useAuth } from '../../lib/auth'
import { fmtDateTime } from '../../lib/format'
import { Button, ConfirmDialog, EmptyState, ErrorState, Field, Loading, Modal, Notice, Status, useToast } from '../../components/ui'

interface Release { id: string; version: string; channel: 'pilot' | 'stable'; base_url: string; sha256: string; notes: string | null; status: string; rollout_percent: number; created_at: string | null; promotion_eligible?: boolean }
interface Deployment {
  release: Release
  summary: Partial<Record<'updated' | 'pending' | 'failing' | 'excluded', number>> & { total: number }
  devices: { device: string; device_id: string; screen: string | null; app_version: string | null; state: 'updated' | 'pending' | 'failing' | 'excluded'; last_seen_at: string | null }[]
}
const DEPLOY_STATE: Record<string, [string, 'success' | 'warning' | 'danger' | 'neutral']> = {
  updated: ['À jour', 'success'], pending: ['En attente', 'warning'], failing: ['En échec', 'danger'], excluded: ['Hors vague', 'neutral'],
}
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
  const [dialog, setDialog] = useState<{ kind: 'publish'; version?: string } | { kind: 'withdraw'; release: Release } | { kind: 'deployment'; release: Release } | null>(null)
  const manage = can('player_releases.manage')
  const refresh = () => {
    client.invalidateQueries({ queryKey: ['player-releases'] })
    client.invalidateQueries({ queryKey: ['supervision'] })
  }

  return (
    <section className="panel table-panel section-gap">
      <div className="panel-title">
        <div><h2>Versions du lecteur</h2><p>Une version passe par le canal pilote avant le canal stable ; la version cible est transmise aux lecteurs avec leur programme signé</p></div>
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
          {query.data.data.length === 0 ? <EmptyState title="Aucune version publiée" text="Les lecteurs exécutent la version déployée avec le site. La construction d’une version est décrite dans le guide de mise en service, § 5." /> : (
            <div className="table-scroll">
              <table>
                <thead><tr><th scope="col">Version</th><th scope="col">Canal</th><th scope="col">Empreinte SHA-256</th><th scope="col">Publiée le</th><th scope="col">Déploiement</th><th scope="col">État</th>{manage && <th scope="col"><span className="sr-only">Actions</span></th>}</tr></thead>
                <tbody>
                  {query.data.data.map((r) => {
                    const promotable = r.channel === 'pilot' && r.status === 'active' && !query.data.data.some((x) => x.version === r.version && x.channel === 'stable' && x.status === 'active')
                    return (
                      <tr key={r.id}>
                        <td><strong className="mono">{r.version}</strong>{r.notes && <small>{r.notes}</small>}{r.promotion_eligible && <small><Status tone="success">Promotion en stable possible : indicateurs du canal pilote satisfaits</Status></small>}</td>
                        <td>{CHANNEL_LABEL[r.channel] ?? r.channel}</td>
                        <td className="mono" title={r.sha256}>{r.sha256.slice(0, 16)}…</td>
                        <td>{fmtDateTime(r.created_at)}</td>
                        <td>{r.rollout_percent < 100 ? <Status tone="warning">{`Vague : ${r.rollout_percent} %`}</Status> : '100 %'}</td>
                        <td>{r.status === 'active' ? <Status tone="success">Active</Status> : <Status tone="neutral">Retirée</Status>}</td>
                        {manage && (
                          <td><div className="row-actions">
                            <Button size="small" variant="ghost" icon="chart" onClick={() => setDialog({ kind: 'deployment', release: r })}>Suivi</Button>
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
      {dialog?.kind === 'deployment' && <DeploymentDialog release={dialog.release} manage={manage} onClose={() => setDialog(null)} onChanged={refresh} />}
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
  const [rollout, setRollout] = useState(100)
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
            version: version.trim(), channel, base_url: promote?.base_url ?? `/player/releases/${version.trim()}/`, sha256: sha256.trim().toLowerCase(), notes: notes.trim() || null, rollout_percent: rollout,
          })
          onDone(res.data)
        } catch (e) {
          if (e instanceof ApiError) {
            setError(e)
          }
          throw e
        }
      }}>
      <div className="form-grid" style={{ marginTop: 12 }}>
        <Field label="Part du canal servie (%)" required error={error?.field('rollout_percent')} help="Déploiement progressif : 100 % = tous les lecteurs du canal. Les autres restent sur la version précédente.">
          <input type="number" min={1} max={100} value={rollout} onChange={(e) => setRollout(Number(e.target.value))} />
        </Field>
      </div>
      {!promote && (
        <div className="form-grid" style={{ marginTop: 12 }}>
          <Field label="Version" required error={error?.field('version')} help="Exemple : 1.1.0"><input value={version} maxLength={40} onChange={(e) => setVersion(e.target.value)} /></Field>
          <Field label="Empreinte SHA-256 de la version" required error={error?.field('sha256')} help="Fournie avec la version construite (guide de mise en service, § 5)" wide>
            <input className="mono" value={sha256} maxLength={64} onChange={(e) => setSha256(e.target.value)} />
          </Field>
          <Field label="Notes de version" error={error?.field('notes')} wide><textarea value={notes} maxLength={1000} onChange={(e) => setNotes(e.target.value)} /></Field>
        </div>
      )}
      {!promote && <div style={{ marginTop: 10 }}><Notice tone="info">La version doit être construite et copiée sur le serveur web avant sa publication : voir le guide de mise en service, § 5.</Notice></div>}
    </ConfirmDialog>
  )
}

/** §21.4 : suivi d'une version (lecteurs à jour, en attente, en échec, hors vague) et élargissement de la vague. */
function DeploymentDialog({ release, manage, onClose, onChanged }: { release: Release; manage: boolean; onClose: () => void; onChanged: () => void }) {
  const toast = useToast()
  const query = useQuery({ queryKey: ['player-release-deployment', release.id], queryFn: ({ signal }) => api.get<{ data: Deployment }>(`/player-releases/${release.id}/deployment`, undefined, signal).then((r) => r.data) })
  const [percent, setPercent] = useState(release.rollout_percent)
  const [reason, setReason] = useState('')
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<ApiError | null>(null)
  const save = async () => {
    setBusy(true)
    setError(null)
    try {
      await api.patch(`/player-releases/${release.id}`, { rollout_percent: percent, reason: reason.trim() })
      toast.success('Vague modifiée', `${release.version} · ${percent} % du canal`)
      setReason('')
      onChanged()
      await query.refetch()
    } catch (e) {
      if (e instanceof ApiError) {
        setError(e)
      }
      toast.error(e)
    } finally {
      setBusy(false)
    }
  }
  const summary = query.data?.summary
  return (
    <Modal title="Suivi du déploiement" kicker={`${release.version} · canal ${CHANNEL_LABEL[release.channel]}`} onClose={onClose} wide footer={<Button variant="secondary" onClick={onClose}>Fermer</Button>}>
      {query.isLoading ? <Loading /> : query.isError || !query.data ? <ErrorState error={query.error} onRetry={() => query.refetch()} /> : (
        <>
          <p className="muted small">{summary?.total ?? 0} lecteur(s) actif(s) sur le canal dans votre périmètre · {(['updated', 'pending', 'failing', 'excluded'] as const).map((k) => `${DEPLOY_STATE[k][0]} : ${summary?.[k] ?? 0}`).join(' · ')}</p>
          {query.data.devices.length === 0 ? <EmptyState title="Aucun lecteur sur ce canal" text="Aucun lecteur actif n’est rattaché à ce canal dans votre périmètre." /> : (
            <div className="table-scroll" style={{ maxHeight: 320 }}>
              <table>
                <thead><tr><th scope="col">Lecteur</th><th scope="col">Écran</th><th scope="col">Version déclarée</th><th scope="col">Dernier contact</th><th scope="col">État</th></tr></thead>
                <tbody>
                  {query.data.devices.map((d) => (
                    <tr key={d.device_id}><td className="mono">{d.device}</td><td>{d.screen ?? '—'}</td><td className="mono">{d.app_version ?? '—'}</td><td>{fmtDateTime(d.last_seen_at)}</td>
                      <td><Status tone={DEPLOY_STATE[d.state][1]}>{DEPLOY_STATE[d.state][0]}</Status></td></tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
          {manage && release.status === 'active' && (
            <div className="form-grid" style={{ marginTop: 14 }}>
              <Field label="Part du canal servie (%)" required error={error?.field('rollout_percent')} help="Augmenter ajoute des lecteurs sans en retirer : la sélection est stable.">
                <input type="number" min={1} max={100} value={percent} onChange={(e) => setPercent(Number(e.target.value))} />
              </Field>
              <Field label="Motif" required error={error?.field('reason')}><input value={reason} maxLength={300} onChange={(e) => setReason(e.target.value)} /></Field>
              <div><Button icon="check" busy={busy} disabled={percent === release.rollout_percent || reason.trim().length < 5} onClick={save}>Appliquer la vague</Button></div>
            </div>
          )}
        </>
      )}
    </Modal>
  )
}
