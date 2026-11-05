/**
 * Types, constantes et composants partagés des vues « Affichage » (V07–V10, V15, V16, V22, V23).
 * Toutes les données viennent de l'API ; ce module ne contient que des libellés d'interface.
 */
import { useEffect, useId, useRef, useState, type ReactNode } from 'react'
import { useQuery } from '@tanstack/react-query'
import { api } from '../../lib/api'
import { DAYS, fmtDateTime, fmtTime, LANG_LABEL, CLASS_LABEL } from '../../lib/format'
import { Button, Icon, Kv, StatusOf, type IconName } from '../../components/ui'
import './display.css'

/* ===== Types de l'API ===== */
export interface OperatingWindow { days: number[]; on: string; off: string; wake_before_min?: number | null; ignore_holidays?: boolean | null; override_reason?: string | null }

export interface Device {
  id: string; code: string; status: string; hardware_id: string | null; network_status: string
  last_seen_at: string | null; last_sync_at: string | null; app_version: string | null; release_channel?: string | null; platform: string | null
  storage: { usage?: number | null; quota?: number | null; persisted?: boolean | null } | null
  clock_skew_ms: number | null; active_manifest_version: number | null; current_playback: Record<string, unknown> | null
  last_errors: string[] | null; cache_inventory: string[] | null; paired_at: string | null; revoked_at: string | null; revoke_reason: string | null
}

export interface Screen {
  id: string; code: string; name: string; kind: string; orientation: string; resolution: string; status: string
  operating_window: OperatingWindow | null; window_override_reason: string | null; audio_enabled: boolean; control_channel: string
  panel_state: string; panel_state_at: string | null
  location: { id: string; name: string; floor: { id: string; label: string; level: number } | null; zone: { id: string; code: string; name: string; max_classification: string } | null } | null
  room: { id: string; name: string | null } | null
  groups: { id: string; name: string }[]
  device: Device | null
  is_demo: boolean
}

export interface ScreenGroup {
  id: string; code: string; name: string; description: string | null; screens_count: number
  screens: { id: string; code: string; name: string }[]; active_campaigns: number
}

export interface Referentials {
  floors: { id: string; label: string; level: number; building?: { id: string; name: string } | null; is_active?: boolean }[]
  zones: { id: string; code: string; name: string; is_active?: boolean }[]
  locations: { id: string; name: string; floor_id: string; zone_id: string; floor?: { id: string; label: string; level: number } | null; zone?: { id: string; name: string; code: string } | null; is_active?: boolean }[]
}

export interface ScheduleEntry {
  entry_id: string; campaign_id: string; campaign_ref: string; content_id: string; content_version: string; lang: string; auth_class: string
  priority: number; position: number; rotation_rank: number; from: string; to: string; days: number[]; time_from: string | null; time_to: string | null
  duration_s: number; slide: { title: string; body: string | null; kind: string; lang: string } | null
}

export interface ProgramPreview {
  at: string; timezone: string; operating_window: OperatingWindow
  result: { mode: 'program' | 'alert' | 'fallback' | 'off'; reason: string; playlist: ScheduleEntry[]; alerts: { id: string; reference: string; severity: string; title: string; message: string; starts_at: string; expires_at: string }[] }
  campaigns: { id: string; reference: string; name: string; priority: number; status: string }[]
  fallback: { content_version: string | null; valid_until: string | null; slide: { title: string; lang: string } | null }
  agenda: { room: string; floor: string; label: string; start: string; end: string }[]
  audio_policy: { enabled: boolean; max_volume: number }
  screen: { code: string; name: string; kind: string; orientation: string; resolution: string; location: string; floor: string; building: string; zone: string }
  entries_count: number
}

export interface LayoutZone { id: string; type: string; x: number; y: number; w: number; h: number; style?: string | null; align?: string | null; font_scale?: number | null }
export interface TemplateLayout { zones: LayoutZone[]; theme?: string | null; transition?: string | null; safe_margin?: number | null }
export interface Template {
  id: string; code: string; name: string; orientation: 'landscape' | 'portrait'; purpose: string | null; layout: TemplateLayout
  version: number; status: string; is_institutional: boolean; updated_at: string | null; used_by: number
}

/* ===== Libellés ===== */
export const KIND_LABEL: Record<string, string> = { display: 'Affichage dynamique', room_panel: 'Panneau de salle', orientation: 'Orientation' }
export const ORIENTATION_LABEL: Record<string, string> = { landscape: 'Paysage', portrait: 'Portrait' }
export const RESOLUTIONS = ['1920x1080', '1080x1920', '3840x2160', '2160x3840', '1280x800', '800x1280']
export const CONTROL_LABEL: Record<string, string> = { none: 'Aucun (non piloté)', cec: 'HDMI-CEC', rs232: 'RS-232', api: 'API constructeur' }
export const PANEL_STATE: Record<string, [string, string]> = {
  not_measured: ['Non mesuré', 'neutral'], on: ['Allumée', 'success'], off: ['Éteinte', 'neutral'], standby: ['Veille', 'blue'], unknown: ['Inconnu', 'warning'],
}
export const DEVICE_STATUS: Record<string, [string, string]> = { active: ['Actif', 'success'], revoked: ['Révoqué', 'danger'], pending: ['En attente', 'warning'] }
export const MODE_STATUS: Record<string, [string, string]> = {
  program: ['Programme ordinaire', 'success'], alert: ['Alerte prioritaire', 'danger'], fallback: ['Contenu de repli', 'warning'], off: ['Hors plage — veille', 'neutral'],
}
export const ZONE_TYPES: { type: string; label: string; icon: IconName }[] = [
  { type: 'header', label: 'En-tête institutionnel', icon: 'layout' },
  { type: 'text', label: 'Texte', icon: 'file' },
  { type: 'image', label: 'Image', icon: 'image' },
  { type: 'video', label: 'Vidéo', icon: 'play' },
  { type: 'document', label: 'Document PDF', icon: 'folder' },
  { type: 'clock', label: 'Horloge & date', icon: 'clock' },
  { type: 'agenda', label: 'Agenda du jour', icon: 'calendar' },
  { type: 'ticker', label: 'Bandeau défilant', icon: 'arrow' },
  { type: 'qr', label: 'QR code', icon: 'eye' },
  { type: 'weather', label: 'Météo', icon: 'sun' },
  { type: 'countdown', label: 'Compte à rebours', icon: 'refresh' },
]
export const ZONE_LABEL: Record<string, string> = Object.fromEntries(ZONE_TYPES.map((z) => [z.type, z.label]))
export const ZONE_STYLE_COLOR: Record<string, string> = { primary: '#0b5278', light: '#e9f3f6', dark: '#0b1f3a', accent: '#e6af39' }

export function networkOf(s: Pick<Screen, 'device'>): string {
  return s.device?.network_status ?? 'unpaired'
}

export function fmtWindow(w?: OperatingWindow | null): string {
  if (!w) return '—'
  const days = [...(w.days ?? [])].sort((a, b) => a - b)
  const contiguous = days.length > 1 && days.every((d, i) => i === 0 || d === days[i - 1] + 1)
  const dayLabel = days.length === 7 ? 'Tous les jours' : contiguous ? `${DAYS[days[0]]}–${DAYS[days[days.length - 1]]}` : days.map((d) => DAYS[d]).join(', ')
  return `${dayLabel || 'Aucun jour'} · ${w.on}–${w.off}`
}

export function useReferentials() {
  return useQuery({ queryKey: ['referentials'], queryFn: ({ signal }) => api.get<{ data: Referentials }>('/referentials', undefined, signal).then((r) => r.data), staleTime: 300_000 })
}

export function useScreenGroups(enabled = true) {
  return useQuery({ queryKey: ['screen-groups'], queryFn: ({ signal }) => api.get<{ data: ScreenGroup[] }>('/screen-groups', undefined, signal).then((r) => r.data), enabled })
}

/** Liste complète (plafonnée à 100 par l'API) des écrans, pour les sélecteurs. */
export function useAllScreens(enabled = true) {
  return useQuery({ queryKey: ['screens', 'all'], queryFn: ({ signal }) => api.get<{ data: Screen[]; meta: { total: number } }>('/screens', { per_page: 500 }, signal), enabled })
}

/** Instant courant rafraîchi périodiquement (comptes à rebours). */
export function useNow(intervalMs = 1000): number {
  const [now, setNow] = useState(Date.now())
  useEffect(() => {
    const t = setInterval(() => setNow(Date.now()), intervalMs)
    return () => clearInterval(t)
  }, [intervalMs])
  return now
}

export function fmtCountdown(ms: number): string {
  if (ms <= 0) return '00:00'
  const total = Math.floor(ms / 1000)
  const h = Math.floor(total / 3600)
  const m = Math.floor((total % 3600) / 60)
  const s = total % 60
  const pad = (n: number) => String(n).padStart(2, '0')
  return h ? `${h} h ${pad(m)} min` : `${pad(m)}:${pad(s)}`
}

/* ===== Composants ===== */

/** Parcours multi-étapes reprenant FlowModal de la maquette (progression, étape courante, pied d'actions). */
export function FlowShell({ title, kicker = 'PARCOURS', steps, step, children, footer, onClose }: {
  title: string; kicker?: string; steps: string[]; step: number; children: ReactNode; footer: ReactNode; onClose: () => void
}) {
  const id = useId()
  const ref = useRef<HTMLDivElement>(null)
  const closeRef = useRef(onClose)
  closeRef.current = onClose
  useEffect(() => {
    const previous = document.activeElement as HTMLElement | null
    const onKey = (e: KeyboardEvent) => e.key === 'Escape' && closeRef.current()
    document.addEventListener('keydown', onKey)
    return () => {
      document.removeEventListener('keydown', onKey)
      previous?.focus()
    }
  }, [])
  /* Focus sur le premier champ à chaque changement d'étape. */
  useEffect(() => {
    ref.current?.querySelector<HTMLElement>('.flow-content input, .flow-content select, .flow-content textarea, .flow-content h3')?.focus()
  }, [step])
  return (
    <div className="modal-backdrop" role="dialog" aria-modal="true" aria-labelledby={id}>
      <div className="flow-modal" ref={ref}>
        <header>
          <div><small>{kicker}</small><h2 id={id}>{title}</h2></div>
          <button className="close" onClick={onClose} aria-label="Fermer"><Icon name="x" /></button>
        </header>
        <FlowProgress steps={steps} step={step} />
        <div className="flow-content">
          <div className="step-label">ÉTAPE {step + 1} SUR {steps.length}</div>
          {children}
        </div>
        <footer>{footer}</footer>
      </div>
    </div>
  )
}

export function FlowProgress({ steps, step }: { steps: string[]; step: number }) {
  return (
    <div className="flow-progress" role="list" aria-label="Progression">
      {steps.map((s, i) => (
        <div key={s} role="listitem" className={i < step ? 'done' : i === step ? 'active' : ''} aria-current={i === step ? 'step' : undefined}>
          <span>{i < step ? <Icon name="check" size={13} /> : i + 1}</span>
          <b>{s}{i < step && <em className="sr-only"> (terminée)</em>}</b>
        </div>
      ))}
    </div>
  )
}

export function ProofLegend() {
  return (
    <div className="proof-legend">
      <span><i className="scheduled" />Programmé</span>
      <span><i className="received" />Manifeste reçu</span>
      <span><i className="loaded" />Téléchargé</span>
      <span><i className="active" />Lecture déclarée</span>
      <b><Icon name="info" size={14} /> Ne prouve pas qu’une personne a vu le contenu.</b>
    </div>
  )
}

/** Miniature d'un modèle : zones dessinées en pourcentage du canevas. */
export function TemplateThumb({ layout, orientation }: { layout: TemplateLayout; orientation: string }) {
  return (
    <div className={`tpl-thumb ${orientation === 'portrait' ? 'portrait' : ''} theme-${layout.theme ?? 'institutionnel'}`} aria-hidden="true">
      {(layout.zones ?? []).map((z) => (
        <span key={z.id} style={{ left: `${z.x}%`, top: `${z.y}%`, width: `${z.w}%`, height: `${z.h}%`, background: z.style ? ZONE_STYLE_COLOR[z.style] : undefined, color: z.style === 'light' || z.style === 'accent' ? '#14283c' : undefined }}>
          {ZONE_LABEL[z.type] ?? z.type}
        </span>
      ))}
    </div>
  )
}

/** Résultat d'une prévisualisation de programme (V08 onglet Programme, V22). */
export function ProgramResult({ data }: { data: ProgramPreview }) {
  const r = data.result
  const campaign = (id: string) => data.campaigns.find((c) => c.id === id)
  return (
    <div className="program-result">
      <div className="program-mode">
        <StatusOf map={MODE_STATUS} value={r.mode} />
        <div>
          <strong>Règle appliquée : {r.reason}</strong>
          <small>Instant simulé : {fmtDateTime(data.at)} · fuseau {data.timezone} · {data.entries_count} entrée(s) compilée(s) pour cet écran</small>
        </div>
      </div>

      {r.mode === 'alert' && (
        <div className="table-scroll">
          <table>
            <thead><tr><th>Alerte</th><th>Niveau</th><th>Début</th><th>Expiration</th></tr></thead>
            <tbody>
              {r.alerts.map((a) => (
                <tr key={a.id}><td><strong>{a.title}</strong><small>{a.reference} · {a.message}</small></td><td><StatusOf map={{ critical: ['Critique', 'danger'], warning: ['Avertissement', 'warning'], info: ['Information', 'blue'], exercise: ['Exercice', 'warning'] }} value={a.severity} /></td><td>{fmtDateTime(a.starts_at)}</td><td>{fmtDateTime(a.expires_at)}</td></tr>
              ))}
            </tbody>
          </table>
        </div>
      )}

      {r.mode === 'program' && (
        <>
          <h3 className="sub-title">Liste de lecture résolue (rotation à priorité égale)</h3>
          <div className="table-scroll">
            <table>
              <thead><tr><th>#</th><th>Contenu</th><th>Langue</th><th>Priorité</th><th>Campagne</th><th>Créneau</th><th>Durée</th><th>Classification</th></tr></thead>
              <tbody>
                {r.playlist.map((e, i) => {
                  const c = campaign(e.campaign_id)
                  return (
                    <tr key={e.entry_id}>
                      <td>{i + 1}</td>
                      <td><strong>{e.slide?.title || '(sans titre)'}</strong><small>Position {e.position} · rang de langue {e.rotation_rank}</small></td>
                      <td><span className="lang">{e.lang.toUpperCase()}</span> {LANG_LABEL[e.lang] ?? ''}</td>
                      <td><span className="priority">P{e.priority}</span></td>
                      <td>{c ? <><strong>{c.name}</strong><small>{c.reference}</small></> : e.campaign_ref}</td>
                      <td>{e.time_from || e.time_to ? `${e.time_from ?? '—'}–${e.time_to ?? '—'}` : 'Toute la plage'}<small>{e.days.length ? e.days.map((d) => DAYS[d]).join(', ') : 'Tous jours'}</small></td>
                      <td>{e.duration_s} s</td>
                      <td>{CLASS_LABEL[e.auth_class] ?? e.auth_class}</td>
                    </tr>
                  )
                })}
              </tbody>
            </table>
          </div>
        </>
      )}

      <div className="grid-2 section-gap">
        <div>
          <h3 className="sub-title">Fonctionnement et repli</h3>
          <Kv items={[
            ['Plage de fonctionnement', fmtWindow(data.operating_window)],
            ['Dérogation', data.operating_window.override_reason ?? 'Aucune'],
            ['Audio', data.audio_policy.enabled ? `Autorisé · volume max ${data.audio_policy.max_volume} %` : 'Coupé'],
            ['Contenu de repli', data.fallback.slide ? `${data.fallback.slide.title} (${data.fallback.slide.lang?.toUpperCase()})` : 'Aucun — écran institutionnel par défaut'],
            ['Repli valide jusqu’au', data.fallback.valid_until ? fmtDateTime(data.fallback.valid_until) : '—'],
            ['Écran', `${data.screen.code} · ${data.screen.location} · ${data.screen.floor} · zone ${data.screen.zone}`],
          ]} />
        </div>
        <div>
          <h3 className="sub-title">Agenda du jour du bâtiment</h3>
          {data.agenda.length === 0 ? <p className="muted small">Aucune réunion confirmée aujourd’hui.</p> : (
            <div className="timeline-list">
              {data.agenda.map((a, i) => (
                <div key={i}><time>{fmtTime(a.start)}–{fmtTime(a.end)}</time><span><strong>{a.label}</strong> · {a.room} ({a.floor})</span></div>
              ))}
            </div>
          )}
        </div>
      </div>
      {data.campaigns.length > 0 && (
        <>
          <h3 className="sub-title section-gap">Campagnes ciblant cet écran (7 prochains jours)</h3>
          <div className="chip-row">
            {data.campaigns.map((c) => <span key={c.id} className="chip">{c.reference} · {c.name} · P{c.priority} · {c.status}</span>)}
          </div>
        </>
      )}
    </div>
  )
}

export function StepButtons({ onBack, backLabel, onNext, nextLabel, nextDisabled, busy, danger }: { onBack: () => void; backLabel: string; onNext?: () => void; nextLabel?: string; nextDisabled?: boolean; busy?: boolean; danger?: boolean }) {
  return (
    <>
      <Button variant="secondary" onClick={onBack}>{backLabel}</Button>
      <div className="footer-note"><Icon name="shield" size={14} /> Action tracée dans le journal</div>
      {onNext && <Button variant={danger ? 'danger' : 'primary'} onClick={onNext} disabled={nextDisabled} busy={busy}>{nextLabel}<Icon name="arrow" size={15} /></Button>}
    </>
  )
}
