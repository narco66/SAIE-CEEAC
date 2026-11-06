import type { Manifest, ManifestAlert, ScheduleEntry } from './types.ts'

/**
 * Résolution déterministe du programme (PUB-05), identique à app/Services/ScheduleResolver.php :
 * alerte active d'abord ; sinon mode événement (PUB-12), qui écarte le programme ordinaire ; puis priorité décroissante,
 * date de début, identifiant ; rotation ordonnée à priorité égale.
 * Les entrées dont l'autorisation de diffusion hors ligne a expiré sont retirées (PLY-05).
 */

export const TIMEZONE = 'Africa/Libreville'

export interface LocalClock { date: string; time: string; weekday: number }

export function localClock(at: Date, timeZone = TIMEZONE): LocalClock {
  const parts = new Intl.DateTimeFormat('en-CA', { timeZone, year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit', weekday: 'short', hourCycle: 'h23' }).formatToParts(at)
  const get = (t: string) => parts.find((p) => p.type === t)?.value ?? ''
  return { date: `${get('year')}-${get('month')}-${get('day')}`, time: `${get('hour')}:${get('minute')}`, weekday: ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'].indexOf(get('weekday')) + 1 }
}

export function withinWindow(manifest: Pick<Manifest, 'operating_window' | 'non_working_days' | 'timezone'>, at: Date, wakeMinutes = 0): boolean {
  const w = manifest.operating_window
  if (!w) return true
  const local = localClock(at, manifest.timezone)
  if (!w.ignore_holidays && manifest.non_working_days?.includes(local.date)) return false
  if (!(w.days ?? [1, 2, 3, 4, 5]).includes(local.weekday)) return false
  const [h, m] = w.on.split(':').map(Number)
  const onMinutes = h * 60 + m - wakeMinutes
  const [oh, om] = w.off.split(':').map(Number)
  const [ch, cm] = local.time.split(':').map(Number)
  const now = ch * 60 + cm
  return now >= onMinutes && now < oh * 60 + om
}

const SEVERITY_RANK: Record<string, number> = { critical: 0, warning: 1, exercise: 2, info: 3 }

export type Resolution =
  | { mode: 'off'; reason: string }
  | { mode: 'alert'; alerts: ManifestAlert[]; reason: string }
  | { mode: 'program'; playlist: ScheduleEntry[]; reason: string }
  | { mode: 'fallback'; reason: string }

function cmp(a: (string | number)[], b: (string | number)[]): number {
  for (let i = 0; i < a.length; i++) {
    if (a[i] < b[i]) return -1
    if (a[i] > b[i]) return 1
  }
  return 0
}

export function resolve(manifest: Manifest, at: Date, isAssetReady: (id: string) => boolean = () => true): Resolution {
  if (!withinWindow(manifest, at)) return { mode: 'off', reason: 'Hors plage de fonctionnement' }
  const t = at.getTime()
  const alerts = (manifest.alerts ?? []).filter((a) => Date.parse(a.starts_at) <= t && Date.parse(a.expires_at) > t)
  if (alerts.length) {
    alerts.sort((a, b) => cmp([SEVERITY_RANK[a.severity] ?? 9, a.starts_at, a.id], [SEVERITY_RANK[b.severity] ?? 9, b.starts_at, b.id]))
    return { mode: 'alert', alerts, reason: 'Alerte active prioritaire' }
  }
  const local = localClock(at, manifest.timezone)
  const candidates = (manifest.schedule ?? []).filter((e) => {
    const until = manifest.authorization?.[e.auth_class]
    if (until && Date.parse(until) <= t) return false
    if (Date.parse(e.from) > t || Date.parse(e.to) <= t) return false
    if (e.days?.length && !e.days.includes(local.weekday)) return false
    if (e.time_from && local.time < e.time_from) return false
    if (e.time_to && local.time >= e.time_to) return false
    return e.slide.media.every((m) => isAssetReady(m.asset))
  })
  if (!candidates.length) return { mode: 'fallback', reason: 'Aucun programme valide : contenu de repli' }
  const events = candidates.filter((e) => e.event)
  const eligible = events.length ? events : candidates
  eligible.sort((a, b) => cmp([-a.priority, a.from, a.campaign_id, a.position, a.rotation_rank], [-b.priority, b.from, b.campaign_id, b.position, b.rotation_rank]))
  const top = eligible[0].priority
  const playlist = eligible.filter((e) => e.priority === top)
  return { mode: 'program', playlist, reason: `${events.length ? 'Mode événement · ' : ''}Priorité ${top} · rotation de ${playlist.length} diapositive(s)` }
}

/** Le contenu de repli expiré est remplacé par un repli institutionnel neutre (PUB-11). */
export function fallbackValid(manifest: Manifest, at: Date): boolean {
  return !!manifest.fallback?.slide && !!manifest.fallback.valid_until && Date.parse(manifest.fallback.valid_until) > at.getTime()
}

/** Audio autorisé uniquement si la politique de zone le permet et dans la plage horaire audio (PLY-10). */
export function audioAllowed(manifest: Manifest, at: Date): boolean {
  if (!manifest.audio_policy?.enabled) return false
  const local = localClock(at, manifest.timezone)
  return local.time >= manifest.audio_policy.hours.on && local.time < manifest.audio_policy.hours.off
}

/** Données « vivantes » d'un manifeste : appliquées dès sa vérification, sans attendre les médias du programme (PLY-03, PUB-06). */
export type LiveData = Pick<Manifest, 'version' | 'authorization' | 'alerts' | 'agenda' | 'room_panel' | 'orientation'>

export function liveData(manifest: Manifest): LiveData {
  return { version: manifest.version, authorization: manifest.authorization, alerts: manifest.alerts, agenda: manifest.agenda, room_panel: manifest.room_panel, orientation: manifest.orientation }
}

/**
 * Vue affichée : le programme et les médias viennent du manifeste actif (bascule atomique) ; alertes, agenda, panneau de salle
 * et orientation viennent du dernier manifeste vérifié. Hors ligne, ces informations publiques cessent d'être affichées à
 * l'expiration de leur autorisation (PLY-05) : jamais un agenda ou une bienvenue périmés.
 */
export function liveView(active: Manifest, live: LiveData | null, at: Date): Manifest {
  const source: LiveData = live && live.version >= active.version ? live : liveData(active)
  const expired = !!source.authorization?.public && Date.parse(source.authorization.public) <= at.getTime()
  return {
    ...active,
    alerts: source.alerts,
    agenda: expired ? [] : source.agenda,
    room_panel: source.room_panel && expired ? { ...source.room_panel, occurrences: [], blocks: [] } : source.room_panel,
    orientation: source.orientation && expired ? { ...source.orientation, welcomes: [] } : source.orientation,
  }
}

/** Contrôles d'un manifeste signé reçu (PLY-03) : destinataire, validité, et aucun retour à une version antérieure. */
export function acceptManifest(manifest: Pick<Manifest, 'device_id' | 'version' | 'valid_until' | 'content_hash'>, deviceCode: string, active: Pick<Manifest, 'version' | 'content_hash'> | null, at: Date): 'accept' | 'same' | string {
  if (manifest.device_id !== deviceCode) return 'Manifeste destiné à un autre lecteur'
  if (manifest.valid_until && Date.parse(manifest.valid_until) <= at.getTime()) return 'Manifeste expiré : version ignorée'
  if (active && manifest.content_hash === active.content_hash && manifest.version <= active.version) return 'same'
  if (active && manifest.version <= active.version) return 'Manifeste plus ancien que la version active : version ignorée'
  return 'accept'
}
