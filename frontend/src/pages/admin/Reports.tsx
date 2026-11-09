import { useEffect, useMemo, useRef, useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { api, type ApiError } from '../../lib/api'
import { addDays, fmtDate, fmtDateTime, fmtNumber, localParts } from '../../lib/format'
import { Button, ErrorState, Icon, Loading, Notice, PageHeading, Status, useDialogFocus } from '../../components/ui'
import { useAuth } from '../../lib/auth'
import {
  asApiError, EXPORT_FIELDS, ExportTracker, IndicatorValue, PERIOD_EXPORT_TYPES, PeriodFields, periodBounds, presetRange, requestExport,
  useExportTypes, useReferentials, type ExportItem,
} from './common'
import './admin.css'

interface Summary {
  period: { from: string; to: string; timezone: string }
  diffusion: { manifests_received: number; activations: number; plays_declared: number; errors: number; daily: Record<string, number> | [] }
  availability: { floor: string; level: number; devices: number; value: number | null; method: string }[]
  rooms: {
    rows: { room: string; floor: string; available_minutes: number; reserved_minutes: number; rate: number | null; cancellations: number; no_shows: number; services: number }[]
    rate: number | null; presence: string; method: string
  }
  incidents: { total: number; resolved: number; open: number }
  publication: { contents_created: number; versions_approved: number; returns: number; campaigns_published: number }
  indicators: { code: string; label: string; value: number | null; unit: string; target: string; basis: string; period: { from: string | null; to: string | null } }[]
  presence: { measured: boolean; label: string }
  notice: string
}

const hours = (minutes: number) => `${fmtNumber(minutes / 60, 1)} h`

/** V33 Rapports : chiffres calculés en base pour la période choisie ; aucune valeur absente n'est remplacée par une estimation. */
export default function Reports() {
  const { can } = useAuth()
  const initial = presetRange('30')
  const [draft, setDraft] = useState({ from: initial.from, to: initial.to, floor_id: '', building_id: '' })
  const [applied, setApplied] = useState(draft)
  const [modal, setModal] = useState(false)
  const refs = useReferentials()

  const query = useQuery({
    queryKey: ['reports', 'summary', applied],
    queryFn: ({ signal }) => api.get<{ data: Summary }>('/reports/summary', { ...periodBounds(applied.from, applied.to), floor_id: applied.floor_id || undefined, building_id: applied.building_id || undefined }, signal).then((r) => r.data),
  })

  return (
    <>
      <PageHeading
        title="Rapports"
        description="Diffusion, disponibilité du parc, occupation réservée des salles et indicateurs de succès."
        actions={can('exports.create', 'reports.view') && <Button icon="file" onClick={() => setModal(true)}>Générer un rapport</Button>}
      />
      <form className="report-filters" onSubmit={(e) => { e.preventDefault(); setApplied(draft) }} aria-label="Filtres du rapport">
        <PeriodFields idPrefix="rep" from={draft.from} to={draft.to} onChange={(from, to) => setDraft({ ...draft, from, to })} />
        {(refs.data?.buildings ?? []).length > 1 && (
          <label htmlFor="rep-building">
            Bâtiment
            <select id="rep-building" className="input" value={draft.building_id} onChange={(e) => setDraft({ ...draft, building_id: e.target.value, floor_id: '' })}>
              <option value="">Tous les bâtiments</option>
              {(refs.data?.buildings ?? []).map((b) => <option key={b.id} value={b.id}>{String(b.name ?? b.code)}</option>)}
            </select>
          </label>
        )}
        <label htmlFor="rep-floor">
          Niveau
          <select id="rep-floor" className="input" value={draft.floor_id} onChange={(e) => setDraft({ ...draft, floor_id: e.target.value })}>
            <option value="">Tous les niveaux</option>
            {(refs.data?.floors ?? []).filter((f) => !draft.building_id || f.building_id === draft.building_id).map((f) => <option key={f.id} value={f.id}>{String(f.label ?? f.code)}</option>)}
          </select>
        </label>
        <Button type="submit" variant="secondary" icon="filter" disabled={!draft.from || !draft.to || draft.from > draft.to}>Appliquer</Button>
      </form>
      {query.isLoading ? <Loading label="Calcul des indicateurs…" /> : query.isError || !query.data ? <ErrorState error={query.error} onRetry={() => query.refetch()} /> : <ReportBody d={query.data} floorFiltered={!!applied.floor_id || !!applied.building_id} fetching={query.isFetching} />}
      {modal && <ReportModal onClose={() => setModal(false)} from={applied.from} to={applied.to} />}
    </>
  )
}

function ReportBody({ d, floorFiltered, fetching }: { d: Summary; floorFiltered: boolean; fetching: boolean }) {
  const measured = d.availability.filter((a) => a.value !== null)
  const devices = measured.reduce((s, a) => s + a.devices, 0)
  const avgAvailability = devices > 0 ? Math.round((measured.reduce((s, a) => s + (a.value ?? 0) * a.devices, 0) / devices) * 10) / 10 : null
  const daily = Array.isArray(d.diffusion.daily) ? {} : d.diffusion.daily

  const bars = useMemo(() => {
    const start = localParts(d.period.from).date
    const end = localParts(d.period.to).date
    const days: string[] = []
    for (let day = start, i = 0; day <= end && i < 62; day = addDays(day, 1), i++) days.push(day)
    const keys = days.length < 62 ? days : Object.keys(daily).sort()
    return keys.map((k) => ({ day: k, value: Number(daily[k] ?? 0) }))
  }, [d.period.from, d.period.to, daily])
  const max = Math.max(1, ...bars.map((b) => b.value))

  return (
    <div aria-busy={fetching}>
      <p className="muted small" style={{ marginBottom: 10 }}>
        Période du {fmtDateTime(d.period.from)} au {fmtDateTime(d.period.to)} · fuseau {d.period.timezone}{fetching ? ' · actualisation…' : ''}
      </p>
      <div className="stat-grid compact">
        <div className={`metric-card ${avgAvailability === null ? 'unavailable' : ''}`}>
          <span>Disponibilité lecteurs (moyenne)</span>
          <strong><IndicatorValue value={avgAvailability} unit="%" /></strong>
          <small>{devices} lecteur(s) mesuré(s) · relevés de télémétrie</small>
        </div>
        <div className="metric-card">
          <span>Activations déclarées</span>
          <strong>{fmtNumber(d.diffusion.activations)}</strong>
          <small>{fmtNumber(d.diffusion.plays_declared)} lecture(s) · {fmtNumber(d.diffusion.manifests_received)} programme(s) reçu(s) · {fmtNumber(d.diffusion.errors)} erreur(s)</small>
        </div>
        <div className={`metric-card ${d.rooms.rate === null ? 'unavailable' : ''}`}>
          <span>Taux d’occupation réservé</span>
          <strong><IndicatorValue value={d.rooms.rate} unit="%" /></strong>
          <small>{d.rooms.presence || d.presence.label}</small>
        </div>
        <div className="metric-card">
          <span>Incidents</span>
          <strong>{fmtNumber(d.incidents.total)}</strong>
          <small>{d.incidents.resolved} résolu(s) ou clos · {d.incidents.open} ouvert(s)</small>
        </div>
      </div>

      <div className="two-columns report-grid">
        <section className="panel">
          <div className="panel-title">
            <div><h2>Lectures déclarées par jour</h2><p>Déclarations des lecteurs{floorFiltered ? ' du niveau sélectionné' : ''}</p></div>
          </div>
          {bars.every((b) => b.value === 0) ? (
            <p className="muted small" style={{ padding: '0 19px 18px' }}>Aucune lecture déclarée sur la période.</p>
          ) : (
            <div className="bar-chart" role="img" aria-label={`Lectures déclarées par jour : maximum ${fmtNumber(max)} sur ${bars.length} jour(s)`}>
              {bars.map((b) => (
                <div key={b.day} title={`${fmtDate(b.day + 'T12:00:00+01:00')} : ${fmtNumber(b.value)} lecture(s) déclarée(s)`}>
                  <i style={{ height: `${(b.value / max) * 85}%` }} />
                  <span>{b.day.slice(8, 10)}</span>
                </div>
              ))}
            </div>
          )}
          <div className="chart-legend">
            <span><i />Lecture déclarée par un lecteur</span>
            <b>Une activation n’est pas une preuve de vue humaine.</b>
          </div>
          <details className="chart-data">
            <summary>Afficher les données du graphique</summary>
            <div className="table-scroll" style={{ maxHeight: 220 }}>
              <table>
                <thead><tr><th scope="col">Jour</th><th scope="col">Lectures déclarées</th></tr></thead>
                <tbody>{bars.map((b) => <tr key={b.day}><td>{fmtDate(b.day + 'T12:00:00+01:00')}</td><td>{fmtNumber(b.value)}</td></tr>)}</tbody>
              </table>
            </div>
          </details>
        </section>
        <section className="panel">
          <div className="panel-title">
            <div><h2>Disponibilité par niveau</h2><p>{d.availability[0]?.method ?? 'Relevés de télémétrie / créneaux de 5 min attendus'}</p></div>
          </div>
          <div className="availability-list">
            {d.availability.map((a) => (
              <div key={a.floor}>
                <span>{a.floor}<small>{a.devices} lecteur(s)</small></span>
                <i aria-hidden="true"><b style={{ width: `${a.value ?? 0}%`, background: a.value !== null && a.value < 95 ? 'var(--amber)' : undefined }} /></i>
                <strong>{a.value === null ? <span className="muted">n.d.</span> : `${fmtNumber(a.value, 1)} %`}</strong>
              </div>
            ))}
            {d.availability.length === 0 && <p className="muted small">Aucun lecteur actif rattaché à un niveau.</p>}
          </div>
        </section>
      </div>

      <section className="panel table-panel section-gap">
        <div className="panel-title">
          <div><h2>Occupation réservée des salles</h2><p>{d.rooms.method} · {d.rooms.presence}</p></div>
        </div>
        <div className="table-scroll">
          <table>
            <thead><tr><th scope="col">Salle</th><th scope="col">Niveau</th><th scope="col">Ouverture nette</th><th scope="col">Réservé confirmé</th><th scope="col">Taux réservé</th><th scope="col">Annulations</th><th scope="col">Non honorées</th><th scope="col">Prestations</th></tr></thead>
            <tbody>
              {d.rooms.rows.map((r) => (
                <tr key={r.room + r.floor}>
                  <td><strong>{r.room}</strong></td>
                  <td>{r.floor}</td>
                  <td>{hours(r.available_minutes)}</td>
                  <td>{hours(r.reserved_minutes)}</td>
                  <td><IndicatorValue value={r.rate} unit="%" /></td>
                  <td>{r.cancellations}</td>
                  <td>{r.no_shows}</td>
                  <td>{r.services}</td>
                </tr>
              ))}
              {d.rooms.rows.length === 0 && <tr><td colSpan={8} className="muted">Aucune salle active sur ce périmètre.</td></tr>}
            </tbody>
          </table>
        </div>
      </section>

      <div className="two-columns report-grid section-gap">
        <section className="panel">
          <div className="panel-title"><div><h2>Publication</h2><p>Circuit éditorial sur la période</p></div></div>
          <div className="panel-body">
            <dl className="kv">
              <dt>Contenus créés</dt><dd>{fmtNumber(d.publication.contents_created)}</dd>
              <dt>Versions approuvées</dt><dd>{fmtNumber(d.publication.versions_approved)}</dd>
              <dt>Retours pour correction</dt><dd>{fmtNumber(d.publication.returns)}</dd>
              <dt>Campagnes publiées</dt><dd>{fmtNumber(d.publication.campaigns_published)}</dd>
            </dl>
          </div>
        </section>
        <section className="panel">
          <div className="panel-title"><div><h2>Niveaux de preuve</h2><p>Lecture des chiffres</p></div></div>
          <div className="panel-body" style={{ display: 'grid', gap: 8 }}>
            <Notice tone="warning">{d.notice}</Notice>
            <Notice tone="info">{d.presence.label} : le taux de salle reflète des réservations confirmées, pas une présence constatée.</Notice>
          </div>
        </section>
      </div>

      <section className="panel table-panel section-gap">
        <div className="panel-title">
          <div><h2>Indicateurs de succès</h2><p>Valeur, cible, période de référence et base de calcul</p></div>
        </div>
        <div className="table-scroll">
          <table>
            <thead><tr><th scope="col">Indicateur</th><th scope="col">Valeur</th><th scope="col">Cible</th><th scope="col">Période</th><th scope="col">Base de calcul</th></tr></thead>
            <tbody>
              {d.indicators.map((i) => (
                <tr key={i.code}>
                  <td><strong>{i.label}</strong></td>
                  <td><IndicatorValue value={i.value} unit={i.unit} /></td>
                  <td><Status tone="neutral">Cible {i.target}</Status></td>
                  <td>{i.period.from ? (i.period.from === i.period.to ? `Instantané du ${fmtDateTime(i.period.from)}` : `${fmtDate(i.period.from)} → ${fmtDate(i.period.to)}`) : `Depuis l’origine jusqu’au ${fmtDate(i.period.to)}`}</td>
                  <td className="muted">{i.basis}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </section>
    </div>
  )
}

const STEPS = ['Périmètre', 'Format', 'Aperçu', 'Génération']

/** Parcours « Générer un rapport » (ReportStep de la maquette) branché sur POST /exports puis suivi GET /exports/:id. */
function ReportModal({ onClose, from: initialFrom, to: initialTo }: { onClose: () => void; from: string; to: string }) {
  const types = useExportTypes()
  const available = (types.data ?? []).filter((t) => t.allowed && PERIOD_EXPORT_TYPES.includes(t.type))
  const [step, setStep] = useState(0)
  const [type, setType] = useState('')
  const [format, setFormat] = useState('pdf')
  const [from, setFrom] = useState(initialFrom)
  const [to, setTo] = useState(initialTo)
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<ApiError | null>(null)
  const [created, setCreated] = useState<ExportItem | null>(null)
  const def = available.find((t) => t.type === type)

  useEffect(() => {
    if (!type && available.length) setType(available[0].type)
  }, [available, type])
  useEffect(() => {
    if (def && !def.formats.includes(format)) setFormat(def.formats[0])
  }, [def, format])
  const dialogRef = useRef<HTMLDivElement>(null)
  useDialogFocus(dialogRef, onClose)

  const generate = async () => {
    setBusy(true)
    setError(null)
    try {
      setCreated(await requestExport(type, format, periodBounds(from, to)))
      setStep(3)
    } catch (e) {
      setError(asApiError(e))
    } finally {
      setBusy(false)
    }
  }
  const canNext = step === 0 ? !!def && !!from && !!to && from <= to : true

  return (
    <div className="modal-backdrop" role="dialog" aria-modal="true" aria-labelledby="report-modal-title">
      <div className="flow-modal" ref={dialogRef}>
        <header>
          <div><small>PARCOURS</small><h2 id="report-modal-title">Générer un rapport</h2></div>
          <button className="icon-button" onClick={onClose} aria-label="Fermer"><Icon name="x" /></button>
        </header>
        <div className="flow-progress" role="list" aria-label="Étapes">
          {STEPS.map((s, i) => (
            <div key={s} role="listitem" className={i < step ? 'done' : i === step ? 'active' : ''} aria-current={i === step ? 'step' : undefined}>
              <span>{i < step ? <Icon name="check" size={13} /> : i + 1}</span>
              <b>{s}</b>
            </div>
          ))}
        </div>
        <div className="flow-content">
          <div className="step-label">ÉTAPE {step + 1} SUR {STEPS.length}</div>
          {types.isLoading ? <Loading /> : types.isError ? <ErrorState error={types.error} onRetry={() => types.refetch()} /> : available.length === 0 ? (
            <Notice tone="warning">Aucun rapport périodique n’est autorisé pour vos habilitations.</Notice>
          ) : step === 0 ? (
            <>
              <h3>Périmètre du rapport</h3>
              <p>Les données seront figées à l’instant de génération.</p>
              <div className="form-grid">
                <label htmlFor="rm-type" className="wide">
                  Type de document
                  <select id="rm-type" value={type} onChange={(e) => setType(e.target.value)}>
                    {available.map((t) => <option key={t.type} value={t.type}>{t.label}</option>)}
                  </select>
                </label>
                <PeriodFields idPrefix="rm" from={from} to={to} onChange={(f, t) => { setFrom(f); setTo(t) }} />
              </div>
            </>
          ) : step === 1 ? (
            <>
              <h3>Format</h3>
              <p>PDF pour la diffusion et l’archivage ; Excel (XLSX) ou CSV pour l’analyse dans un tableur.</p>
              <fieldset className="admin-fieldset">
                <legend>Format de sortie</legend>
                <div className="target-list">
                  {def?.formats.map((f) => (
                    <label key={f}>
                      <input type="radio" name="rm-format" checked={format === f} onChange={() => setFormat(f)} />
                      <span><b>{f.toUpperCase()}</b>{f === 'pdf' ? 'Document paginé, en-tête institutionnel, classification' : f === 'xlsx' ? 'Classeur Excel, une feuille par tableau' : 'Séparateur point-virgule, encodage UTF-8'}</span>
                    </label>
                  ))}
                </div>
              </fieldset>
            </>
          ) : step === 2 ? (
            <>
              <h3>Aperçu du contenu</h3>
              <p>{def?.label} · {format.toUpperCase()} · du {fmtDate(from + 'T12:00:00+01:00')} au {fmtDate(to + 'T12:00:00+01:00')}</p>
              <div className="target-list">
                {(EXPORT_FIELDS[type] ?? ['Champs définis par le modèle de document']).map((f) => (
                  <label key={f}><Icon name="check" size={15} /><span><b>{f}</b></span></label>
                ))}
              </div>
              <p className="muted small" style={{ marginTop: 12 }}>La classification du document est déterminée par le serveur selon les données incluses ; le fichier expire après le délai de conservation des exports.</p>
              {error && <div style={{ marginTop: 12 }}><Notice tone="danger">{error.message}</Notice></div>}
            </>
          ) : created ? (
            <>
              <h3>Rapport en cours de génération</h3>
              <p>Le traitement est asynchrone. Le téléchargement restera soumis à vos droits.</p>
              <ExportTracker initial={created} />
            </>
          ) : null}
        </div>
        <footer>
          {step < 3 ? <Button variant="secondary" onClick={step ? () => setStep(step - 1) : onClose}>{step ? 'Retour' : 'Annuler'}</Button> : <span />}
          <div className="footer-note"><Icon name="shield" size={14} /> Action tracée dans le journal</div>
          {step < 2 && <Button onClick={() => setStep(step + 1)} disabled={!canNext || available.length === 0}>Continuer</Button>}
          {step === 2 && <Button icon="download" onClick={generate} busy={busy}>Lancer la génération</Button>}
          {step === 3 && <Button onClick={onClose}>Terminer</Button>}
        </footer>
      </div>
    </div>
  )
}
