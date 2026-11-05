import { useState } from 'react'
import { Link } from 'react-router'
import { useQuery, useQueryClient } from '@tanstack/react-query'
import { api, type ApiError } from '../../lib/api'
import { useAuth } from '../../lib/auth'
import { useList } from '../../lib/hooks'
import { fmtBytes, fmtDateTime, fmtRelative, NETWORK_STATUS, SEVERITY } from '../../lib/format'
import { Button, EmptyState, ErrorState, Field, Icon, Loading, Modal, Notice, PageHeading, Pagination, Select, Status, StatusOf, useToast } from '../../components/ui'
import { asApiError, ExportDialog, useReferentials } from './common'
import { PlayerReleases } from './PlayerReleases'
import './admin.css'

interface Check { name: string; ok: boolean; warn: boolean; detail: string; checked_at: string | null }
interface DeviceRow {
  id: string; code: string; screen: string | null; screen_id: string | null; network_status: string; last_seen_at: string | null; app_version: string | null
  clock_skew_ms: number | null; storage: { usage?: number | null; quota?: number | null; persisted?: boolean | null } | null; errors: unknown
}
interface SupervisionData { checks: Check[]; devices: DeviceRow[]; versions: Record<string, number> | []; incidents: number }
interface Incident {
  id: string; reference: string; title: string; description: string | null; kind: string; severity: string; status: string; impact: string | null
  screen: { id: string; code: string } | null; device: string | null; assignee: { id: string; name: string; initials: string } | null
  detected_at: string | null; acknowledged_at: string | null; resolved_at: string | null; closed_at: string | null
  events: { at: string; by: string; action: string; note?: string | null }[]; is_automatic: boolean
}

export const INCIDENT_STATUS: Record<string, [string, string]> = { open: ['Ouvert', 'danger'], acknowledged: ['Acquitté', 'warning'], resolved: ['Résolu', 'success'], closed: ['Clos', 'neutral'] }
const ACTIONS: { action: 'assign' | 'acknowledge' | 'resolve' | 'close' | 'comment'; label: string; from: string[] }[] = [
  { action: 'assign', label: 'Assigner', from: ['open', 'acknowledged'] },
  { action: 'acknowledge', label: 'Acquitter', from: ['open'] },
  { action: 'resolve', label: 'Résoudre', from: ['open', 'acknowledged'] },
  { action: 'close', label: 'Clore', from: ['resolved'] },
  { action: 'comment', label: 'Commenter', from: ['open', 'acknowledged', 'resolved'] },
]
const EVENT_LABEL: Record<string, string> = { assign: 'Assignation', acknowledge: 'Acquittement', resolve: 'Résolution', close: 'Clôture', comment: 'Commentaire' }

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
  const query = useQuery({ queryKey: ['supervision'], queryFn: ({ signal }) => api.get<{ data: SupervisionData }>('/supervision', undefined, signal).then((r) => r.data), refetchInterval: 60_000 })

  return (
    <>
      <PageHeading
        title="Supervision"
        description="Santé de la plateforme, état des lecteurs et suivi des incidents."
        actions={<>
          <Button variant="secondary" icon="download" onClick={() => setExporting(true)}>Rapport d’incidents (PDF08)</Button>
          {can('incidents.manage') && <Button icon="plus" onClick={() => setDeclare(true)}>Déclarer un incident</Button>}
        </>}
      />
      {query.isLoading ? <Loading /> : query.isError || !query.data ? <ErrorState error={query.error} onRetry={() => query.refetch()} /> : <Health d={query.data} />}
      <PlayerReleases />
      <Incidents />
      {declare && <DeclareIncident devices={query.data?.devices ?? []} onClose={() => setDeclare(false)} />}
      {exporting && <ExportDialog type="incident_report" title="Rapport d’incidents" onClose={() => setExporting(false)} />}
    </>
  )
}

function Health({ d }: { d: SupervisionData }) {
  const versions = Array.isArray(d.versions) ? {} : d.versions
  const totalDevices = Object.values(versions).reduce((s, n) => s + n, 0)
  return (
    <>
      <section className="panel">
        <div className="panel-title"><div><h2>Contrôles de santé</h2><p>Actualisés toutes les minutes · {d.incidents} incident(s) en cours</p></div></div>
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
        <section className="panel table-panel" style={{ gridColumn: 'span 1' }}>
          <div className="panel-title"><div><h2>Lecteurs actifs</h2><p>{d.devices.length} lecteur(s) appairé(s)</p></div></div>
          {d.devices.length === 0 ? <EmptyState title="Aucun lecteur actif" /> : (
            <div className="table-scroll">
              <table>
                <thead><tr><th scope="col">Lecteur</th><th scope="col">Écran</th><th scope="col">Réseau</th><th scope="col">Dernier contact</th><th scope="col">Version</th><th scope="col">Écart d’horloge</th><th scope="col">Stockage</th></tr></thead>
                <tbody>
                  {d.devices.map((v) => {
                    const skew = v.clock_skew_ms
                    return (
                      <tr key={v.id}>
                        <td className="mono">{v.code}</td>
                        <td>{v.screen_id ? <Link className="link-text" to={`/affichage/ecrans/${v.screen_id}`}>{v.screen}</Link> : '—'}</td>
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

function Incidents() {
  const [status, setStatus] = useState('open,acknowledged')
  const [severity, setSeverity] = useState('')
  const [selected, setSelected] = useState<Incident | null>(null)
  const list = useList<Incident>('incidents', '/incidents', { status: status || undefined, severity: severity || undefined })
  return (
    <section className="panel table-panel section-gap">
      <div className="panel-title"><div><h2>Incidents</h2><p>Automatiques (détectés par la plateforme) et déclarés</p></div></div>
      <div className="toolbar">
        <Select label="État" value={status} onChange={(v) => { setStatus(v); list.setPage(1) }} allLabel="Tous les états" options={[
          { value: 'open,acknowledged', label: 'En cours' }, { value: 'open', label: 'Ouverts' }, { value: 'acknowledged', label: 'Acquittés' }, { value: 'resolved', label: 'Résolus' }, { value: 'closed', label: 'Clos' },
        ]} />
        <Select label="Gravité" value={severity} onChange={(v) => { setSeverity(v); list.setPage(1) }} allLabel="Toutes gravités" options={[{ value: 'critical', label: 'Critique' }, { value: 'major', label: 'Majeur' }, { value: 'minor', label: 'Mineur' }]} />
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
                    <td><StatusOf map={INCIDENT_STATUS} value={i.status} /></td>
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
      {selected && <IncidentDetail incident={list.items.find((x) => x.id === selected.id) ?? selected} onClose={() => setSelected(null)} />}
    </section>
  )
}

function IncidentDetail({ incident, onClose }: { incident: Incident; onClose: () => void }) {
  const { can } = useAuth()
  const client = useQueryClient()
  const toast = useToast()
  const refs = useReferentials(can('incidents.manage'))
  const [action, setAction] = useState<(typeof ACTIONS)[number]['action'] | null>(null)
  const [note, setNote] = useState('')
  const [assignee, setAssignee] = useState(incident.assignee?.id ?? '')
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<ApiError | null>(null)
  const allowed = ACTIONS.filter((a) => a.from.includes(incident.status))

  const submit = async () => {
    if (!action) return
    setBusy(true)
    setError(null)
    try {
      await api.patch(`/incidents/${incident.id}`, { action, note: note || null, assignee_id: action === 'assign' ? assignee : undefined })
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
          <dt>Origine</dt><dd>{incident.is_automatic ? 'Détection automatique' : 'Déclaration manuelle'} ({incident.kind})</dd>
          <dt>Écran / lecteur</dt><dd>{incident.screen?.code ?? '—'} {incident.device ? `· ${incident.device}` : ''}</dd>
          <dt>Impact</dt><dd>{incident.impact ?? '—'}</dd>
          <dt>Assigné à</dt><dd>{incident.assignee?.name ?? 'Non assigné'}</dd>
        </dl>
        <dl className="kv">
          <dt>Détecté</dt><dd>{fmtDateTime(incident.detected_at)}</dd>
          <dt>Acquitté</dt><dd>{fmtDateTime(incident.acknowledged_at)}</dd>
          <dt>Résolu</dt><dd>{fmtDateTime(incident.resolved_at)}</dd>
          <dt>Clos</dt><dd>{fmtDateTime(incident.closed_at)}</dd>
        </dl>
      </div>
      {incident.description && <p style={{ marginTop: 12 }}>{incident.description}</p>}
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
                <Field label={action === 'comment' ? 'Commentaire' : 'Note (facultative)'} required={action === 'comment'} error={error?.field('note')}>
                  <textarea value={note} onChange={(e) => setNote(e.target.value)} maxLength={1000} />
                </Field>
                {error && <Notice tone="danger">{error.message}</Notice>}
                <div className="form-actions">
                  <Button variant="secondary" onClick={() => setAction(null)}>Annuler</Button>
                  <Button onClick={submit} busy={busy} disabled={(action === 'assign' && !assignee) || (action === 'comment' && !note.trim())}>{ACTIONS.find((a) => a.action === action)?.label}</Button>
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
  const [form, setForm] = useState({ title: '', description: '', severity: 'minor', screen_id: '', impact: '' })
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
            <option value="critical">Critique</option><option value="major">Majeur</option><option value="minor">Mineur</option>
          </select>
        </Field>
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
