import { useState } from 'react'
import { useNavigate, useParams } from 'react-router'
import { useAuth } from '../../lib/auth'
import { useQuery } from '@tanstack/react-query'
import { api } from '../../lib/api'
import { CAMPAIGN_STATUS, fmtDateTime, fmtRelative, NETWORK_STATUS } from '../../lib/format'
import { Button, EmptyState, ErrorState, Icon, Loading, Notice, PageHeading, StatusOf } from '../../components/ui'
import { useExportRequest, type Campaign } from './shared'

interface Row {
  screen: { id: string; code: string; name: string; floor: string }
  device: { id: string; code: string; network_status: string; last_seen_at: string | null } | null
  scheduled: boolean; manifest_version: number | null
  received_at: string | null; loaded_at: string | null; activated_at: string | null; played_at: string | null; screenshot_at: string | null
  panel_visible: string; errors: unknown[] | Record<string, unknown> | null
}
interface DeliveryData {
  campaign: Campaign; rows: Row[]; notice: string
  summary: { targeted: number; received: number; loaded: number; activated: number; played: number; offline: number; plays_declared: number }
}

const PANEL: Record<string, string> = { on: 'Allumé', off: 'Éteint', standby: 'Veille', unknown: 'Inconnu', error: 'Erreur' }

function errorList(errors: Row['errors']): string[] {
  if (!errors) return []
  const arr = Array.isArray(errors) ? errors : Object.values(errors)
  return arr.map((e) => {
    if (typeof e === 'string') return e
    if (e && typeof e === 'object') {
      const o = e as Record<string, unknown>
      return [o.code, o.message ?? o.detail, o.at ? `(${fmtDateTime(String(o.at))})` : ''].filter(Boolean).join(' ')
    }
    return String(e)
  }).filter(Boolean)
}

function Proof({ at, label, ok }: { at: string | null; label: string; ok?: boolean }) {
  const done = ok ?? !!at
  return (
    <div className={`proof-cell ${done ? 'ok' : 'missing'}`}>
      <b><Icon name={done ? 'check' : 'clock'} size={13} />{done ? label : 'Non reçu'}</b>
      {at && <small title={fmtDateTime(at)}>{fmtDateTime(at)}</small>}
    </div>
  )
}

/** V24 Suivi de diffusion par terminal et par niveau de preuve (PUB-07, annexe C). */
export default function Delivery() {
  const { id } = useParams()
  const navigate = useNavigate()
  const { me } = useAuth()
  const [incidentsOnly, setIncidentsOnly] = useState(false)
  const exporter = useExportRequest()
  const query = useQuery({
    queryKey: ['campaign-delivery', id],
    queryFn: ({ signal }) => api.get<{ data: DeliveryData }>(`/campaigns/${id}/delivery`, undefined, signal).then((r) => r.data),
    refetchInterval: 30_000,
  })
  if (query.isLoading) return <Loading />
  if (query.isError || !query.data) return <ErrorState error={query.error} onRetry={() => query.refetch()} />
  const { campaign: c, rows, summary, notice } = query.data

  const isIncident = (r: Row) => !r.device || r.device.network_status !== 'online' || errorList(r.errors).length > 0 || (r.scheduled && (!r.received_at || !r.loaded_at || !r.activated_at))
  const shown = incidentsOnly ? rows.filter(isIncident) : rows
  const pct = (n: number) => (summary.targeted ? ` (${Math.round((n / summary.targeted) * 100)} %)` : '')

  return (
    <>
      <PageHeading
        title={`Suivi de diffusion — ${c.name}`}
        description={`${c.reference} · ${fmtDateTime(c.starts_at)} → ${fmtDateTime(c.ends_at)} (heure de Libreville) · priorité ${c.priority}`}
        demo={me.demo_mode}
        actions={<>
          <Button variant="secondary" icon="list" onClick={() => navigate('/affichage/campagnes')}>Campagnes</Button>
          <Button variant="secondary" icon="refresh" onClick={() => query.refetch()} busy={query.isFetching}>Actualiser</Button>
          <Button variant="secondary" icon="download" busy={exporter.busy === 'diffusion_report:csv'} disabled={exporter.busy !== null} onClick={() => exporter.run('diffusion_report', 'csv', { campaign_id: c.id })}>CSV</Button>
          <Button icon="download" busy={exporter.busy === 'diffusion_report:pdf'} disabled={exporter.busy !== null} onClick={() => exporter.run('diffusion_report', 'pdf', { campaign_id: c.id })}>Rapport PDF05</Button>
        </>}
      />
      <div style={{ display: 'flex', gap: 10, alignItems: 'center', marginBottom: 12 }}>
        <StatusOf map={CAMPAIGN_STATUS} value={c.status} />
        <span className="muted small">Rotation {[...c.languages].sort((a, b) => a.rank - b.rank).map((l) => l.lang.toUpperCase()).join(' → ') || '—'} · publiée {c.published_at ? fmtDateTime(c.published_at) : 'non'} · mise à jour {fmtRelative(query.dataUpdatedAt ? new Date(query.dataUpdatedAt).toISOString() : null)}</span>
      </div>
      {c.suspend_reason && <Notice tone="danger">Campagne suspendue : {c.suspend_reason}</Notice>}
      <div className="mini-stats" style={{ gridTemplateColumns: 'repeat(auto-fit, minmax(150px, 1fr))' }}>
        <div><Icon name="monitor" /><span><strong>{summary.targeted}</strong> terminaux ciblés</span></div>
        <div><Icon name="download" /><span><strong>{summary.received}</strong> manifestes reçus{pct(summary.received)}</span></div>
        <div><Icon name="folder" /><span><strong>{summary.loaded}</strong> contenus téléchargés{pct(summary.loaded)}</span></div>
        <div><Icon name="check" /><span><strong>{summary.activated}</strong> activations{pct(summary.activated)}</span></div>
        <div><Icon name="play" /><span><strong>{summary.played}</strong> lectures déclarées · {summary.plays_declared} accusé(s)</span></div>
        <div><Icon name="wifi" /><span><strong>{summary.offline}</strong> hors ligne ou non appairé(s)</span></div>
      </div>
      <section className="panel table-panel">
        <div className="proof-legend">
          <span><i className="scheduled" />Programmé</span>
          <span><i className="received" />Manifeste reçu</span>
          <span><i className="loaded" />Téléchargé</span>
          <span><i className="active" />Lecture déclarée</span>
          <b><Icon name="info" size={14} /> {notice}</b>
        </div>
        <div className="toolbar">
          <label className="pub-check"><input type="checkbox" checked={incidentsOnly} onChange={(e) => setIncidentsOnly(e.target.checked)} /> Incidents seulement ({rows.filter(isIncident).length})</label>
          <div className="toolbar-spacer" />
          <span className="muted small">Niveaux déclarés par le logiciel du lecteur ; « Écran visible » n’est jamais déduit du heartbeat.</span>
        </div>
        {shown.length === 0 ? <EmptyState title={incidentsOnly ? 'Aucun incident' : 'Aucun terminal ciblé'} text={incidentsOnly ? 'Tous les terminaux ont accusé chaque étape.' : 'Les cibles de la campagne ne correspondent à aucun écran actif.'} /> : (
          <div className="table-scroll">
            <table>
              <thead>
                <tr><th>Écran</th><th>Terminal</th><th>Programmé</th><th>Manifeste reçu</th><th>Téléchargé</th><th>Activé</th><th>Lecture déclarée</th><th>Rendu capturé</th><th>Écran visible</th><th>Erreurs</th></tr>
              </thead>
              <tbody>
                {shown.map((r) => {
                  const errs = errorList(r.errors)
                  return (
                    <tr key={r.screen.id}>
                      <td><a className="link-text" href={`/affichage/ecrans/${r.screen.id}`} onClick={(e) => { e.preventDefault(); navigate(`/affichage/ecrans/${r.screen.id}`) }}>{r.screen.code}</a><small>{r.screen.name} · {r.screen.floor}</small></td>
                      <td>{r.device ? <><StatusOf map={NETWORK_STATUS} value={r.device.network_status} /><small>{r.device.code} · vu {fmtRelative(r.device.last_seen_at)}</small></> : <StatusOf map={NETWORK_STATUS} value="unpaired" />}</td>
                      <td><Proof at={null} ok={r.scheduled} label={r.manifest_version ? `Manifeste v${r.manifest_version}` : 'Oui'} /></td>
                      <td><Proof at={r.received_at} label="Reçu" /></td>
                      <td><Proof at={r.loaded_at} label="Téléchargé" /></td>
                      <td><Proof at={r.activated_at} label="Activé" /></td>
                      <td><Proof at={r.played_at} label="Déclarée" /></td>
                      <td>{r.screenshot_at ? <Proof at={r.screenshot_at} label="Capture" /> : <div className="proof-cell na"><b>Aucune capture</b></div>}</td>
                      <td>{r.panel_visible === 'not_measured' ? <div className="proof-cell na"><b><Icon name="info" size={13} />Non mesuré</b><small>Aucun canal de contrôle de la dalle</small></div> : <div className={`proof-cell ${r.panel_visible === 'on' ? 'ok' : 'missing'}`}><b>{PANEL[r.panel_visible] ?? r.panel_visible}</b></div>}</td>
                      <td>{errs.length ? <span className="red-text small">{errs.slice(0, 3).join(' · ')}</span> : <span className="muted small">—</span>}</td>
                    </tr>
                  )
                })}
              </tbody>
            </table>
          </div>
        )}
      </section>
      <p className="muted small" style={{ marginTop: 10 }}><Icon name="info" size={13} /> Aucune estimation du nombre de personnes ayant vu le message n’est produite.</p>
    </>
  )
}
