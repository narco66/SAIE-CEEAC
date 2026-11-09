import { useEffect, useLayoutEffect, useRef, useState, type ReactNode } from 'react'
import { QRCodeSVG } from 'qrcode.react'
import { api, ApiError, fileUrl } from '../../lib/api'
import { useData } from '../../lib/hooks'
import { fmtTime, LANG_LABEL, localParts, toIsoFromLocal } from '../../lib/format'
import { Icon, useToast } from '../../components/ui'
import './publication.css'

/* ===== Types des réponses de l'API (app/Support/Present.php) ===== */
export interface UserRef { id: string; name: string; initials: string }
export interface NamedRef { id: string; name: string }

export interface AssetVersion {
  id: string; version: number; original_name: string; mime: string | null; extension: string; size: number; sha256: string | null
  width: number | null; height: number | null; duration_s: number | null; pages: number | null
  scan_status: string; scan_engine: string | null; scan_detail: string | null; has_thumbnail: boolean; created_at: string | null; processed_at: string | null
  /** Fichier bureautique converti : diffusé à l'écran comme un PDF de `broadcast_pages` pages. */
  broadcastable_as_pdf?: boolean; broadcast_pages?: number | null
}
export interface Asset {
  id: string; version: number; reference: string; title: string; kind: 'image' | 'video' | 'pdf' | 'office'; classification: string; language: string | null; status: string
  owner: NamedRef | null; category: string | null; creator: UserRef | null; current_version: AssetVersion | null; usages: number | null
  created_at: string | null; updated_at: string | null; is_demo?: boolean
  tags?: string[]; usage_rights?: string | null; rights_expire_on?: string | null; rights_expired?: boolean
}
export interface Usage { type: 'content' | 'document' | 'campaign'; id: string; reference: string; title: string }

export interface Zone { id: string; type: string; x: number; y: number; w: number; h: number; style?: string | null; font_scale?: number | null; align?: string | null }
export interface TemplateLayout { zones: Zone[]; theme?: string | null; transition?: string | null; safe_margin?: number | null }
export interface Template { id: string; code: string; name: string; orientation: 'landscape' | 'portrait'; purpose: string; layout: TemplateLayout; version: number; status: string; is_institutional: boolean }

export interface MediaItem { asset_version_id: string; pages?: number[] | null; page_duration_s?: number | null; role?: string | null }
export interface QrConfig { enabled?: boolean; document_id?: string | null; label?: string | null; code?: string }
export interface ContentLang {
  id: string; lang: string; revision: number; title: string; body: string | null; extra: unknown; status: string; overflow: boolean
  subtitles_asset_version_id: string | null; submitted_at: string | null; last_return_reason: string | null
}
export interface ApprovedVersion { id: string; lang: string; revision: number; approved_at?: string | null; approver?: string | null; payload?: { title?: string; body?: string | null; category?: string | null } }
export interface Content {
  id: string; reference: string; title: string; kind: string; status: string; classification: string; priority: number; version: number
  category: NamedRef | null; owner: NamedRef | null; author: UserRef | null; template: { id: string; name: string; orientation: 'landscape' | 'portrait' } | null
  media: MediaItem[]; qr: QrConfig | null; proposed_targets: unknown; valid_from: string | null; expires_at: string | null; is_permanent: boolean
  languages: ContentLang[]; updated_at: string | null; created_at: string | null; approved_versions?: ApprovedVersion[]
}
export interface Approval {
  id: string; lang: string; revision: number; status: string; decision: string | null; reason: string | null; due_at: string | null; escalated_at: string | null
  assignee: UserRef | null; backup: UserRef | null; decider: UserRef | null; decided_at: string | null
}
export interface HistoryEvent { action: string; from: string | null; to: string | null; lang: string | null; revision: number | null; actor: string | null; role: string | null; reason: string | null; at: string | null }
export interface ContentDetail extends Content {
  template_layout: TemplateLayout | null
  approvals: Approval[]; history: HistoryEvent[]
  permissions: { edit: boolean; validate_languages: string[]; archive: boolean }
  reassign_candidates?: Record<string, { id: string; name: string }[]>
  campaigns: { id: string; reference: string; name: string; status: string }[]
}

export interface Referentials {
  defaults?: { slide_duration_s: number }
  categories: { id: string; code: string; name: string; default_duration_s: number; is_active: boolean }[]
  structures: { id: string; name: string; code?: string; is_active?: boolean }[]
  zones: { id: string; code: string; name: string; max_classification: string; is_active: boolean }[]
  floors: { id: string; code: string; label: string; level: number; is_active: boolean; building?: { id: string; name: string } | null }[]
  languages: { code: string; name: string; is_active: boolean; sort: number }[]
  users: { id: string; name: string; structure_id: string | null }[]
}

export function useReferentials() {
  return useData<Referentials>(['referentials'], '/referentials')
}

/* ===== Libellés ===== */
export const KIND_LABEL: Record<string, string> = { image: 'Image', video: 'Vidéo', pdf: 'PDF', office: 'Bureautique' }
export const KIND_ICON: Record<string, 'image' | 'play' | 'file'> = { image: 'image', video: 'play', pdf: 'file', office: 'file' }
export const DOC_STATUS: Record<string, [string, string]> = { draft: ['Brouillon', 'neutral'], published: ['Publié', 'success'], unpublished: ['Dépublié', 'danger'], trashed: ['Corbeille', 'neutral'] }
export const HISTORY_LABEL: Record<string, string> = {
  created: 'Création', submitted: 'Soumission', approved: 'Approbation', returned: 'Retour pour correction', reassigned: 'Réaffectation', revised: 'Révision',
  archived: 'Archivage', published: 'Publication', unpublished: 'Dépublication', version_added: 'Nouvelle version',
}
export const LIMITS = { landscape: { title: 90, body: 420 }, portrait: { title: 70, body: 320 } } as const
export const ALL_LANGS = ['fr', 'en', 'pt', 'es'] as const

export const langLabel = (code: string) => LANG_LABEL[code] ?? code.toUpperCase()

export function LangPills({ languages }: { languages: { lang: string; status: string }[] }) {
  if (!languages.length) return <span className="muted small">—</span>
  const labels: Record<string, string> = { draft: 'brouillon', submitted: 'soumis', approved: 'approuvé', returned: 'à corriger' }
  return (
    <span className="lang-pill">
      {languages.map((l) => (
        <span key={l.lang} className={l.status} title={`${langLabel(l.lang)} : ${labels[l.status] ?? l.status}`}>
          {l.lang.toUpperCase()}
          {l.status === 'approved' ? ' ✓' : l.status === 'returned' ? ' !' : l.status === 'submitted' ? ' …' : ''}
          <span className="sr-only"> {labels[l.status] ?? l.status}</span>
        </span>
      ))}
    </span>
  )
}

/** Sépare un instant ISO en date et heure locales de Libreville pour les champs de saisie. */
export function splitLocal(iso?: string | null): { date: string; time: string } {
  if (!iso) return { date: '', time: '' }
  const p = localParts(iso)
  return { date: p.date, time: p.time }
}
export function joinLocal(date: string, time: string, fallbackTime = '00:00'): string | null {
  if (!date) return null
  return toIsoFromLocal(date, time || fallbackTime)
}

export function errorText(e: unknown): string {
  return e instanceof ApiError ? e.message : e instanceof Error ? e.message : String(e)
}

/** Champ date + heure saisi dans le fuseau institutionnel. */
export function LocalDateTime({ label, date, time, onDate, onTime, required, error, disabled, help }: {
  label: string; date: string; time: string; onDate: (v: string) => void; onTime: (v: string) => void; required?: boolean; error?: string; disabled?: boolean; help?: string
}) {
  return (
    <fieldset className="field" style={{ border: 0, padding: 0, margin: 0 }} disabled={disabled}>
      <legend style={{ padding: 0 }}>{label} {required && <em aria-hidden="true">*</em>}{required && <span className="sr-only">(obligatoire)</span>}</legend>
      <div style={{ display: 'flex', gap: 6 }}>
        <input type="date" value={date} onChange={(e) => onDate(e.target.value)} aria-label={`${label} — date`} aria-invalid={!!error} required={required} />
        <input type="time" value={time} onChange={(e) => onTime(e.target.value)} aria-label={`${label} — heure (Libreville)`} aria-invalid={!!error} style={{ maxWidth: 120 }} />
      </div>
      {help && !error && <span className="field-help">{help}</span>}
      {error && <span className="field-error" role="alert">{error}</span>}
    </fieldset>
  )
}

/** Barre d'étapes de la maquette (flow-progress), navigable au clavier. */
export function Stepper({ steps, current, onSelect, reachable }: { steps: string[]; current: number; onSelect: (i: number) => void; reachable?: (i: number) => boolean }) {
  return (
    <nav className="flow-progress" aria-label="Étapes">
      {steps.map((s, i) => (
        <div className={i < current ? 'done' : i === current ? 'active' : ''} key={s}>
          <button type="button" onClick={() => onSelect(i)} disabled={reachable ? !reachable(i) : false} aria-current={i === current ? 'step' : undefined}>
            <span>{i < current ? <Icon name="check" size={13} /> : i + 1}</span>
            <b>{s}</b>
            {i < current && <span className="sr-only"> (terminée)</span>}
          </button>
        </div>
      ))}
    </nav>
  )
}

/** Menu d'actions contextuel d'une ligne de tableau. */
export function RowMenu({ label, children }: { label: string; children: (close: () => void) => ReactNode }) {
  const [open, setOpen] = useState(false)
  const ref = useRef<HTMLDivElement>(null)
  useEffect(() => {
    if (!open) return
    const onDoc = (e: MouseEvent) => { if (!ref.current?.contains(e.target as Node)) setOpen(false) }
    const onKey = (e: KeyboardEvent) => { if (e.key === 'Escape') setOpen(false) }
    document.addEventListener('mousedown', onDoc)
    document.addEventListener('keydown', onKey)
    return () => { document.removeEventListener('mousedown', onDoc); document.removeEventListener('keydown', onKey) }
  }, [open])
  return (
    <div className="pub-menu-wrap" ref={ref}>
      <button className="icon-button" aria-haspopup="menu" aria-expanded={open} aria-label={label} onClick={() => setOpen((o) => !o)}><Icon name="more" /></button>
      {open && <div className="pub-menu" role="menu">{children(() => setOpen(false))}</div>}
    </div>
  )
}

/** Demande de génération d'un document (PDF04, PDF05, PDF10) puis téléchargement dès qu'il est prêt. */
export function useExportRequest() {
  const toast = useToast()
  const [busy, setBusy] = useState<string | null>(null)
  const run = async (type: string, format: 'pdf' | 'csv', params: Record<string, string>) => {
    const key = `${type}:${format}`
    setBusy(key)
    try {
      const res = await api.post<{ data: { id: string; status: string; label: string; error: string | null } }>('/exports', { type, format, params })
      let exp = res.data
      toast.info('Génération demandée', `${exp.label} (${format.toUpperCase()}) — traitement en cours.`)
      for (let i = 0; i < 45 && !['ready', 'failed', 'cancelled', 'expired'].includes(exp.status); i++) {
        await new Promise((r) => setTimeout(r, 2000))
        exp = (await api.get<{ data: typeof exp }>(`/exports/${exp.id}`)).data
      }
      if (exp.status === 'ready') {
        const a = document.createElement('a')
        a.href = fileUrl(`/exports/${exp.id}/download`)
        a.rel = 'noopener'
        document.body.appendChild(a)
        a.click()
        a.remove()
        toast.success('Document prêt', `${exp.label} téléchargé.`)
      } else if (exp.status === 'failed') {
        toast.error(new Error(exp.error ?? 'La génération a échoué.'), 'Génération impossible')
      } else {
        toast.info('Génération toujours en cours', 'Le fichier sera disponible dans « Exports & traitements ».')
      }
    } catch (e) {
      toast.error(e, 'Génération impossible')
    } finally {
      setBusy(null)
    }
  }
  return { run, busy }
}

/* ===== Aperçu écran avec la mise en page du modèle ===== */
const DEFAULT_LAYOUT: Record<'landscape' | 'portrait', TemplateLayout> = {
  landscape: { zones: [{ id: 'header', type: 'header', x: 0, y: 0, w: 100, h: 11, style: 'dark' }, { id: 'title', type: 'text', x: 5, y: 16, w: 90, h: 70, style: 'light' }] },
  portrait: { zones: [{ id: 'header', type: 'header', x: 0, y: 0, w: 100, h: 7, style: 'dark' }, { id: 'title', type: 'text', x: 5, y: 12, w: 90, h: 76, style: 'light' }] },
}

export function TemplatePreview({ layout, orientation, title, body, kicker, lang, imageUrl, mediaLabel, qrValue, onOverflow, label }: {
  layout?: TemplateLayout | null; orientation: 'landscape' | 'portrait'; title: string; body?: string | null; kicker?: string | null; lang?: string
  imageUrl?: string | null; mediaLabel?: string | null; qrValue?: string | null; onOverflow?: (overflow: boolean) => void; label?: string
}) {
  const zones = layout?.zones?.length ? layout.zones : DEFAULT_LAYOUT[orientation].zones
  const textRefs = useRef<Record<string, HTMLDivElement | null>>({})
  const [overflowing, setOverflowing] = useState<string[]>([])
  const frameRef = useRef<HTMLDivElement>(null)
  const callback = useRef(onOverflow)
  callback.current = onOverflow

  useLayoutEffect(() => {
    const measure = () => {
      const ids = Object.entries(textRefs.current).filter(([, el]) => el && (el.scrollHeight > el.clientHeight + 1 || el.scrollWidth > el.clientWidth + 1)).map(([id]) => id)
      setOverflowing((prev) => (prev.join() === ids.join() ? prev : ids))
      callback.current?.(ids.length > 0)
    }
    measure()
    const ro = typeof ResizeObserver !== 'undefined' ? new ResizeObserver(measure) : null
    if (ro && frameRef.current) ro.observe(frameRef.current)
    return () => ro?.disconnect()
  }, [title, body, kicker, layout, orientation])

  const textZones = zones.filter((z) => z.type === 'text')
  return (
    <div ref={frameRef} className={`tpl-frame ${orientation} theme-${layout?.theme ?? 'institutionnel'}`} role="img" aria-label={label ?? `Aperçu ${orientation === 'portrait' ? 'portrait 9:16' : 'paysage 16:9'}${lang ? ` — ${langLabel(lang)}` : ''}`} lang={lang}>
      {zones.map((z) => {
        const style = { left: `${z.x}%`, top: `${z.y}%`, width: `${z.w}%`, height: `${z.h}%` }
        const cls = `tpl-zone ${z.style ? `style-${z.style}` : ''} ${z.align ? `align-${z.align}` : ''}`
        switch (z.type) {
          case 'header':
            return <div key={z.id} className={`${cls} header`} style={style}><span>Commission de la CEEAC</span><small>{fmtTime(new Date().toISOString())}</small></div>
          case 'text': {
            const primary = z.id === textZones[0]?.id
            return (
              <div key={z.id} className={`${cls} text ${overflowing.includes(z.id) ? 'overflowing' : ''}`} style={style} ref={(el) => { textRefs.current[z.id] = el }}>
                {primary ? (
                  <>
                    {kicker && <span className="tpl-kicker">{kicker}</span>}
                    <h4 style={z.font_scale ? { fontSize: `calc(${orientation === 'portrait' ? 6.4 : 3.6}cqw * ${z.font_scale})` } : undefined}>{title || 'Titre du message'}</h4>
                    {body && <p>{body}</p>}
                  </>
                ) : null}
              </div>
            )
          }
          case 'image':
          case 'video':
            return (
              <div key={z.id} className={`${cls} media-zone`} style={style}>
                {imageUrl ? <img src={imageUrl} alt="" onError={(e) => { e.currentTarget.style.display = 'none' }} /> : <span className="tpl-zone placeholder" style={{ position: 'static' }}><Icon name={z.type === 'video' ? 'play' : 'image'} size={22} />{mediaLabel ?? ''}</span>}
              </div>
            )
          case 'qr':
            return qrValue ? <div key={z.id} className={`${cls} qr-zone`} style={style}><QRCodeSVG value={qrValue} /></div> : null
          default:
            return <div key={z.id} className={`${cls} placeholder`} style={style}>{z.type === 'document' ? (mediaLabel ?? 'Document') : z.type === 'clock' ? fmtTime(new Date().toISOString()) : z.type === 'agenda' ? 'Agenda' : z.type === 'ticker' ? 'Bandeau' : z.type}</div>
        }
      })}
      {overflowing.length > 0 && <span className="tpl-overflow-badge">Débordement</span>}
    </div>
  )
}

/* ===== Campagnes ===== */
export interface CampaignTarget { type: 'screen' | 'group' | 'zone' | 'floor' | 'all'; id: string | null }
export interface Campaign {
  id: string; reference: string; name: string; kind?: 'standard' | 'event'; status: string; priority: number; version: number
  starts_at: string; ends_at: string; days: number[]; time_from: string | null; time_to: string | null; slide_duration_s: number | null; suspend_reason: string | null
  creator: UserRef | null; published_at: string | null
  languages: { lang: string; rank: number }[]
  items: { id: string; position: number; duration_s: number | null; version_map: Record<string, string> | null; content: { id: string; reference: string; title: string; classification: string; status: string } | null }[]
  targets: CampaignTarget[]
  screens?: number; receipts?: { received: number; targeted: number }
}
export interface PreviewScreen {
  id: string; code: string; name: string; kind: string; floor: string; zone: string; zone_max_classification: string; compatible: boolean; network_status: string
  conflicts: { reference: string; name: string; priority: number; same_priority: boolean }[]
}
export interface CampaignPreview { screens: PreviewScreen[]; effective: number; warnings: string[] }
