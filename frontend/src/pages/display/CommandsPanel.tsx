import { useState } from 'react'
import { useQuery, useQueryClient } from '@tanstack/react-query'
import { api } from '../../lib/api'
import { fmtDateTime, fmtRelative } from '../../lib/format'
import { Button, ConfirmDialog, ErrorState, Loading, Modal, Notice, Status, StatusOf, useToast } from '../../components/ui'

export interface DeviceCommandRow {
  id: string; command: string; label: string; status: string; reason: string | null; requested_by: { id: string; name: string } | null
  requested_at: string; sent_at: string | null; received_at: string | null; completed_at: string | null; expires_at: string
  result_message: string | null; result_data: Record<string, unknown> | null; screenshot_id: string | null
}
interface CommandsMeta {
  availability: { command: string; label: string; available: boolean; reason: string | null }[]
  capabilities: string[] | null; capabilities_reported_at: string | null; playback_paused: boolean; can_command: boolean
  current_page: number; last_page: number; total: number
}

/** §11.2 : états d'une commande. « Exécutée » n'apparaît qu'après le compte rendu du lecteur. */
export const COMMAND_STATUS: Record<string, [string, string]> = {
  pending: ['En attente', 'neutral'], sent: ['Envoyée', 'blue'], received: ['Reçue', 'blue'], executed: ['Exécutée', 'success'],
  failed: ['Échouée', 'danger'], expired: ['Expirée', 'warning'], cancelled: ['Annulée', 'neutral'],
}
/** Commandes qui interrompent l'affichage : motif exigé, confirmation explicite. */
const DISRUPTIVE: Record<string, string> = {
  stop_playback: 'L’écran devient noir jusqu’à la reprise ; les alertes prioritaires restent affichées. Aucune lecture n’est déclarée pendant l’arrêt.',
  reboot_device: 'L’appareil redémarre : l’écran est interrompu pendant le redémarrage. L’exécution sera confirmée par le lecteur à son retour.',
  standby: 'La dalle est mise en veille par l’agent local ; elle reste éteinte jusqu’au réveil ou à la prochaine ouverture de la plage de fonctionnement.',
  restart_player: 'L’application du lecteur se recharge (cache vérifié conservé) ; l’écran est brièvement interrompu. L’exécution est confirmée au retour du lecteur.',
}

/**
 * Commandes à distance d'un lecteur (§11) : chaque commande indisponible est désactivée avec sa raison (capacités déclarées
 * par le lecteur), et l'historique montre le cycle complet jusqu'au compte rendu du lecteur.
 */
export default function CommandsPanel({ deviceId, deviceCode }: { deviceId: string; deviceCode: string }) {
  const client = useQueryClient()
  const toast = useToast()
  const [confirm, setConfirm] = useState<{ command: string; label: string } | null>(null)
  const [busy, setBusy] = useState<string | null>(null)
  const [report, setReport] = useState<DeviceCommandRow | null>(null)
  const key = ['device-commands', deviceId]
  const query = useQuery({
    queryKey: key,
    queryFn: ({ signal }) => api.get<{ data: DeviceCommandRow[]; meta: CommandsMeta }>(`/devices/${deviceId}/commands`, { per_page: 15 }, signal),
    // Suivi du cycle de vie tant qu'une commande est en cours (remise au battement, environ 30 s).
    refetchInterval: (q) => (q.state.data?.data.some((c) => ['pending', 'sent', 'received'].includes(c.status)) ? 5000 : false),
  })
  const send = async (command: string, reason?: string) => {
    setBusy(command)
    try {
      const res = await api.post<{ message?: string }>(`/devices/${deviceId}/commands`, { command, reason })
      toast.success('Commande transmise', res.message)
      await client.invalidateQueries({ queryKey: key })
    } finally {
      setBusy(null)
    }
  }
  const cancel = async (id: string) => {
    try {
      await api.post(`/device-commands/${id}/cancel`)
      toast.success('Commande annulée')
      await client.invalidateQueries({ queryKey: key })
    } catch (e) {
      toast.error(e)
    }
  }

  if (query.isLoading) return <section className="panel"><Loading /></section>
  if (query.isError || !query.data) return <section className="panel"><ErrorState error={query.error} onRetry={() => query.refetch()} /></section>
  const { data: rows, meta } = query.data

  return (
    <section className="panel" aria-labelledby="commands-title">
      <div className="panel-title">
        <div>
          <h2 id="commands-title">Commandes à distance</h2>
          <p>{meta.capabilities_reported_at ? `Capacités déclarées par le lecteur ${fmtRelative(meta.capabilities_reported_at)}` : 'Le lecteur n’a pas encore déclaré ses capacités'}{meta.playback_paused ? ' · diffusion arrêtée' : ''}</p>
        </div>
        {meta.playback_paused && <Status tone="warning">Diffusion arrêtée</Status>}
      </div>
      <div className="panel-body">
        {meta.can_command ? (
          <div className="command-grid" role="group" aria-label="Commandes disponibles">
            {meta.availability.map((a) => (
              <div key={a.command} className="command-cell">
                <Button size="small" variant={a.command === 'stop_playback' || a.command === 'reboot_device' ? 'danger' : 'secondary'} disabled={!a.available} busy={busy === a.command}
                  title={a.reason ?? undefined}
                  onClick={() => (DISRUPTIVE[a.command] ? setConfirm(a) : void send(a.command).catch((e) => toast.error(e)))}>{a.label}</Button>
                {a.reason && <small id={`reason-${a.command}`} className="muted">{a.reason}</small>}
              </div>
            ))}
          </div>
        ) : <Notice tone="info">Consultation seule : l’envoi de commandes exige la permission « commandes à distance ».</Notice>}
        <p className="muted small" style={{ marginTop: 10 }}>Une commande est remise au prochain battement du lecteur (environ 30 s). Elle n’est « exécutée » qu’après le compte rendu du lecteur ; sans réponse à l’échéance, elle expire.</p>
        <div className="table-scroll" style={{ marginTop: 8 }}>
          <table>
            <caption className="sr-only">Historique des commandes du lecteur {deviceCode}</caption>
            <thead><tr><th>Commande</th><th>Demandée</th><th>État</th><th>Compte rendu</th><th><span className="sr-only">Actions</span></th></tr></thead>
            <tbody>
              {rows.map((c) => (
                <tr key={c.id}>
                  <td><strong>{c.label}</strong><small className="mono" title={`Identifiant de transaction ${c.id}`}>…{c.id.slice(-12)}</small>{c.reason && <small>{c.reason}</small>}</td>
                  <td>{fmtDateTime(c.requested_at)}<small>{c.requested_by?.name ?? 'Système'}</small></td>
                  <td><StatusOf map={COMMAND_STATUS} value={c.status} /><small>{c.completed_at ? fmtDateTime(c.completed_at) : c.received_at ? `reçue ${fmtRelative(c.received_at)}` : c.sent_at ? `envoyée ${fmtRelative(c.sent_at)}` : `échéance ${fmtDateTime(c.expires_at)}`}</small></td>
                  <td className="small">{c.result_message ?? '—'}</td>
                  <td className="row-actions">
                    {c.result_data && <Button size="small" variant="ghost" onClick={() => setReport(c)}>Détail</Button>}
                    {meta.can_command && ['pending', 'sent'].includes(c.status) && <Button size="small" variant="ghost" onClick={() => cancel(c.id)}>Annuler</Button>}
                  </td>
                </tr>
              ))}
              {rows.length === 0 && <tr><td colSpan={5} className="muted">Aucune commande envoyée à ce lecteur.</td></tr>}
            </tbody>
          </table>
        </div>
      </div>
      {confirm && (
        <ConfirmDialog title={confirm.label} object={deviceCode} danger={confirm.command !== 'restart_player'} confirmLabel={confirm.label}
          consequence={DISRUPTIVE[confirm.command]} reasonLabel="Motif" reasonRequired onClose={() => setConfirm(null)}
          onConfirm={(reason) => send(confirm.command, reason)} />
      )}
      {report && (
        <Modal title={`Compte rendu · ${report.label}`} kicker={report.id} onClose={() => setReport(null)} wide>
          <p className="small">{report.result_message}</p>
          {Array.isArray(report.result_data?.errors) && (
            <>
              <h3 className="sub-title">Erreurs récentes du lecteur</h3>
              {(report.result_data.errors as string[]).length ? <ul className="small mono" style={{ paddingLeft: 18 }}>{(report.result_data.errors as string[]).map((e, i) => <li key={i}>{e}</li>)}</ul> : <p className="muted small">Aucune erreur.</p>}
            </>
          )}
          <h3 className="sub-title">Données transmises</h3>
          <pre className="code-block">{JSON.stringify({ ...report.result_data, errors: undefined }, null, 2)}</pre>
        </Modal>
      )}
    </section>
  )
}
