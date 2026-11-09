import { useMemo, useState } from 'react'
import { Link } from 'react-router'
import { useQuery } from '@tanstack/react-query'
import { api, fileUrl } from '../../lib/api'
import { fmtDate, fmtDateTime, SEVERITY } from '../../lib/format'
import { ErrorState, Icon, Loading, PageHeading, Status, StatusOf } from '../../components/ui'
import { KIND_LABEL, OPERATIONAL_STATE } from './shared'

interface Breakdown { label: string; total: number; online: number; anomaly: number }
interface FloorMap {
  id: string; label: string; level: number; building: string | null; plan_asset_version_id: string | null
  rooms: { id: string; name: string; code: string }[]
  screens: { id: string; code: string; name: string; state: string; location: string; room: string | null; plan: { x: number; y: number } | null }[]
}
interface Overview {
  generated_at: string
  cards: Record<'total' | 'online' | 'offline' | 'broadcasting' | 'anomaly' | 'inactive' | 'not_configured' | 'maintenance' | 'standby', number>
  incidents_by_severity: Record<string, number>
  by_state: Record<string, number>; by_building: Breakdown[]; by_floor: Breakdown[]; by_kind: Record<string, number>; by_technology: Record<string, number>
  players: { total: number; versions: Record<string, number>; outdated: number; paused: number }
  availability_trend: { date: string; value: number | null }[]
  most_played: { id: string; code: string; name: string; plays: number }[]
  incidents_trend: { date: string; opened: number; critical: number }[]
  floors: FloorMap[]
}

const TECHNOLOGY: Record<string, string> = { led: 'LED', lcd: 'LCD', oled: 'OLED', other: 'Autre', unknown: 'Non renseignée' }
/** Couleur et icône : la couleur n'est jamais le seul porteur de l'état (§4.3). */
const STATE_MARK: Record<string, { cls: string; icon: 'check' | 'alert' | 'x' | 'clock' | 'wifi' }> = {
  broadcasting: { cls: 'ok', icon: 'check' }, active: { cls: 'ok', icon: 'wifi' }, fallback: { cls: 'ok', icon: 'wifi' },
  anomaly: { cls: 'warn', icon: 'alert' }, offline: { cls: 'crit', icon: 'x' }, pending_configuration: { cls: 'warn', icon: 'clock' },
  maintenance: { cls: 'warn', icon: 'clock' }, standby: { cls: 'off', icon: 'clock' }, paused: { cls: 'off', icon: 'clock' }, disabled: { cls: 'off', icon: 'x' },
  suspended: { cls: 'crit', icon: 'x' }, player_stopped: { cls: 'crit', icon: 'alert' },
}

/** §4 Vue d'ensemble du parc : indicateurs réels, répartitions, tendances et vue par niveau. */
export default function FleetOverview() {
  const q = useQuery({ queryKey: ['fleet-overview'], queryFn: ({ signal }) => api.get<{ data: Overview }>('/screens/overview', undefined, signal).then((r) => r.data), refetchInterval: 30_000 })
  if (q.isLoading) return <Loading />
  if (q.isError || !q.data) return <ErrorState error={q.error} onRetry={() => q.refetch()} />
  const d = q.data
  const cards: { key: keyof Overview['cards']; label: string; to: string; tone?: string; hint: string }[] = [
    { key: 'total', label: 'Écrans', to: '/affichage/ecrans', hint: 'hors archivés' },
    { key: 'online', label: 'En ligne', to: '/affichage/ecrans?network=online', tone: 'green-text', hint: 'lecteur joignable' },
    { key: 'broadcasting', label: 'En diffusion', to: '/affichage/ecrans?state=broadcasting', tone: 'green-text', hint: 'lecture déclarée' },
    { key: 'offline', label: 'Hors ligne', to: '/affichage/ecrans?state=offline', tone: 'red-text', hint: 'aucun battement' },
    { key: 'anomaly', label: 'En anomalie', to: '/affichage/ecrans?state=anomaly', tone: 'amber-text', hint: 'incident ou erreur récente' },
    { key: 'not_configured', label: 'Non configurés', to: '/affichage/ecrans?state=pending_configuration', tone: 'amber-text', hint: 'sans lecteur appairé' },
    { key: 'standby', label: 'En veille ou arrêtés', to: '/affichage/ecrans?state=standby,paused', hint: 'hors plage ou arrêt commandé' },
    { key: 'maintenance', label: 'En maintenance', to: '/affichage/ecrans?state=maintenance', hint: 'intervention' },
    { key: 'inactive', label: 'Inactifs', to: '/affichage/ecrans?state=disabled', hint: 'désactivés' },
  ]
  const incidents = Object.values(d.incidents_by_severity).reduce((a, b) => a + b, 0)
  return (
    <>
      <PageHeading title="Vue d’ensemble du parc" description={`Indicateurs calculés sur les états, la télémétrie et les accusés réellement reçus · ${fmtDateTime(d.generated_at)}`} />
      <section className="overview-cards" aria-label="Indicateurs du parc">
        {cards.map((c) => (
          <Link key={c.key} to={c.to} className="overview-card">
            <strong className={c.tone}>{d.cards[c.key]}</strong><span>{c.label}</span><small>{c.hint}</small>
          </Link>
        ))}
        <Link to="/supervision" className="overview-card">
          <strong className={incidents ? 'red-text' : ''}>{incidents}</strong><span>Alertes techniques</span>
          <small>{Object.entries(d.incidents_by_severity).filter(([, n]) => n > 0).map(([s, n]) => `${n} ${(SEVERITY[s]?.[0] ?? s).toLowerCase()}`).join(' · ') || 'aucun incident ouvert'}</small>
        </Link>
      </section>
      <div className="two-columns report-grid section-gap">
        <Bars title="Répartition par bâtiment" rows={d.by_building} />
        <Bars title="Répartition par niveau" rows={d.by_floor} />
      </div>
      <div className="two-columns report-grid section-gap">
        <Counts title="Répartition par état" data={d.by_state} label={(k) => OPERATIONAL_STATE[k]?.[0] ?? k} />
        <Counts title="Répartition par type" data={d.by_kind} label={(k) => KIND_LABEL[k] ?? k} extra={<Counts title="Technologie des dalles" data={d.by_technology} label={(k) => TECHNOLOGY[k] ?? k} bare />} />
      </div>
      <div className="two-columns report-grid section-gap">
        <Trend title="Joignabilité des lecteurs (14 jours)" hint="Relevés de télémétrie reçus / créneaux de 5 min attendus depuis l’appairage" points={d.availability_trend.map((p) => ({ label: p.date, value: p.value, text: p.value === null ? 'sans mesure' : `${p.value.toLocaleString('fr-FR')} %` }))} max={100} />
        <Trend title="Incidents ouverts par jour (30 jours)" hint="Dont critiques" points={d.incidents_trend.map((p) => ({ label: p.date, value: p.opened, text: `${p.opened} incident(s)${p.critical ? `, dont ${p.critical} critique(s)` : ''}` }))} max={Math.max(1, ...d.incidents_trend.map((p) => p.opened))} />
      </div>
      <div className="two-columns report-grid section-gap">
        <section className="panel">
          <div className="panel-title"><div><h2>Écrans les plus sollicités</h2><p>Lectures déclarées sur 7 jours</p></div></div>
          <div className="panel-body">
            {d.most_played.length ? <ol className="ranked">{d.most_played.map((m) => <li key={m.id}><Link className="link-text" to={`/affichage/ecrans/${m.id}`}>{m.code}</Link> · {m.name} <strong>{m.plays.toLocaleString('fr-FR')}</strong></li>)}</ol>
              : <p className="muted small">Aucune lecture déclarée sur la période.</p>}
          </div>
        </section>
        <section className="panel">
          <div className="panel-title"><div><h2>État des lecteurs</h2><p>{d.players.total} lecteur(s) appairé(s)</p></div></div>
          <div className="panel-body">
            <ul className="ranked">
              {Object.entries(d.players.versions).sort().map(([v, n]) => <li key={v}><span className="mono">{v}</span> <strong>{n}</strong></li>)}
            </ul>
            <p className="small">{d.players.outdated} lecteur(s) en retard sur la version de leur canal · {d.players.paused} diffusion(s) arrêtée(s) à distance</p>
          </div>
        </section>
      </div>
      <BuildingView floors={d.floors} />
    </>
  )
}

function Bars({ title, rows }: { title: string; rows: Breakdown[] }) {
  return (
    <section className="panel">
      <div className="panel-title"><div><h2>{title}</h2><p>Écrans en ligne / total</p></div></div>
      <div className="panel-body">
        {rows.length ? <ul className="breakdown-list">{rows.map((r) => (
          <li key={r.label} className="breakdown-row static">
            <span>{r.label}</span><i aria-hidden="true"><b style={{ width: `${r.total ? (r.online / r.total) * 100 : 0}%` }} /></i><strong>{r.online}/{r.total}</strong>
            {r.anomaly > 0 ? <Status tone="danger">{r.anomaly} en anomalie</Status> : <span />}
          </li>
        ))}</ul> : <p className="muted small">Aucun écran.</p>}
      </div>
    </section>
  )
}

function Counts({ title, data, label, extra, bare }: { title: string; data: Record<string, number>; label: (k: string) => string; extra?: React.ReactNode; bare?: boolean }) {
  const total = Object.values(data).reduce((a, b) => a + b, 0)
  const list = (
    <ul className="breakdown-list">
      {Object.entries(data).sort((a, b) => b[1] - a[1]).map(([k, n]) => (
        <li key={k} className="breakdown-row static"><span>{label(k)}</span><i aria-hidden="true"><b style={{ width: `${total ? (n / total) * 100 : 0}%` }} /></i><strong>{n}</strong><span /></li>
      ))}
    </ul>
  )
  if (bare) return <><h3 className="sub-title">{title}</h3>{list}</>
  return <section className="panel"><div className="panel-title"><div><h2>{title}</h2><p>{total} écran(s)</p></div></div><div className="panel-body">{list}{extra}</div></section>
}

function Trend({ title, hint, points, max }: { title: string; hint: string; points: { label: string; value: number | null; text: string }[]; max: number }) {
  return (
    <section className="panel">
      <div className="panel-title"><div><h2>{title}</h2><p>{hint}</p></div></div>
      <div className="panel-body">
        <div className="trend" role="list">
          {points.map((p) => (
            <div key={p.label} role="listitem" className="trend-bar" title={`${fmtDate(p.label)} : ${p.text}`} aria-label={`${fmtDate(p.label)} : ${p.text}`}>
              <i style={{ height: `${p.value === null ? 0 : Math.max(2, (p.value / max) * 100)}%` }} className={p.value === null ? 'empty' : ''} />
            </div>
          ))}
        </div>
        <div className="trend-axis"><span>{fmtDate(points[0]?.label)}</span><span>{fmtDate(points[points.length - 1]?.label)}</span></div>
      </div>
    </section>
  )
}

/** §4.3 : vue par niveau — plan si déposé (écrans positionnés), sinon salles et emplacements ; filtre des anomalies. */
function BuildingView({ floors }: { floors: FloorMap[] }) {
  const [floorId, setFloorId] = useState(floors[0]?.id ?? '')
  const [onlyIssues, setOnlyIssues] = useState(false)
  const floor = floors.find((f) => f.id === floorId) ?? floors[0]
  const screens = useMemo(() => (floor?.screens ?? []).filter((s) => !onlyIssues || ['anomaly', 'offline', 'pending_configuration'].includes(s.state)), [floor, onlyIssues])
  if (!floor) return null
  const placed = screens.filter((s) => s.plan)
  return (
    <section className="panel section-gap" aria-labelledby="building-view">
      <div className="panel-title">
        <div><h2 id="building-view">Vue par niveau</h2><p>{floor.building} · {floor.label} · {floor.screens.length} écran(s){floor.rooms.length ? ` · ${floor.rooms.length} salle(s)` : ''}</p></div>
        <div className="row-actions">
          <select className="input" aria-label="Niveau" value={floor.id} onChange={(e) => setFloorId(e.target.value)}>
            {floors.map((f) => <option key={f.id} value={f.id}>{f.building} · {f.label}</option>)}
          </select>
          <label className="check-label"><input type="checkbox" checked={onlyIssues} onChange={(e) => setOnlyIssues(e.target.checked)} /> Anomalies seulement</label>
        </div>
      </div>
      <div className="panel-body">
        {floor.plan_asset_version_id && placed.length > 0 && (
          <div className="floor-plan">
            <img src={fileUrl(`/asset-versions/${floor.plan_asset_version_id}/file`)} alt={`Plan du niveau ${floor.label}`} />
            {placed.map((s) => (
              <Link key={s.id} to={`/affichage/ecrans/${s.id}`} className={`plan-pin ${STATE_MARK[s.state]?.cls ?? 'off'}`} style={{ left: `${s.plan!.x}%`, top: `${s.plan!.y}%` }} title={`${s.code} · ${OPERATIONAL_STATE[s.state]?.[0] ?? s.state}`}>
                <Icon name={STATE_MARK[s.state]?.icon ?? 'info'} size={12} /><span>{s.code}</span>
              </Link>
            ))}
          </div>
        )}
        {!floor.plan_asset_version_id && <p className="muted small">Aucun plan déposé pour ce niveau (Référentiels › Niveaux) : les écrans sont présentés par emplacement.</p>}
        <div className="floor-grid">
          {screens.map((s) => (
            <Link key={s.id} to={`/affichage/ecrans/${s.id}`} className={`floor-screen ${STATE_MARK[s.state]?.cls ?? 'off'}`}>
              <Icon name={STATE_MARK[s.state]?.icon ?? 'info'} size={16} />
              <span><strong>{s.code}</strong><small>{s.location}{s.room ? ` · ${s.room}` : ''}</small></span>
              <StatusOf map={OPERATIONAL_STATE} value={s.state} />
            </Link>
          ))}
          {screens.length === 0 && <p className="muted small">{onlyIssues ? 'Aucune anomalie à ce niveau.' : 'Aucun écran à ce niveau.'}</p>}
        </div>
      </div>
    </section>
  )
}
