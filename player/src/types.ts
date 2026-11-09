/** Contenu du manifeste signé (annexe A, enveloppe « saie-manifest-envelope/1 »). */

export interface LayoutZone { id: string; type: string; x: number; y: number; w: number; h: number; style?: string; align?: string; font_scale?: number }
export interface Layout { zones: LayoutZone[]; theme?: string; transition?: string; safe_margin?: number }

export interface SlideMedia { asset: string; kind: 'image' | 'video' | 'pdf' | 'office'; pages: number[] | null; page_duration_s: number | null; role: string }
export interface Slide {
  title: string
  body: string | null
  category: string | null
  kind: string
  lang: string
  media: SlideMedia[]
  subtitles: string | null
  qr: { url: string; label: string } | null
  layout: Layout | null
  delegation: { name?: string; flags?: string[]; languages?: string[] } | null
  duration_s: number
}

export interface ScheduleEntry {
  entry_id: string
  campaign_id: string
  campaign_ref: string
  content_id: string
  content_version: string
  lang: string
  auth_class: 'public' | 'interne'
  priority: number
  /** Mode événement (PUB-12) : écarte le programme ordinaire pendant sa période. */
  event?: boolean
  position: number
  rotation_rank: number
  from: string
  to: string
  days: number[]
  time_from: string | null
  time_to: string | null
  duration_s: number
  slide: Slide
}

export interface ManifestAlert { id: string; reference: string; severity: 'critical' | 'warning' | 'info' | 'exercise'; title: string; message: string; translations: Record<string, { title: string; message: string }>; starts_at: string; expires_at: string }
export interface AgendaItem { room: string; floor: string; label: string; start: string; end: string }
export interface Asset { id: string; sha256: string; size: number; type: string; kind: string; lang: string | null; href: string }

export interface Manifest {
  alert_policy?: { critical_overrides_window?: boolean }
  format: string
  manifest_id: string
  device_id: string
  version: number
  generated_at: string
  valid_until: string
  authorization: { public: string; interne: string }
  content_hash: string
  timezone: string
  screen: { code: string; name: string; kind: 'display' | 'room_panel' | 'orientation'; orientation: 'landscape' | 'portrait'; resolution: string; control_channel?: 'none' | 'cec' | 'rs232' | 'api'; location: string; floor: string; building: string; zone: string }
  operating_window: { days: number[]; on: string; off: string; wake_before_min: number; override_reason: string | null; ignore_holidays?: boolean }
  non_working_days: string[]
  audio_policy: { enabled: boolean; max_volume: number; hours: { on: string; off: string } }
  /** §7.3 : profil de configuration en vigueur et son empreinte, à confirmer au serveur. */
  config?: { profile: string | null; version: number | null; hash: string; settings: ProfileSettings }
  schedule: ScheduleEntry[]
  alerts: ManifestAlert[]
  agenda: AgendaItem[]
  fallback: { content_version: string | null; valid_until: string | null; slide: Slide | null }
  institution: { name: string; tagline: string }
  dynamic?: Record<string, { type: string; name: string; fetched_at: string; kind?: string; items?: { title: string; date: string | null }[]; temperature?: number; unit?: string; place?: string; label?: string; target?: string }>
  room_panel?: {
    room: { code: string; name: string; capacity: number; floor: string; open_from: string; open_to: string; status: string }
    occurrences: { start: string; end: string; label: string; participants: number | null; confidential: boolean }[]
    blocks: { start: string; end: string; label: string }[]
  }
  orientation?: {
    /** Borne tactile : annuaire interactif (ORI-01). */
    touch?: boolean
    floors: { level: number; label: string; structures: string[]; rooms: { name: string; capacity: number }[]; directory?: { name: string; office: string | null; phone: string | null }[]; plan?: string | null }[]
    welcomes: { lang: string; title: string; body: string; delegation: { name?: string } | null }[]
  }
  assets: Asset[]
  player_release?: { version: string; channel: 'pilot' | 'stable'; url: string; sha256: string } | null
  /** Lecteur suspendu par la supervision (§10.7) : le programme et les alertes sont vides, seul le repli s'affiche. */
  suspended?: boolean
  trusted_keys?: { key_id: string; public_key: string }[]
}

export interface Envelope { format: string; version: number; etag: string; payload: string; signature: { alg: string; key_id: string; value: string } }

export interface Identity { deviceId: string; deviceCode: string; token: string; publicKey: string; keyId: string; pairedAt: string; tokenIssuedAt?: string; trustedKeys?: Record<string, string>; revoked?: boolean }

export interface DeviceEvent { event_id: string; kind: string; manifest_version?: number | null; ref_type?: string | null; ref_id?: string | null; lang?: string | null; occurred_at: string; payload?: Record<string, unknown> }

/** Paramètres d'un profil de configuration (§7.3) ; un paramètre absent conserve le comportement par défaut. */
export interface ProfileSettings {
  volume_max?: number; languages?: string[]; language_rotation_s?: number; sync_interval_s?: number; heartbeat_interval_s?: number
  reconnect_max_s?: number; standby_outside_window?: boolean; fullscreen?: boolean; brightness?: number; contrast?: number
  software_rotation?: boolean; idle_standby_min?: number; server_url?: string
}
