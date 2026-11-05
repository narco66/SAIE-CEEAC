/** Formats d'affichage : instants reçus en UTC, affichés dans le fuseau Africa/Libreville (conventions du cahier). */
export const TZ = 'Africa/Libreville'

const dateTime = new Intl.DateTimeFormat('fr-FR', { timeZone: TZ, day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' })
const dateOnly = new Intl.DateTimeFormat('fr-FR', { timeZone: TZ, day: '2-digit', month: 'short', year: 'numeric' })
const timeOnly = new Intl.DateTimeFormat('fr-FR', { timeZone: TZ, hour: '2-digit', minute: '2-digit' })
const longDate = new Intl.DateTimeFormat('fr-FR', { timeZone: TZ, weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' })
const dayShort = new Intl.DateTimeFormat('fr-FR', { timeZone: TZ, weekday: 'short', day: '2-digit' })

export const fmtDateTime = (v?: string | null) => (v ? dateTime.format(new Date(v)) : '—')
export const fmtDate = (v?: string | null) => (v ? dateOnly.format(new Date(v)) : '—')
export const fmtTime = (v?: string | null) => (v ? timeOnly.format(new Date(v)) : '—')
export const fmtLongDate = (v: Date | string = new Date()) => longDate.format(typeof v === 'string' ? new Date(v) : v)
export const fmtDayShort = (v: Date) => dayShort.format(v)

export function fmtRelative(v?: string | null): string {
  if (!v) return 'jamais'
  const diff = (Date.now() - new Date(v).getTime()) / 1000
  const abs = Math.abs(diff)
  const suffix = diff >= 0 ? 'il y a ' : 'dans '
  if (abs < 60) return `${suffix}${Math.round(abs)} s`
  if (abs < 3600) return `${suffix}${Math.round(abs / 60)} min`
  if (abs < 86400) return `${suffix}${Math.round(abs / 3600)} h`
  return `${suffix}${Math.round(abs / 86400)} j`
}

export function fmtBytes(size?: number | null): string {
  if (!size && size !== 0) return '—'
  if (size < 1024) return `${size} o`
  if (size < 1024 ** 2) return `${(size / 1024).toFixed(0)} Ko`
  if (size < 1024 ** 3) return `${(size / 1024 ** 2).toFixed(1).replace('.', ',')} Mo`
  return `${(size / 1024 ** 3).toFixed(1).replace('.', ',')} Go`
}

export const fmtNumber = (v?: number | null, digits = 0) => (v === null || v === undefined ? '—' : v.toLocaleString('fr-FR', { maximumFractionDigits: digits }))

/** Parties locales (Africa/Libreville) d'un instant : utile pour les calendriers. */
export function localParts(v: string | Date): { date: string; time: string; minutes: number; weekday: number } {
  const d = typeof v === 'string' ? new Date(v) : v
  const parts = new Intl.DateTimeFormat('en-CA', { timeZone: TZ, year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit', weekday: 'short', hourCycle: 'h23' }).formatToParts(d)
  const get = (t: string) => parts.find((p) => p.type === t)?.value ?? ''
  const weekday = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'].indexOf(get('weekday')) + 1
  const time = `${get('hour')}:${get('minute')}`
  return { date: `${get('year')}-${get('month')}-${get('day')}`, time, minutes: Number(get('hour')) * 60 + Number(get('minute')), weekday }
}

/** Convertit une date et une heure saisies dans le fuseau institutionnel (UTC+1, sans heure d'été) en instant ISO UTC. */
export function toIsoFromLocal(date: string, time: string): string {
  return new Date(`${date}T${time}:00+01:00`).toISOString()
}

export function todayLocal(): string {
  return localParts(new Date()).date
}

export function addDays(date: string, days: number): string {
  const d = new Date(`${date}T12:00:00+01:00`)
  d.setUTCDate(d.getUTCDate() + days)
  return localParts(d).date
}

export const LANG_LABEL: Record<string, string> = { fr: 'Français', en: 'English', pt: 'Português', es: 'Español' }
export const CLASS_LABEL: Record<string, string> = { public: 'Public', interne: 'Interne', restreint: 'Restreint' }
export const CLASS_TONE: Record<string, string> = { public: 'success', interne: 'blue', restreint: 'danger' }

export const CONTENT_STATUS: Record<string, [string, string]> = {
  draft: ['Brouillon', 'neutral'], submitted: ['En attente', 'warning'], approved: ['Approuvé', 'success'], scheduled: ['Programmé', 'blue'],
  broadcasting: ['En diffusion', 'success'], expired: ['Expiré', 'neutral'], archived: ['Archivé', 'neutral'], suspended: ['Suspendu', 'danger'],
}
export const LANG_STATUS: Record<string, [string, string]> = { draft: ['Brouillon', 'neutral'], submitted: ['Soumis', 'warning'], approved: ['Approuvé', 'success'], returned: ['À corriger', 'danger'] }
export const CAMPAIGN_STATUS: Record<string, [string, string]> = {
  draft: ['Brouillon', 'neutral'], scheduled: ['Programmée', 'blue'], active: ['En cours', 'success'], suspended: ['Suspendue', 'danger'], expired: ['Expirée', 'neutral'], archived: ['Archivée', 'neutral'],
}
export const OCC_STATUS: Record<string, [string, string]> = {
  draft: ['Brouillon', 'neutral'], pending: ['En attente', 'warning'], confirmed: ['Confirmée', 'success'], refused: ['Refusée', 'danger'], cancelled: ['Annulée', 'neutral'],
  in_progress: ['En cours', 'blue'], completed: ['Terminée', 'neutral'], no_show: ['Non honorée', 'danger'],
}
export const NETWORK_STATUS: Record<string, [string, string]> = {
  online: ['Connecté', 'success'], suspect: ['Suspect', 'warning'], unavailable: ['Indisponible', 'danger'], unpaired: ['Non appairé', 'neutral'], unknown: ['Inconnu', 'neutral'],
}
export const SCAN_STATUS: Record<string, [string, string]> = { quarantine: ['Quarantaine', 'warning'], scanning: ['Analyse', 'warning'], ready: ['Prêt', 'success'], rejected: ['Rejeté', 'danger'], trashed: ['Corbeille', 'neutral'] }
export const SEVERITY: Record<string, [string, string]> = { critical: ['Critique', 'danger'], warning: ['Avertissement', 'warning'], info: ['Information', 'blue'], exercise: ['Exercice', 'warning'], major: ['Majeur', 'warning'], minor: ['Mineur', 'neutral'] }
export const SERVICE_STATUS: Record<string, [string, string]> = { requested: ['À traiter', 'warning'], accepted: ['Acceptée', 'blue'], clarification: ['Précision demandée', 'warning'], ready: ['Prête', 'success'], impossible: ['Impossible', 'danger'], cancelled: ['Annulée', 'neutral'] }
export const DAYS = ['', 'Lun', 'Mar', 'Mer', 'Jeu', 'Ven', 'Sam', 'Dim']
