import { useEffect, useState, type ReactNode } from 'react'
import { useQuery, useQueryClient } from '@tanstack/react-query'
import { api, ApiError, fileUrl } from '../../lib/api'
import { addDays, fmtBytes, fmtDateTime, todayLocal } from '../../lib/format'
import { Button, Icon, Modal, Notice, Status, StatusOf } from '../../components/ui'

/* ===== Types partagés des pages d'administration et de pilotage ===== */

export type ExportStatus = 'pending' | 'processing' | 'ready' | 'failed' | 'expired' | 'cancelled'

export interface ExportItem {
  id: string
  type: string
  label: string
  format: 'pdf' | 'csv'
  params: Record<string, unknown> | null
  status: ExportStatus
  progress: number | null
  file_name: string | null
  size: number | null
  error: string | null
  classification: string | null
  created_at: string | null
  completed_at: string | null
  expires_at: string | null
}

export interface ExportType { type: string; label: string; formats: string[]; allowed: boolean }

export interface RefItem { id: string; code?: string; name?: string; label?: string; is_active?: boolean; [key: string]: unknown }

export interface Referentials {
  sites: RefItem[]
  buildings: RefItem[]
  floors: RefItem[]
  zones: RefItem[]
  locations: RefItem[]
  structures: RefItem[]
  categories: RefItem[]
  languages: RefItem[]
  service_types: RefItem[]
  holidays: RefItem[]
  equipment: RefItem[]
  users: { id: string; name: string; structure_id: string | null }[]
}

export const EXPORT_STATUS: Record<string, [string, string]> = {
  pending: ['En attente', 'neutral'], processing: ['Traitement', 'blue'], ready: ['Prêt', 'success'],
  failed: ['Échec', 'danger'], expired: ['Expiré', 'neutral'], cancelled: ['Annulé', 'neutral'],
}

/** Types d'export générables à partir d'une simple période (les autres sont liés à un objet : réservation, campagne, document). */
export const PERIOD_EXPORT_TYPES = ['diffusion_report', 'room_usage', 'room_planning', 'equipment_inventory', 'incident_report', 'audit_extract']

/** Rappel des champs inclus, d'après le chapitre 29 du cahier des charges. */
export const EXPORT_FIELDS: Record<string, string[]> = {
  diffusion_report: ['Campagnes programmées et cibles', 'Manifestes reçus par les lecteurs (horodatés)', 'Activations et lectures déclarées', 'Erreurs de lecture', 'Mention : une activation n’est pas une preuve de vue humaine'],
  room_usage: ['Minutes d’ouverture hors fermetures et jours non ouvrés', 'Minutes réservées confirmées et taux réservé', 'Annulations et réunions non honorées', 'Prestations demandées', 'Mention : présence non mesurée'],
  room_planning: ['Salles et niveaux', 'Créneaux réservés (réunions confidentielles affichées « Réservée »)', 'Fermetures et blocages'],
  equipment_inventory: ['Écrans, lecteurs et emplacements', 'Version applicative et dernier contact', 'Équipements de salles'],
  incident_report: ['Référence, gravité et état', 'Écran ou lecteur concerné', 'Détection, acquittement, résolution, clôture', 'Impact et historique des actions'],
  audit_extract: ['Date, acteur, rôle et pouvoir étendu', 'Action, objet, version et langue', 'Motif, résultat et identifiant de corrélation', 'Secrets toujours masqués'],
}

export const POWER_LABEL: Record<string, string> = { urgence: 'Urgence', modeles: 'Modèles', prestations: 'Prestations' }

export function asApiError(e: unknown): ApiError {
  return e instanceof ApiError ? e : new ApiError(0, { message: e instanceof Error ? e.message : String(e) })
}

/** Bornes ISO (fuseau institutionnel UTC+1) d'une période saisie en dates locales. */
export function periodBounds(from: string, to: string): { from?: string; to?: string } {
  return {
    from: from ? new Date(`${from}T00:00:00+01:00`).toISOString() : undefined,
    to: to ? new Date(`${to}T23:59:59+01:00`).toISOString() : undefined,
  }
}

export const PERIOD_PRESETS = [
  { value: '7', label: '7 derniers jours' },
  { value: '30', label: '30 derniers jours' },
  { value: '90', label: '90 derniers jours' },
  { value: '365', label: '12 derniers mois' },
  { value: 'custom', label: 'Période personnalisée' },
]

export function presetRange(days: string): { from: string; to: string } {
  const to = todayLocal()
  return { from: addDays(to, -Number(days || 30) + 1), to }
}

/** Champ de période (préréglage + dates) utilisable dans les filtres et les demandes d'export. */
export function PeriodFields({ from, to, onChange, idPrefix }: { from: string; to: string; onChange: (from: string, to: string) => void; idPrefix: string }) {
  const [preset, setPreset] = useState('custom')
  return (
    <>
      <label htmlFor={`${idPrefix}-preset`}>
        Période
        <select id={`${idPrefix}-preset`} className="input" value={preset} onChange={(e) => {
          setPreset(e.target.value)
          if (e.target.value !== 'custom') {
            const r = presetRange(e.target.value)
            onChange(r.from, r.to)
          }
        }}>
          {PERIOD_PRESETS.map((p) => <option key={p.value} value={p.value}>{p.label}</option>)}
        </select>
      </label>
      <label htmlFor={`${idPrefix}-from`}>
        Du
        <input id={`${idPrefix}-from`} className="input" type="date" value={from} max={to || undefined} onChange={(e) => { setPreset('custom'); onChange(e.target.value, to) }} />
      </label>
      <label htmlFor={`${idPrefix}-to`}>
        Au
        <input id={`${idPrefix}-to`} className="input" type="date" value={to} min={from || undefined} onChange={(e) => { setPreset('custom'); onChange(from, e.target.value) }} />
      </label>
    </>
  )
}

/** Liste des types d'export avec l'autorisation calculée par le serveur (meta.types de GET /exports). */
export function useExportTypes() {
  return useQuery({
    queryKey: ['exports', 'types'],
    queryFn: ({ signal }) => api.get<{ data: ExportItem[]; meta: { types: ExportType[] } }>('/exports', { per_page: 1 }, signal).then((r) => r.meta.types),
    staleTime: 5 * 60_000,
  })
}

export function useReferentials(enabled = true) {
  return useQuery({
    queryKey: ['referentials'],
    queryFn: ({ signal }) => api.get<{ data: Referentials }>('/referentials', undefined, signal).then((r) => r.data),
    staleTime: 60_000,
    enabled,
  })
}

export function requestExport(type: string, format: string, params: Record<string, unknown>) {
  return api.post<{ data: ExportItem }>('/exports', { type, format, params }).then((r) => r.data)
}

/** Suivi d'un export asynchrone : interrogation toutes les 2 s jusqu'à un état final, puis lien de téléchargement réautorisé. */
export function ExportTracker({ initial }: { initial: ExportItem }) {
  const client = useQueryClient()
  const query = useQuery({
    queryKey: ['exports', 'item', initial.id],
    queryFn: ({ signal }) => api.get<{ data: ExportItem }>(`/exports/${initial.id}`, undefined, signal).then((r) => r.data),
    initialData: initial,
    refetchInterval: (q) => (q.state.data && ['pending', 'processing'].includes(q.state.data.status) ? 2000 : false),
  })
  const e = query.data
  useEffect(() => {
    if (e && !['pending', 'processing'].includes(e.status)) client.invalidateQueries({ queryKey: ['exports', 'list'] })
  }, [e, client])
  const progress = e.status === 'ready' ? 100 : e.progress ?? 0
  return (
    <div className="export-progress" aria-live="polite">
      <span><Icon name={e.status === 'failed' ? 'alert' : 'file'} size={28} /></span>
      <div>
        <strong>{e.file_name ?? `${e.label} (${e.format.toUpperCase()})`}</strong>
        <small>
          {e.status === 'ready' ? `Prêt · ${fmtBytes(e.size)} · expire le ${fmtDateTime(e.expires_at)}` : e.status === 'failed' ? `Échec : ${e.error ?? 'erreur non précisée'}` : `${e.status === 'pending' ? 'En file d’attente' : 'Génération en cours'} · ${progress} %`}
        </small>
        <i role="progressbar" aria-valuemin={0} aria-valuemax={100} aria-valuenow={progress} aria-label="Progression de l’export"><em style={{ width: `${progress}%` }} /></i>
        {query.isError && <small className="red-text">Suivi interrompu : {asApiError(query.error).message}</small>}
      </div>
      <StatusOf map={EXPORT_STATUS} value={e.status} />
      {e.status === 'ready' && (
        <a className="button" href={fileUrl(`/exports/${e.id}/download`)} download><Icon name="download" size={16} /><span>Télécharger</span></a>
      )}
    </div>
  )
}

/**
 * Demande d'export simple (format + période) pour un type donné, suivie jusqu'au fichier prêt.
 */
export function ExportDialog({ type, title, params = {}, withPeriod = true, initialFrom = '', initialTo = '', onClose, children }: {
  type: string; title: string; params?: Record<string, unknown>; withPeriod?: boolean; initialFrom?: string; initialTo?: string; onClose: () => void; children?: ReactNode
}) {
  const types = useExportTypes()
  const def = types.data?.find((t) => t.type === type)
  const [format, setFormat] = useState('pdf')
  const initial = initialFrom || initialTo ? { from: initialFrom, to: initialTo } : presetRange('30')
  const [from, setFrom] = useState(initial.from)
  const [to, setTo] = useState(initial.to)
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<ApiError | null>(null)
  const [created, setCreated] = useState<ExportItem | null>(null)

  const submit = async () => {
    setBusy(true)
    setError(null)
    try {
      setCreated(await requestExport(type, format, { ...params, ...(withPeriod ? periodBounds(from, to) : {}) }))
    } catch (e) {
      setError(asApiError(e))
    } finally {
      setBusy(false)
    }
  }

  return (
    <Modal title={title} kicker="EXPORT" onClose={onClose} footer={created ? <Button variant="secondary" onClick={onClose}>Fermer</Button> : <>
      <Button variant="secondary" onClick={onClose}>Annuler</Button>
      <Button icon="download" onClick={submit} busy={busy} disabled={!def?.allowed || (withPeriod && (!from || !to))}>Lancer la génération</Button>
    </>}>
      {types.isLoading ? <p className="muted">Chargement des formats…</p> : !def ? <Notice tone="danger">Type d’export indisponible.</Notice> : !def.allowed ? <Notice tone="warning">Vos habilitations ne permettent pas de générer ce document.</Notice> : created ? (
        <div style={{ display: 'grid', gap: 12 }}>
          <ExportTracker initial={created} />
          <p className="muted small">Le traitement est asynchrone : vous pouvez fermer cette fenêtre et retrouver le fichier dans « Exports & traitements ». Le téléchargement reste soumis à vos droits.</p>
        </div>
      ) : (
        <div style={{ display: 'grid', gap: 14 }}>
          <p className="muted small">{def.label}</p>
          {children}
          <fieldset className="admin-fieldset">
            <legend>Format</legend>
            <div className="chip-row">
              {def.formats.map((f) => (
                <label key={f} className="radio-chip">
                  <input type="radio" name={`format-${type}`} value={f} checked={format === f} onChange={() => setFormat(f)} /> {f.toUpperCase()}
                </label>
              ))}
            </div>
          </fieldset>
          {withPeriod && <div className="form-grid"><PeriodFields idPrefix={`exp-${type}`} from={from} to={to} onChange={(f, t) => { setFrom(f); setTo(t) }} /></div>}
          {EXPORT_FIELDS[type] && (
            <div>
              <strong className="small">Champs inclus</strong>
              <ul className="admin-bullets">{EXPORT_FIELDS[type].map((x) => <li key={x}>{x}</li>)}</ul>
            </div>
          )}
          {error && <Notice tone="danger">{error.message}{error.field('format') ? ` — ${error.field('format')}` : ''}</Notice>}
        </div>
      )}
    </Modal>
  )
}

/** Valeur d'indicateur : jamais de chiffre inventé, une valeur absente est annoncée « non disponible ». */
export function IndicatorValue({ value, unit }: { value: number | null | undefined; unit?: string }) {
  if (value === null || value === undefined) return <span className="muted">non disponible</span>
  const v = value.toLocaleString('fr-FR', { maximumFractionDigits: 1 })
  return <>{unit === '%' ? `${v} %` : unit ? `${v} ${unit}` : v}</>
}

export function YesNo({ value }: { value: boolean }) {
  return value ? <Status tone="success">Oui</Status> : <Status tone="neutral">Non</Status>
}

export function FieldRow({ children }: { children: ReactNode }) {
  return <div className="form-grid">{children}</div>
}

/** Convertit une saisie datetime-local (heure de Libreville) en ISO UTC ; chaîne vide → null. */
export function localInputToIso(value: string): string | null {
  if (!value) return null
  const [date, time] = value.split('T')
  return new Date(`${date}T${(time ?? '00:00').slice(0, 5)}:00+01:00`).toISOString()
}
