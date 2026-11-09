import { useState } from 'react'
import { useRealtimeStatus } from '../../lib/realtime'
import { Link } from 'react-router'
import { useQuery, useQueryClient } from '@tanstack/react-query'
import { api, type ApiError } from '../../lib/api'
import { useAuth } from '../../lib/auth'
import { useList } from '../../lib/hooks'
import { fmtBytes, fmtDate, fmtDateTime, fmtRelative, NETWORK_STATUS, SEVERITY } from '../../lib/format'
import { Button, EmptyState, ErrorState, Field, Icon, Kv, Loading, Modal, Notice, PageHeading, Pagination, Select, Status, StatusOf, useToast } from '../../components/ui'
import { asApiError, ExportDialog, useReferentials } from './common'
import { PlayerReleases } from './PlayerReleases'
import { OPERATIONAL_STATE } from '../display/shared'
import './admin.css'

interface Check { name: string; ok: boolean; warn: boolean; detail: string; checked_at: string | null }
interface DeviceRow {
  id: string; code: string; screen: string | null; screen_id: string | null; network_status: string; last_seen_at: string | null; app_version: string | null
  clock_skew_ms: number | null; storage: { usage?: number | null; quota?: number | null; persisted?: boolean | null } | null; errors: unknown
  state: string; playback_paused: boolean; floor: { id: string; label: string; level: number } | null; building: string | null; groups: { id: string; name: string }[]
}
interface Breakdown { id: string; total: number; online: number; anomaly: number }
interface SupervisionData {
  checks: Check[]; devices: DeviceRow[]; versions: Record<string, number> | []; incidents: number
  by_floor: (Breakdown & { label: string; level: number })[]; by_group: (Breakdown & { name: string })[]; thresholds: { suspect: number; unavailable: number }
}
interface Incident {
  id: string; reference: string; title: string; description: string | null; kind: string; category: string; maintenance_type: string; diagnosis: string | null; severity: string; status: string; impact: string | null
  screen: { id: string; code: string } | null; device: string | null; assignee: { id: string; name: string; initials: string } | null
  detected_at: string | null; acknowledged_at: string | null; resolved_at: string | null; closed_at: string | null
  events: { at: string; by: string; action: string; note?: string | null }[]; is_automatic: boolean; resolution?: string | null
  verified_at?: string | null; verified_by?: { id: string; name: string } | null; escalation_level?: number; due_at?: string | null; overdue?: boolean
}
interface IncidentStats {
  total: number; open: number; automatic: number; escalated: number
  by_severity: Record<string, { count: number; mean_ack_min: number | null; mean_resolve_min: number | null }>; by_category: Record<string, number>
}

export const INCIDENT_STATUS: Record<string, [string, string]> = { open: ['Ouvert', 'danger'], acknowledged: ['Acquitté', 'warning'], resolved: ['Résolu', 'success'], closed: ['Clos', 'neutral'] }
/** §15.2 : qualification, affectation, diagnostic, intervention, résolution, vérification, clôture (ou réouverture). */
const ACTIONS: { action: 'assign' | 'acknowledge' | 'qualify' | 'diagnose' | 'intervene' | 'resolve' | 'verify' | 'reopen' | 'close' | 'comment'; label: string; from: string[] }[] = [
  { action: 'assign', label: 'Assigner', from: ['open', 'acknowledged'] },
  { action: 'acknowledge', label: 'Acquitter', from: ['open'] },
  { action: 'qualify', label: 'Qualifier', from: ['open', 'acknowledged'] },
  { action: 'diagnose', label: 'Consigner le diagnostic', from: ['open', 'acknowledged'] },
  { action: 'intervene', label: 'Consigner une intervention', from: ['open', 'acknowledged'] },
  { action: 'resolve', label: 'Résoudre', from: ['open', 'acknowledged'] },
  { action: 'verify', label: 'Vérifier le fonctionnement', from: ['resolved'] },
  { action: 'reopen', label: 'Rouvrir', from: ['resolved'] },
  { action: 'close', label: 'Clore', from: ['resolved'] },
  { action: 'comment', label: 'Commenter', from: ['open', 'acknowledged', 'resolved'] },
]
/** RG-012 : un incident n'est résolu qu'avec le résultat du traitement. */
const NOTE_REQUIRED: string[] = ['comment', 'diagnose', 'resolve', 'intervene', 'verify', 'reopen', 'qualify']
const EVENT_LABEL: Record<string, string> = { assign: 'Assignation', acknowledge: 'Acquittement', qualify: 'Qualification', diagnose: 'Diagnostic', intervene: 'Intervention', resolve: 'Résolution', verify: 'Vérification', reopen: 'Réouverture', close: 'Clôture', comment: 'Commentaire', escalate: 'Escalade' }
const CATEGORY_LABEL: Record<string, string> = { player: 'Lecteur', panel: 'Dalle', network: 'Réseau', power: 'Alimentation', content: 'Contenu', software: 'Logiciel', other: 'Autre' }
export const INCIDENT_CATEGORY: Record<string, string> = { player: 'Lecteur', panel: 'Dalle d’affichage', network: 'Réseau', power: 'Alimentation', content: 'Contenu', software: 'Logiciel', other: 'Autre' }

function checkState(c: Check): ['ok' | 'warn' | 'fail', string, string] {
  if (c.ok && !c.warn) return ['ok', 'OK', 'success']
  if (c.warn) return ['warn', 'Avertissement', 'warning']
  return ['fail', 'Échec', 'danger']
}

/** V40 Supervision : chaque contrôle indique sa date ou l'absence de mesure ; incidents suivis jusqu'à clôture. */
export default function Supervision() {
  const { can } = useAuth()
  const [declare, setDeclare] = useState(false)
  const [exporting, setExporting] = useState(false)
  const query = useQuery({ queryKey: ['supervision'], queryFn: ({ signal }) => api.get<{ data: SupervisionData }>('/supervision', undefined, signal).then((r) => r.data), refetchInterval: 15_000 })

  return (
    <>
      <PageHeading
        title="Supervision"
        description="Santé de la plateforme, état des lecteurs et suivi des incidents."
        actions={<>
          <RealtimeBadge />
          {can('exports.create') && <Button variant="secondary" icon="download" onClick={() => setExporting(true)}>Rapport d’incidents (PDF08)</Button>}
          {can('incidents.manage') && <Button icon="plus" onClick={() => setDeclare(true)}>Déclarer un incident</Button>}
        </>}
      />
      {query.isLoading ? <Loading /> : query.isError || !query.data ? <ErrorState error={query.error} onRetry={() => query.refetch()} /> : <Health d={query.data} />}
      {can('settings.technical') && <ErrorsAndApi />}
      <PlayerReleases />
      <MaintenancePlans devices={query.data?.devices ?? []} />
      <Incidents />
      {declare && <DeclareIncident devices={query.data?.devices ?? []} onClose={() => setDeclare(false)} />}
      {exporting && <ExportDialog type="incident_report" title="Rapport d’incidents" onClose={() => setExporting(false)} />}
    </>
  )
}

/** Supervision par étage ou par groupe (§10) : un clic filtre la liste des lecteurs. */
function FleetBreakdown({ title, hint, rows, active, onPick }: { title: string; hint: string; rows: { id: string; name: string; total: number; online: number; anomaly: number }[]; active: string; onPick: (id: string) => void }) {
  return (
    <section className="panel">
      <div className="panel-title"><div><h2>{title}</h2><p>{hint}</p></div></div>
      <div className="panel-body">
        {rows.length === 0 ? <p className="muted small">Aucun lecteur rattaché.</p> : (
          <ul className="breakdown-list">
            {rows.map((r) => (
              <li key={r.id}>
                <button type="button" className={`breakdown-row ${active === r.id ? 'active' : ''}`} aria-pressed={active === r.id} onClick={() => onPick(r.id)}>
                  <span>{r.name}</span>
                  <i aria-hidden="true"><b style={{ width: `${r.total ? (r.online / r.total) * 100 : 0}%` }} /></i>
                  <strong>{r.online}/{r.total}</strong>
                  {r.anomaly > 0 ? <Status tone="danger">{r.anomaly} en anomalie</Status> : r.online < r.total ? <Status tone="warning">À vérifier</Status> : <Status tone="success">Joignables</Status>}
                </button>
              </li>
            ))}
          </ul>
        )}
      </div>
    </section>
  )
}

function Health({ d }: { d: SupervisionData }) {
  const [floor, setFloor] = useState('')
  const [group, setGroup] = useState('')
  const devices = d.devices.filter((v) => (!floor || v.floor?.id === floor) && (!group || v.groups.some((g) => g.id === group)))
  const versions = Array.isArray(d.versions) ? {} : d.versions
  const totalDevices = Object.values(versions).reduce((s, n) => s + n, 0)
  return (
    <>
      <section className="panel">
        <div className="panel-title"><div><h2>Contrôles de santé</h2><p>Actualisés toutes les 15 secondes · {d.incidents} incident(s) en cours · hors ligne après {Math.round(d.thresholds.unavailable / 60 * 10) / 10} min sans battement</p></div></div>
        <div className="check-cards">
          {d.checks.map((c) => {
            const [cls, label, tone] = checkState(c)
            return (
              <article key={c.name} className={cls}>
                <header><h3>{c.name}</h3><Status tone={tone}>{label}</Status></header>
                <p>{c.detail}</p>
                {c.checked_at ? <time dateTime={c.checked_at}>Dernier contrôle : {fmtDateTime(c.checked_at)}</time> : <small>Absence de mesure</small>}
              </article>
            )
          })}
        </div>
      </section>
      <div className="two-columns report-grid section-gap">
        <FleetBreakdown title="Par étage" hint="Lecteurs joignables / appairés" rows={d.by_floor.map((f) => ({ ...f, name: f.label }))} active={floor} onPick={(id) => { setFloor(id === floor ? '' : id); setGroup('') }} />
        <FleetBreakdown title="Par groupe" hint="Un écran peut appartenir à plusieurs groupes" rows={d.by_group} active={group} onPick={(id) => { setGroup(id === group ? '' : id); setFloor('') }} />
      </div>
      <div className="two-columns report-grid section-gap">
        <section className="panel table-panel" style={{ gridColumn: 'span 1' }}>
          <div className="panel-title">
            <div><h2>Lecteurs actifs</h2><p>{devices.length} lecteur(s){floor || group ? ' (filtre appliqué)' : ' appairé(s)'}</p></div>
            {(floor || group) && <Button size="small" variant="ghost" onClick={() => { setFloor(''); setGroup('') }}>Retirer le filtre</Button>}
          </div>
          {devices.length === 0 ? <EmptyState title="Aucun lecteur actif" /> : (
            <div className="table-scroll">
              <table>
                <thead><tr><th scope="col">Lecteur</th><th scope="col">Écran</th><th scope="col">État</th><th scope="col">Réseau</th><th scope="col">Dernier contact</th><th scope="col">Version</th><th scope="col">Écart d’horloge</th><th scope="col">Stockage</th></tr></thead>
                <tbody>
                  {devices.map((v) => {
                    const skew = v.clock_skew_ms
                    return (
                      <tr key={v.id}>
                        <td className="mono">{v.code}</td>
                        <td>{v.screen_id ? <Link className="link-text" to={`/affichage/ecrans/${v.screen_id}`}>{v.screen}</Link> : '—'}<small>{v.floor?.label ?? ''}</small></td>
                        <td><StatusOf map={OPERATIONAL_STATE} value={v.state} /></td>
                        <td><StatusOf map={NETWORK_STATUS} value={v.network_status} /></td>
                        <td>{v.last_seen_at ? <>{fmtDateTime(v.last_seen_at)}<small>{fmtRelative(v.last_seen_at)}</small></> : 'Jamais'}</td>
                        <td>{v.app_version ?? '—'}</td>
                        <td className={skew !== null && Math.abs(skew) > 5000 ? 'red-text' : ''}>
                          {skew === null ? <span className="muted">non mesuré</span> : `${skew > 0 ? '+' : ''}${(skew / 1000).toLocaleString('fr-FR', { maximumFractionDigits: 1 })} s`}
                          {skew !== null && Math.abs(skew) > 5000 && <small>au-delà de ±5 s</small>}
                        </td>
                        <td>{v.storage?.usage != null ? `${fmtBytes(v.storage.usage)}${v.storage.quota ? ` / ${fmtBytes(v.storage.quota)}` : ''}` : <span className="muted">non mesuré</span>}</td>
                      </tr>
                    )
                  })}
                </tbody>
              </table>
            </div>
          )}
        </section>
        <section className="panel">
          <div className="panel-title"><div><h2>Répartition des versions</h2><p>Application lecteur</p></div></div>
          <div className="version-bars">
            {Object.entries(versions).sort().map(([version, count]) => (
              <div key={version}>
                <span className="mono">{version || 'inconnue'}</span>
                <i aria-hidden="true"><b style={{ width: `${totalDevices ? (count / totalDevices) * 100 : 0}%` }} /></i>
                <strong>{count}</strong>
              </div>
            ))}
            {totalDevices === 0 && <p className="muted small">Aucune version déclarée.</p>}
          </div>
        </section>
      </div>
    </>
  )
}

interface ErrorsData { api: { measured: boolean; requests: number; server_errors: number; error_rate: number | null; mean_ms: number | null; window_minutes: number }; errors: { at: string; level: string; message: string }[] }

/** ADM-02 : métriques de l'API sur l'heure écoulée et dernières erreurs du journal (contexte technique retiré). */
function ErrorsAndApi() {
  const query = useQuery({ queryKey: ['supervision', 'errors'], queryFn: ({ signal }) => api.get<{ data: ErrorsData }>('/supervision/errors', undefined, signal).then((r) => r.data), refetchInterval: 60_000 })
  return (
    <section className="panel table-panel">
      <div className="panel-title"><div><h2>Erreurs et API</h2><p>Dernière heure · erreurs applicatives sans données des requêtes</p></div></div>
      {query.isLoading ? <Loading /> : query.isError || !query.data ? <ErrorState error={query.error} onRetry={() => query.refetch()} /> : (
        <>
          <div className="panel-body">
            {!query.data.api.measured && <Notice tone="info">Métriques de l’API non mesurées : elles exigent un cache Redis (configuration de production). Le cache en base de données ne les tient pas, pour ne pas ralentir chaque requête.</Notice>}
            {query.data.api.measured && <Kv items={[
              ['Requêtes', String(query.data.api.requests)],
              ['Erreurs serveur (5xx)', `${query.data.api.server_errors}${query.data.api.error_rate !== null ? ` (${String(query.data.api.error_rate).replace('.', ',')} %)` : ''}`],
              ['Temps de réponse moyen', query.data.api.mean_ms !== null ? `${query.data.api.mean_ms} ms` : 'Non mesuré'],
            ]} />}
          </div>
          <div className="table-scroll">
            <table>
              <thead><tr><th scope="col">Date</th><th scope="col">Niveau</th><th scope="col">Message</th></tr></thead>
              <tbody>
                {query.data.errors.map((e, i) => <tr key={i}><td className="mono">{e.at}</td><td>{e.level === 'error' ? 'Erreur' : 'Critique'}</td><td className="small">{e.message}</td></tr>)}
                {query.data.errors.length === 0 && <tr><td colSpan={3} className="muted">Aucune erreur récente dans le journal applicatif.</td></tr>}
              </tbody>
            </table>
          </div>
        </>
      )}
    </section>
  )
}

interface Plan { id: string; title: string; instructions: string | null; category: string; frequency_days: number; next_due_on: string; last_done_on: string | null; is_active: boolean; overdue: boolean; screen: { id: string; code: string } | null }

/** INC-03 : plans de maintenance préventive ; l'échéance ouvre une intervention, sa résolution recale la suivante. */
function MaintenancePlans({ devices }: { devices: SupervisionData['devices'] }) {
  const { can } = useAuth()
  const client = useQueryClient()
  const toast = useToast()
  const [creating, setCreating] = useState(false)
  const query = useQuery({ queryKey: ['maintenance-plans'], queryFn: ({ signal }) => api.get<{ data: Plan[] }>('/maintenance-plans', undefined, signal).then((r) => r.data) })
  const toggle = async (plan: Plan) => {
    try {
      await api.patch(`/maintenance-plans/${plan.id}`, { is_active: !plan.is_active })
      await client.invalidateQueries({ queryKey: ['maintenance-plans'] })
      toast.success(plan.is_active ? 'Plan suspendu' : 'Plan réactivé')
    } catch (e) {
      toast.error(e)
    }
  }
  return (
    <section className="panel table-panel">
      <div className="panel-title">
        <div><h2>Maintenance préventive</h2><p>Opérations périodiques ; une intervention est ouverte à chaque échéance</p></div>
        {can('incidents.manage') && <Button size="small" icon="plus" onClick={() => setCreating(true)}>Nouveau plan</Button>}
      </div>
      {query.isLoading ? <Loading /> : query.isError || !query.data ? <ErrorState error={query.error} onRetry={() => query.refetch()} /> : (
        <div className="table-scroll">
          <table>
            <thead><tr><th scope="col">Opération</th><th scope="col">Cible</th><th scope="col">Fréquence</th><th scope="col">Prochaine échéance</th><th scope="col">Dernière réalisation</th><th scope="col"><span className="sr-only">Actions</span></th></tr></thead>
            <tbody>
              {query.data.map((p) => (
                <tr key={p.id}>
                  <td><strong>{p.title}</strong><small>{INCIDENT_CATEGORY[p.category] ?? p.category}{p.is_active ? '' : ' · suspendu'}</small></td>
                  <td>{p.screen?.code ?? 'Tout le parc'}</td>
                  <td>tous les {p.frequency_days} jour(s)</td>
                  <td>{p.overdue ? <Status tone="danger">{fmtDate(p.next_due_on)} · échue</Status> : fmtDate(p.next_due_on)}</td>
                  <td>{p.last_done_on ? fmtDate(p.last_done_on) : 'Jamais'}</td>
                  <td>{can('incidents.manage') && <Button size="small" variant="secondary" onClick={() => toggle(p)}>{p.is_active ? 'Suspendre' : 'Réactiver'}</Button>}</td>
                </tr>
              ))}
              {query.data.length === 0 && <tr><td colSpan={6} className="muted">Aucun plan de maintenance préventive.</td></tr>}
            </tbody>
          </table>
        </div>
      )}
      {creating && <NewPlan devices={devices} onClose={() => setCreating(false)} />}
    </section>
  )
}

function NewPlan({ devices, onClose }: { devices: SupervisionData['devices']; onClose: () => void }) {
  const client = useQueryClient()
  const toast = useToast()
  const [form, setForm] = useState({ title: '', instructions: '', screen_id: '', category: 'panel', frequency_days: 90, next_due_on: new Date().toISOString().slice(0, 10) })
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<ApiError | null>(null)
  const submit = async () => {
    setBusy(true)
    setError(null)
    try {
      await api.post('/maintenance-plans', { ...form, screen_id: form.screen_id || null, instructions: form.instructions || null })
      await client.invalidateQueries({ queryKey: ['maintenance-plans'] })
      toast.success('Plan créé')
      onClose()
    } catch (e) {
      setError(asApiError(e))
    } finally {
      setBusy(false)
    }
  }
  return (
    <Modal title="Nouveau plan de maintenance" kicker="MAINTENANCE PRÉVENTIVE" onClose={onClose} footer={<>
      <Button variant="secondary" onClick={onClose}>Annuler</Button>
      <Button onClick={submit} busy={busy} disabled={form.title.trim().length < 5}>Créer</Button>
    </>}>
      <div style={{ display: 'grid', gap: 12 }}>
        <Field label="Opération" required error={error?.field('title')}><input className="input" value={form.title} maxLength={200} onChange={(e) => setForm({ ...form, title: e.target.value })} /></Field>
        <Field label="Consignes" error={error?.field('instructions')}><textarea value={form.instructions} maxLength={2000} onChange={(e) => setForm({ ...form, instructions: e.target.value })} /></Field>
        <div className="grid-2">
          <Field label="Écran" error={error?.field('screen_id')}>
            <select className="input" value={form.screen_id} onChange={(e) => setForm({ ...form, screen_id: e.target.value })}>
              <option value="">Tout le parc</option>
              {devices.filter((d) => d.screen_id).map((d) => <option key={d.id} value={d.screen_id ?? ''}>{d.screen}</option>)}
            </select>
          </Field>
          <Field label="Catégorie" error={error?.field('category')}>
            <select className="input" value={form.category} onChange={(e) => setForm({ ...form, category: e.target.value })}>
              {Object.entries(INCIDENT_CATEGORY).map(([code, label]) => <option key={code} value={code}>{label}</option>)}
            </select>
          </Field>
          <Field label="Fréquence (jours)" required error={error?.field('frequency_days')}><input className="input" type="number" min={1} max={730} value={form.frequency_days} onChange={(e) => setForm({ ...form, frequency_days: Number(e.target.value) })} /></Field>
          <Field label="Première échéance" required error={error?.field('next_due_on')}><input className="input" type="date" value={form.next_due_on} onChange={(e) => setForm({ ...form, next_due_on: e.target.value })} /></Field>
        </div>
        {error && !Object.keys(error.errors).length && <Notice tone="danger">{error.message}</Notice>}
      </div>
    </Modal>
  )
}

/** §10.3 : volumes et délais moyens mesurés sur 30 jours. */
function IncidentStatsPanel() {
  const q = useQuery({ queryKey: ['incidents', 'stats'], queryFn: ({ signal }) => api.get<{ data: IncidentStats }>('/incidents/stats', undefined, signal).then((r) => r.data) })
  if (!q.data) return null
  const d = q.data
  const minutes = (m: number | null) => (m === null ? '—' : m < 120 ? `${Math.round(m)} min` : `${(m / 60).toFixed(1).replace('.', ',')} h`)
  return (
    <div className="incident-stats">
      <p className="small"><strong>{d.total}</strong> incident(s) sur 30 jours · {d.open} en cours · {d.automatic} détecté(s) automatiquement · {d.escalated} escaladé(s)</p>
      <div className="table-scroll">
        <table>
          <thead><tr><th>Gravité</th><th>Nombre</th><th>Prise en charge moyenne</th><th>Rétablissement moyen</th></tr></thead>
          <tbody>{Object.entries(d.by_severity).map(([s, v]) => <tr key={s}><td><StatusOf map={SEVERITY} value={s} /></td><td>{v.count}</td><td>{minutes(v.mean_ack_min)}</td><td>{minutes(v.mean_resolve_min)}</td></tr>)}</tbody>
        </table>
      </div>
    </div>
  )
}

function Incidents() {
  const [status, setStatus] = useState('open,acknowledged')
  const [severity, setSeverity] = useState('')
  const [selected, setSelected] = useState<Incident | null>(null)
  const list = useList<Incident>('incidents', '/incidents', { status: status || undefined, severity: severity || undefined })
  return (
    <section className="panel table-panel section-gap">
      <div className="panel-title"><div><h2>Incidents</h2><p>Automatiques (détectés par la plateforme) et déclarés</p></div></div>
      <IncidentStatsPanel />
      <div className="toolbar">
        <Select label="État" value={status} onChange={(v) => { setStatus(v); list.setPage(1) }} allLabel="Tous les états" options={[
          { value: 'open,acknowledged', label: 'En cours' }, { value: 'open', label: 'Ouverts' }, { value: 'acknowledged', label: 'Acquittés' }, { value: 'resolved', label: 'Résolus' }, { value: 'closed', label: 'Clos' },
        ]} />
        <Select label="Gravité" value={severity} onChange={(v) => { setSeverity(v); list.setPage(1) }} allLabel="Toutes gravités" options={[{ value: 'critical', label: 'Critique' }, { value: 'major', label: 'Majeur' }, { value: 'medium', label: 'Moyen' }, { value: 'minor', label: 'Mineur' }]} />
      </div>
      {list.isLoading ? <Loading /> : list.isError ? <ErrorState error={list.error} onRetry={() => list.refetch()} /> : list.items.length === 0 ? <EmptyState title="Aucun incident" text="Aucun incident ne correspond à ces critères." /> : (
        <>
          <div className="table-scroll">
            <table>
              <thead><tr><th scope="col">Référence</th><th scope="col">Incident</th><th scope="col">Gravité</th><th scope="col">État</th><th scope="col">Équipement</th><th scope="col">Assigné à</th><th scope="col">Détecté</th><th scope="col"><span className="sr-only">Actions</span></th></tr></thead>
              <tbody>
                {list.items.map((i) => (
                  <tr key={i.id}>
                    <td className="mono">{i.reference}</td>
                    <td><strong>{i.title}</strong><small>{i.is_automatic ? 'Détection automatique' : 'Déclaration manuelle'}{i.impact ? ` · ${i.impact}` : ''}</small></td>
                    <td><StatusOf map={SEVERITY} value={i.severity} /></td>
                    <td><StatusOf map={INCIDENT_STATUS} value={i.status} />{i.overdue && <small className="red-text">échéance dépassée</small>}{(i.escalation_level ?? 0) > 0 && <small>escaladé (niveau {i.escalation_level})</small>}</td>
                    <td>{i.screen?.code ?? '—'}{i.device && <small>{i.device}</small>}</td>
                    <td>{i.assignee?.name ?? <span className="muted">Non assigné</span>}</td>
                    <td>{fmtDateTime(i.detected_at)}</td>
                    <td><Button size="small" variant="secondary" onClick={() => setSelected(i)}>Ouvrir</Button></td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
          <Pagination meta={list.meta} onPage={list.setPage} />
        </>
      )}
      {selected && <IncidentDetail incident={selected} onUpdated={setSelected} onClose={() => setSelected(null)} />}
    </section>
  )
}

function IncidentDetail({ incident, onUpdated, onClose }: { incident: Incident; onUpdated: (i: Incident) => void; onClose: () => void }) {
  const { can } = useAuth()
  const client = useQueryClient()
  const toast = useToast()
  const refs = useReferentials(can('incidents.manage'))
  const [action, setAction] = useState<(typeof ACTIONS)[number]['action'] | null>(null)
  const [note, setNote] = useState('')
  const [assignee, setAssignee] = useState(incident.assignee?.id ?? '')
  const [qualify, setQualify] = useState({ severity: incident.severity, category: incident.category })
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<ApiError | null>(null)
  const allowed = ACTIONS.filter((a) => a.from.includes(incident.status))

  const submit = async () => {
    if (!action) return
    setBusy(true)
    setError(null)
    try {
      // La fenêtre suit la version renvoyée par le serveur, même si l'incident sort du filtre de la liste.
      const res = await api.patch<{ data: Incident }>(`/incidents/${incident.id}`, { action, note: note || null, assignee_id: action === 'assign' ? assignee : undefined, ...(action === 'qualify' ? qualify : {}) })
      onUpdated(res.data)
      toast.success('Incident mis à jour', EVENT_LABEL[action])
      setAction(null)
      setNote('')
      await client.invalidateQueries({ queryKey: ['incidents'] })
      await client.invalidateQueries({ queryKey: ['supervision'] })
    } catch (e) {
      setError(asApiError(e))
    } finally {
      setBusy(false)
    }
  }

  return (
    <Modal title={incident.title} kicker={incident.reference} onClose={onClose} wide>
      <div className="grid-2">
        <dl className="kv">
          <dt>État</dt><dd><StatusOf map={INCIDENT_STATUS} value={incident.status} /></dd>
          <dt>Gravité</dt><dd><StatusOf map={SEVERITY} value={incident.severity} /></dd>
          <dt>Origine</dt><dd>{incident.is_automatic ? 'Détection automatique' : 'Déclaration manuelle'}</dd>
          <dt>Catégorie</dt><dd>{INCIDENT_CATEGORY[incident.category] ?? incident.category} · {incident.maintenance_type === 'preventive' ? 'maintenance préventive' : 'maintenance corrective'}</dd>
          <dt>Écran / lecteur</dt><dd>{incident.screen?.code ?? '—'} {incident.device ? `· ${incident.device}` : ''}</dd>
          <dt>Impact</dt><dd>{incident.impact ?? '—'}</dd>
          <dt>Assigné à</dt><dd>{incident.assignee?.name ?? 'Non assigné'}</dd>
        </dl>
        <dl className="kv">
          <dt>Détecté</dt><dd>{fmtDateTime(incident.detected_at)}</dd>
          <dt>Acquitté</dt><dd>{fmtDateTime(incident.acknowledged_at)}</dd>
          <dt>Résolu</dt><dd>{fmtDateTime(incident.resolved_at)}</dd>
          <dt>Vérifié</dt><dd>{incident.verified_at ? `${fmtDateTime(incident.verified_at)} · ${incident.verified_by?.name ?? ''}` : '—'}</dd>
          <dt>Échéance de prise en charge</dt><dd>{incident.due_at ? <>{fmtDateTime(incident.due_at)}{incident.overdue && <span className="red-text"> · dépassée</span>}</> : '—'}{(incident.escalation_level ?? 0) > 0 && ` · escaladé (niveau ${incident.escalation_level})`}</dd>
          <dt>Clos</dt><dd>{fmtDateTime(incident.closed_at)}</dd>
        </dl>
      </div>
      {incident.description && <p style={{ marginTop: 12 }}>{incident.description}</p>}
      {incident.diagnosis && <Notice tone="info"><strong>Diagnostic :</strong> {incident.diagnosis}</Notice>}
      {incident.resolution && <Notice tone="success"><strong>Résultat du traitement :</strong> {incident.resolution}</Notice>}
      <h3 className="small" style={{ marginTop: 16, fontWeight: 700 }}>Historique</h3>
      <div className="event-list">
        {incident.events.map((e, i) => (
          <div key={i}><time>{fmtDateTime(e.at)}</time><span><strong>{EVENT_LABEL[e.action] ?? e.action}</strong> · {e.by}{e.note ? ` — ${e.note}` : ''}</span></div>
        ))}
        {incident.events.length === 0 && <p className="muted small">Aucun événement.</p>}
      </div>
      {can('incidents.manage') && (
        allowed.length === 0 ? <div style={{ marginTop: 14 }}><Notice tone="info">Incident clos : aucune action possible.</Notice></div> : (
          <div className="mini-form">
            <h3>Agir sur l’incident</h3>
            <div className="chip-row" role="group" aria-label="Action">
              {allowed.map((a) => <Button key={a.action} size="small" variant={action === a.action ? 'primary' : 'secondary'} onClick={() => setAction(a.action)}>{a.label}</Button>)}
            </div>
            {action && (
              <>
                {action === 'assign' && (
                  <Field label="Assigner à" required error={error?.field('assignee_id')}>
                    <select className="input" value={assignee} onChange={(e) => setAssignee(e.target.value)}>
                      <option value="">— Choisir —</option>
                      {(refs.data?.users ?? []).map((u) => <option key={u.id} value={u.id}>{u.name}</option>)}
                    </select>
                  </Field>
                )}
                {action === 'qualify' && (
                  <div className="form-grid">
                    <Field label="Gravité" required><select value={qualify.severity} onChange={(e) => setQualify({ ...qualify, severity: e.target.value })}><option value="critical">Critique</option><option value="major">Majeur</option><option value="medium">Moyen</option><option value="minor">Mineur</option></select></Field>
                    <Field label="Catégorie" required><select value={qualify.category} onChange={(e) => setQualify({ ...qualify, category: e.target.value })}>{Object.entries(CATEGORY_LABEL).map(([c, l]) => <option key={c} value={c}>{l}</option>)}</select></Field>
                  </div>
                )}
                {action === 'close' && !incident.verified_at && <Notice tone="warning">Vérifiez d’abord le fonctionnement rétabli (« Vérifier le fonctionnement »).</Notice>}
                <Field label={action === 'comment' ? 'Commentaire' : action === 'diagnose' ? 'Diagnostic' : action === 'resolve' ? 'Résultat du traitement' : 'Note (facultative)'} required={NOTE_REQUIRED.includes(action)} error={error?.field('note')}>
                  <textarea value={note} onChange={(e) => setNote(e.target.value)} maxLength={1000} />
                </Field>
                {error && <Notice tone="danger">{error.message}</Notice>}
                <div className="form-actions">
                  <Button variant="secondary" onClick={() => setAction(null)}>Annuler</Button>
                  <Button onClick={submit} busy={busy} disabled={(action === 'assign' && !assignee) || (NOTE_REQUIRED.includes(action) && !note.trim())}>{ACTIONS.find((a) => a.action === action)?.label}</Button>
                </div>
              </>
            )}
          </div>
        )
      )}
    </Modal>
  )
}

function DeclareIncident({ devices, onClose }: { devices: DeviceRow[]; onClose: () => void }) {
  const client = useQueryClient()
  const toast = useToast()
  const [form, setForm] = useState({ title: '', description: '', severity: 'minor', screen_id: '', impact: '', category: 'other', maintenance_type: 'corrective' })
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<ApiError | null>(null)
  const screens = devices.filter((d) => d.screen_id)
  const submit = async () => {
    setBusy(true)
    setError(null)
    try {
      await api.post('/incidents', { ...form, screen_id: form.screen_id || null, description: form.description || null, impact: form.impact || null })
      toast.success('Incident déclaré')
      await client.invalidateQueries({ queryKey: ['incidents'] })
      await client.invalidateQueries({ queryKey: ['supervision'] })
      onClose()
    } catch (e) {
      setError(asApiError(e))
    } finally {
      setBusy(false)
    }
  }
  return (
    <Modal title="Déclarer un incident" kicker="SUPERVISION" onClose={onClose} footer={<>
      <Button variant="secondary" onClick={onClose}>Annuler</Button>
      <Button onClick={submit} busy={busy} disabled={!form.title.trim()}>Déclarer</Button>
    </>}>
      <div style={{ display: 'grid', gap: 12 }}>
        <Field label="Intitulé" required error={error?.field('title')}><input className="input" value={form.title} maxLength={200} onChange={(e) => setForm({ ...form, title: e.target.value })} /></Field>
        <Field label="Gravité" required error={error?.field('severity')}>
          <select className="input" value={form.severity} onChange={(e) => setForm({ ...form, severity: e.target.value })}>
            <option value="critical">Critique</option><option value="major">Majeur</option><option value="medium">Moyen</option><option value="minor">Mineur</option>
          </select>
        </Field>
        <div className="grid-2">
          <Field label="Catégorie" error={error?.field('category')}>
            <select className="input" value={form.category} onChange={(e) => setForm({ ...form, category: e.target.value })}>
              {Object.entries(INCIDENT_CATEGORY).map(([code, label]) => <option key={code} value={code}>{label}</option>)}
            </select>
          </Field>
          <Field label="Nature" error={error?.field('maintenance_type')}>
            <select className="input" value={form.maintenance_type} onChange={(e) => setForm({ ...form, maintenance_type: e.target.value })}>
              <option value="corrective">Maintenance corrective</option><option value="preventive">Maintenance préventive</option>
            </select>
          </Field>
        </div>
        <Field label="Écran concerné" error={error?.field('screen_id')}>
          <select className="input" value={form.screen_id} onChange={(e) => setForm({ ...form, screen_id: e.target.value })}>
            <option value="">Aucun écran précis</option>
            {screens.map((d) => <option key={d.id} value={d.screen_id ?? ''}>{d.screen} ({d.code})</option>)}
          </select>
        </Field>
        <Field label="Impact" error={error?.field('impact')}><input className="input" value={form.impact} maxLength={200} onChange={(e) => setForm({ ...form, impact: e.target.value })} /></Field>
        <Field label="Description" error={error?.field('description')}><textarea value={form.description} maxLength={2000} onChange={(e) => setForm({ ...form, description: e.target.value })} /></Field>
        {error && <Notice tone="danger">{error.message}</Notice>}
        <div className="footer-note"><Icon name="shield" size={14} /> Déclaration tracée dans le journal</div>
      </div>
    </Modal>
  )
}

/** §10.22 : état du flux temps réel ; hors connexion, la page se rafraîchit périodiquement. */
function RealtimeBadge() {
  const status = useRealtimeStatus()
  return status === 'live'
    ? <Status tone="success">Temps réel</Status>
    : <span title="Flux temps réel indisponible : actualisation automatique toutes les 15 secondes"><Status tone="neutral">{status === 'connecting' ? 'Connexion au temps réel…' : 'Actualisation périodique'}</Status></span>
}
