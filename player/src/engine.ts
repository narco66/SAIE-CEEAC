import { setSigningClockOffset, signRequest } from './sign.ts'
import nacl from 'tweetnacl'
import { acceptManifest, liveData, verificationKey, withinWindow, type LiveData } from './schedule.ts'
import {
  ackEvents, cachedIds, clearIdentity, downloadAsset, enqueue, evictUnreferenced, getActive, getIdentity, hasAsset, kv, peekEvents,
  requestPersistence, setActive, setIdentity, storageEstimate, enableSecureStorage, loadSecureStorageFlag, type ActiveState,
} from './storage.ts'
import type { Envelope, Identity, Manifest } from './types.ts'

export const APP_VERSION: string = import.meta.env.VITE_PLAYER_VERSION ?? __PLAYER_VERSION__
const API = '/api/v1/device'
const SYNC_MS = 30_000
const OFF_WINDOW_SYNC_MS = 300_000
const HEARTBEAT_MS = 30_000
const RESERVE = 0.2
/** Délai maximal d'un appel à l'API (PLY-01) : une connexion pendante ne doit jamais figer heartbeat et synchronisation. */
const CALL_TIMEOUT_MS = 20_000
const TOKEN_ROTATE_MS = 30 * 24 * 3600_000
/** Agent local de pilotage de la dalle (player/agent/panel-agent.mjs). */
const PANEL_AGENT = import.meta.env.VITE_PANEL_AGENT_URL ?? 'http://127.0.0.1:7071/panel'

export interface EngineState {
  phase: 'boot' | 'pairing' | 'running' | 'revoked'
  identity: Identity | null
  active: ActiveState | null
  online: boolean
  lastContact: string | null
  lastManifestAt: string | null
  clockOffsetMs: number
  pairing: { code: string; expiresAt: string } | null
  download: { done: number; total: number } | null
  errors: string[]
  readyAssets: Set<string>
  /** Alertes, agenda, panneau et orientation du dernier manifeste vérifié, même si ses médias sont encore en téléchargement. */
  live: LiveData | null
  /** Diffusion arrêtée par une commande à distance (§11) : écran noir jusqu'à la reprise ; les alertes restent affichées. */
  paused: boolean
}

/** Commande à distance remise au battement (§11.2) ; l'identifiant est l'identifiant de transaction. */
interface RemoteCommand { id: string; command: string; params?: { screenshot_id?: string } | null; expires_at: string }
type CommandOutcome = { status: 'executed' | 'failed'; message?: string; data?: Record<string, unknown> } | 'deferred'

/** Commandes que l'application sait exécuter sans matériel particulier ; veille et redémarrage dépendent de l'agent local. */
const SOFTWARE_CAPABILITIES = ['sync', 'refresh', 'restart_player', 'stop_playback', 'resume_playback', 'screenshot', 'logs']

type Listener = () => void

function b64ToBytes(b64: string): Uint8Array {
  const bin = atob(b64)
  const out = new Uint8Array(bin.length)
  for (let i = 0; i < bin.length; i++) out[i] = bin.charCodeAt(i)
  return out
}

export class Engine {
  state: EngineState = { phase: 'boot', identity: null, active: null, online: navigator.onLine, lastContact: null, lastManifestAt: null, clockOffsetMs: 0, pairing: null, download: null, errors: [], readyAssets: new Set(), live: null, paused: false }
  private listeners = new Set<Listener>()
  private timers: number[] = []
  private nextSync = 0
  private nextHeartbeat = 0
  private syncing = false
  private beating = false
  private offlineSince: string | null = null
  private playback: Record<string, unknown> | null = null
  private recentPlays = new Map<string, number>()
  private pendingPlays = new Map<string, { entry: { entry_id: string; campaign_id: string; content_id?: string; content_version?: string; lang: string }; manifestVersion: number; plays: number; seconds: number; first: string; last: string; windowStart: number }>()
  private captured = new Set<string>()
  private panelAwake: boolean | null = null
  /** Capacités annoncées par l'agent local de la dalle (veille, redémarrage), relues à chaque battement. */
  private agentCapabilities: string[] = []
  private handledCommands = new Set<string>()
  private readonly bootedAt = Date.now()
  private failedSyncs = 0
  /** Dernière télémétrie de l'agent local et prochaine tentative (espacée de 10 min quand l'agent est absent). */
  private agentSystem: Record<string, unknown> | null = null
  /** §7.1-f : début de l'affichage du seul contenu de repli (aucune entrée programmée). */
  private fallbackSince: number | null = null
  /** §7.2-c : serveur de rattachement (par défaut, celui qui sert le lecteur). */
  private apiBase = API
  private nextAgentProbe = 0

  subscribe = (listener: Listener) => {
    this.listeners.add(listener)
    return () => this.listeners.delete(listener)
  }
  getSnapshot = () => this.state

  private set(patch: Partial<EngineState>): void {
    this.state = { ...this.state, ...patch }
    this.listeners.forEach((l) => l())
  }

  /** Heure corrigée de la dérive constatée par rapport à l'horloge du serveur (heartbeat). */
  now(): Date {
    return new Date(Date.now() + this.state.clockOffsetMs)
  }

  async start(): Promise<void> {
    await requestPersistence()
    await loadSecureStorageFlag()
    const [identity, active, offset, live, paused] = await Promise.all([getIdentity(), getActive(), kv.get<number>('clock_offset'), kv.get<LiveData>('live'), kv.get<boolean>('playback_paused')])
    const ready = new Set(await cachedIds())
    const server = await kv.get<string>('server_url')
    if (server) this.apiBase = `${server.replace(/\/$/, '')}${API}`
    this.set({ identity: identity ?? null, active: active ?? null, clockOffsetMs: offset ?? 0, readyAssets: ready, lastManifestAt: active?.activatedAt ?? null, live: live ?? null, paused: paused ?? false })
    if (!identity) {
      await this.startPairing()
    } else {
      this.set({ phase: identity.revoked ? 'revoked' : 'running' })
      this.loop()
    }
    window.addEventListener('online', () => { this.nextSync = 0; this.nextHeartbeat = 0 })
  }

  /** Appairage : code à usage unique de 10 minutes affiché sur l'écran (PLY-02, T04). */
  async startPairing(): Promise<void> {
    this.stopLoop()
    let hardwareId = await kv.get<string>('hardware_id')
    if (!hardwareId) {
      hardwareId = `saie-${crypto.randomUUID()}`
      await kv.set('hardware_id', hardwareId)
    }
    this.set({ phase: 'pairing', pairing: null })
    const request = async (): Promise<void> => {
      try {
        const response = await fetch(`${API}/pairing-codes`, {
          method: 'POST', headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
          body: JSON.stringify({ hardware_id: hardwareId, info: { app_version: APP_VERSION, platform: navigator.userAgent.slice(0, 120), resolution: `${screen.width}x${screen.height}` } }),
        })
        if (!response.ok) throw new Error(`HTTP ${response.status}`)
        const data = (await response.json()) as { code: string; poll_secret: string; expires_at: string }
        this.set({ pairing: { code: data.code, expiresAt: data.expires_at }, online: true })
        this.pollPairing(data.code, data.poll_secret, request)
      } catch {
        this.set({ online: false })
        this.timers.push(window.setTimeout(request, 10_000))
      }
    }
    await request()
  }

  private pollPairing(code: string, secret: string, renew: () => Promise<void>): void {
    const tick = async () => {
      try {
        const response = await fetch(`${API}/pairing-codes/${code}`, { headers: { 'X-Poll-Secret': secret, Accept: 'application/json' } })
        if (response.status === 410) return renew()
        if (response.ok) {
          const data = await response.json()
          if (data.status === 'paired') {
            const identity: Identity = { deviceId: data.device.id, deviceCode: data.device.code, token: data.token, publicKey: data.public_key, keyId: data.key_id, pairedAt: new Date().toISOString() }
            await setIdentity(identity)
            this.set({ identity, phase: 'running', pairing: null })
            this.nextSync = 0
            this.nextHeartbeat = 0
            this.loop()
            return
          }
        }
      } catch {
        this.set({ online: false })
      }
      this.timers.push(window.setTimeout(tick, 3000))
    }
    this.timers.push(window.setTimeout(tick, 3000))
  }

  private stopLoop(): void {
    this.timers.forEach((t) => clearTimeout(t))
    this.timers = []
  }

  private loop(): void {
    const tick = async () => {
      const now = Date.now()
      const manifest = this.state.active?.manifest
      // Heartbeat et synchronisation sont indépendants : l'un bloqué ne retarde pas l'autre.
      if (now >= this.nextHeartbeat && !this.beating) {
        this.nextHeartbeat = now + 1000 * (manifest?.config?.settings.heartbeat_interval_s ?? HEARTBEAT_MS / 1000)
        this.beating = true
        void this.heartbeat().finally(() => { this.beating = false })
      }
      if (now >= this.nextSync && !this.syncing) {
        const awake = !manifest || withinWindow(manifest, this.now(), manifest.operating_window.wake_before_min ?? 5)
        // §7.3 : mise en veille de la dalle hors plage, sauf si le profil la désactive ; §7.1-f : veille après inactivité.
        const standbyOutside = manifest?.config?.settings.standby_outside_window !== false
        const idleMin = manifest?.config?.settings.idle_standby_min
        const idle = Boolean(idleMin && this.fallbackSince !== null && now - this.fallbackSince >= idleMin * 60_000)
        if (manifest && (standbyOutside || idleMin)) this.syncPanel((standbyOutside ? awake : true) && !idle)
        // Hors ligne : nouvelles tentatives espacées progressivement jusqu'au délai maximal du profil (politique de reconnexion).
        const base = awake ? 1000 * (manifest?.config?.settings.sync_interval_s ?? SYNC_MS / 1000) : OFF_WINDOW_SYNC_MS
        const backoff = this.state.online ? base : Math.min(1000 * (manifest?.config?.settings.reconnect_max_s ?? 300), base * 2 ** Math.min(this.failedSyncs, 5))
        this.nextSync = now + backoff + Math.round(Math.random() * 5000)
        await this.sync()
      }
      this.timers.push(window.setTimeout(tick, 1000))
    }
    tick()
  }

  private async call(path: string, init: RequestInit = {}): Promise<Response> {
    const token = this.state.identity?.token
    const url = `${this.apiBase}${path}`
    setSigningClockOffset(this.state.clockOffsetMs)
    const signature = token ? await signRequest(token, (init.method ?? 'GET').toUpperCase(), new URL(url, location.origin).pathname, init.body) : {}
    const response = await fetch(url, { signal: AbortSignal.timeout(CALL_TIMEOUT_MS), ...init, headers: { Accept: 'application/json', Authorization: `Bearer ${token}`, ...signature, ...(init.headers ?? {}) } })
    if (response.status === 401) {
      // Signature refusée (horloge, rejeu) : erreur passagère, jamais une révocation définitive du lecteur.
      const code = ((await response.clone().json().catch(() => ({}))) as { code?: string }).code ?? ''
      if (/^(SIGNATURE_|REPLAYED_)/.test(code)) throw new Error(`Requête refusée par le serveur (${code})`)
      await this.markRevoked()
      throw new Error('Lecteur révoqué')
    }
    return response
  }

  /** Clés annoncées par un manifeste dont la signature vient d'être vérifiée : elles serviront après la bascule de clé. */
  private async rememberTrustedKeys(manifest: Manifest): Promise<void> {
    const identity = this.state.identity
    if (!identity || !manifest.trusted_keys?.length) return
    const trustedKeys = Object.fromEntries(manifest.trusted_keys.map((k) => [k.key_id, k.public_key]))
    if (JSON.stringify(trustedKeys) === JSON.stringify(identity.trustedKeys ?? {})) return
    const updated = { ...identity, trustedKeys }
    await setIdentity(updated)
    this.set({ identity: updated })
  }

  /** PLY-02 : renouvellement du jeton après 30 jours (durée de vie serveur : 90 jours) ; l'ancien jeton est révoqué par le serveur. */
  private async rotateTokenIfDue(force = false): Promise<void> {
    const identity = this.state.identity
    if (!identity || identity.revoked) return
    const issued = Date.parse(identity.tokenIssuedAt ?? identity.pairedAt)
    // §9.6 : un renouvellement demandé par l'administration est exécuté sans attendre l'échéance.
    if (!force && Number.isFinite(issued) && Date.now() - issued < TOKEN_ROTATE_MS) return
    const response = await this.call('/token/rotate', { method: 'POST' })
    if (!response.ok) return
    const { token } = (await response.json()) as { token: string }
    const renewed = { ...identity, token, tokenIssuedAt: new Date().toISOString() }
    await setIdentity(renewed)
    this.set({ identity: renewed })
  }

  /** Révocation : l'API est refusée ; le cache reste lisible jusqu'à l'expiration de l'autorisation en cours (R17). */
  private async markRevoked(): Promise<void> {
    const identity = this.state.identity
    if (identity && !identity.revoked) {
      const revoked = { ...identity, revoked: true }
      await setIdentity(revoked)
      this.set({ identity: revoked, phase: 'revoked' })
    }
  }

  async resetPairing(): Promise<void> {
    await clearIdentity()
    this.set({ identity: null })
    await this.startPairing()
  }

  private markOnline(online: boolean): void {
    if (online && !this.state.online && this.offlineSince) {
      enqueue({ kind: 'online', payload: { offline_since: this.offlineSince } })
      this.offlineSince = null
    }
    if (!online && this.state.online) {
      this.offlineSince = new Date().toISOString()
      enqueue({ kind: 'offline' })
    }
    this.set({ online, lastContact: online ? new Date().toISOString() : this.state.lastContact })
  }

  async sync(): Promise<void> {
    if (!this.state.identity || this.state.identity.revoked) return
    this.syncing = true
    try {
      const headers: Record<string, string> = {}
      const etag = await kv.get<string>('etag')
      if (etag && this.state.active) headers['If-None-Match'] = etag
      const response = await this.call('/manifest', { headers })
      this.markOnline(true)
      this.failedSyncs = 0
      if (response.status === 304) return
      if (!response.ok) throw new Error(`Manifeste indisponible (${response.status})`)
      const envelope = (await response.json()) as Envelope
      const manifest = this.verify(envelope)
      const verdict = acceptManifest(manifest, this.state.identity.deviceCode, this.state.active?.manifest ?? null, this.now())
      if (verdict === 'same') {
        await kv.set('etag', response.headers.get('ETag'))
        return
      }
      if (verdict === 'renew' && this.state.active) {
        // Même programme, autorisation hors ligne prolongée : nouvelle enveloppe conservée, aucune réactivation ni accusé d'activation.
        await this.rememberTrustedKeys(manifest)
        const renewed: ActiveState = { envelope, manifest, activatedAt: this.state.active.activatedAt }
        await setActive(renewed)
        await kv.set('etag', response.headers.get('ETag'))
        const live = liveData(manifest)
        await kv.set('live', live)
        this.set({ active: renewed, live, lastManifestAt: new Date().toISOString() })
        await enqueue({ kind: 'manifest_received', manifest_version: manifest.version, payload: { renewal: true } })
        return
      }
      if (verdict !== 'accept') throw new Error(verdict)
      await this.rememberTrustedKeys(manifest)
      await enqueue({ kind: 'manifest_received', manifest_version: manifest.version })
      // Alertes et informations de salle appliquées immédiatement (PUB-06 : < 60 s), avant le téléchargement des médias.
      const live = liveData(manifest)
      await kv.set('live', live)
      this.set({ live })
      await this.prepare(manifest)
      const state: ActiveState = { envelope, manifest, activatedAt: new Date().toISOString() }
      await setActive(state)
      await kv.set('etag', response.headers.get('ETag'))
      this.set({ active: state, lastManifestAt: state.activatedAt, errors: [] })
      await enqueue({ kind: 'content_loaded', manifest_version: manifest.version })
      await enqueue({ kind: 'activated', manifest_version: manifest.version })
      for (const alert of manifest.alerts) await enqueue({ kind: 'alert_received', ref_type: 'alert', ref_id: alert.id, manifest_version: manifest.version })
      await this.applyRelease(manifest)
      await this.applyServer(manifest)
    } catch (error) {
      const message = (error as Error).message
      if (error instanceof TypeError || (error as Error).name === 'TimeoutError' || (error as Error).name === 'AbortError') {
        this.failedSyncs++
        this.markOnline(false)
      } else this.pushError(message)
    } finally {
      this.syncing = false
    }
  }

  /** Vérifie la signature Ed25519 sur la chaîne exacte du manifeste avant tout usage (PLY-03). */
  private verify(envelope: Envelope): Manifest {
    const identity = this.state.identity!
    const key = verificationKey(identity, envelope.signature.key_id)
    if (!key) throw new Error(`Clé de signature inconnue (${envelope.signature.key_id}) : version ignorée`)
    const ok = nacl.sign.detached.verify(new TextEncoder().encode(envelope.payload), b64ToBytes(envelope.signature.value), b64ToBytes(key))
    if (!ok) throw new Error('Signature du manifeste invalide : version ignorée')

    return JSON.parse(envelope.payload) as Manifest
  }

  /**
   * Télécharge toutes les ressources nécessaires avant la bascule ; en cas d'échec, la version précédente reste active
   * (aucune activation partielle, R05). Réserve de 20 % du quota, éviction des ressources non référencées.
   */
  private async prepare(manifest: Manifest): Promise<void> {
    const missing = []
    for (const asset of manifest.assets) if (!(await hasAsset(asset))) missing.push(asset)
    const keep = new Set([...manifest.assets.map((a) => a.id), ...(this.state.active?.manifest.assets.map((a) => a.id) ?? [])])
    const needed = missing.reduce((sum, a) => sum + a.size, 0)
    let estimate = await storageEstimate()
    if (estimate.quota && estimate.usage + needed > estimate.quota * (1 - RESERVE)) {
      await evictUnreferenced(keep)
      estimate = await storageEstimate()
      if (estimate.usage + needed > estimate.quota * (1 - RESERVE)) throw new Error('Stockage insuffisant : programme précédent conservé')
    }
    this.set({ download: missing.length ? { done: 0, total: missing.length } : null })
    let done = 0
    for (const asset of missing) {
      // Délai proportionnel à la taille (débit plancher d'environ 100 ko/s, minimum une minute) : un transfert bloqué n'immobilise pas le lecteur.
      await downloadAsset(asset, this.state.identity!.token, AbortSignal.timeout(Math.max(60_000, Math.round(asset.size / 100))))
      done++
      this.set({ download: { done, total: missing.length } })
    }
    this.set({ download: null, readyAssets: new Set(await cachedIds()) })
  }

  async heartbeat(): Promise<void> {
    if (!this.state.identity || this.state.identity.revoked) return
    try {
      this.flushPlays()
      const estimate = await storageEstimate()
      const panel = await this.panel()
      const dropped = (await kv.get<number>('events_dropped')) ?? 0
      const response = await this.call('/heartbeat', {
        method: 'POST', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          app_version: APP_VERSION, platform: navigator.userAgent.slice(0, 120), resolution: `${window.innerWidth}x${window.innerHeight}`,
          device_time: new Date().toISOString(), active_manifest_version: this.state.active?.manifest.version ?? null,
          storage: estimate, errors: [...this.state.errors.slice(-9), ...(dropped ? [`${dropped} événement(s) ancien(s) écarté(s) : file pleine pendant la coupure`] : [])], playback: this.playback,
          cache_inventory: [...this.state.readyAssets].slice(0, 500), offline_since: this.offlineSince, events_dropped: dropped, ...(panel ? { panel_state: panel } : {}),
          capabilities: this.capabilities(panel), playback_paused: this.state.paused, device_info: { ...this.deviceInfo(), ...(await this.systemInfo()) }, ...this.configReport(panel),
        }),
      })
      if (!response.ok) throw new Error(`Heartbeat refusé (${response.status})`)
      if (dropped) await kv.set('events_dropped', 0)
      const data = (await response.json()) as { server_time: string; screenshot_requests?: string[]; commands?: RemoteCommand[]; rotate_token?: boolean; secure_storage?: boolean }
      if (data.rotate_token) await this.rotateTokenIfDue(true)
      if (data.secure_storage) await enableSecureStorage(this.state.identity)
      await this.provisionAgent()
      for (const id of data.screenshot_requests ?? []) await this.captureScreenshot(id)
      // Relance ou redémarrage confirmés après le retour du lecteur : l'exécution est alors prouvée.
      await this.confirmRestart()
      for (const command of data.commands ?? []) {
        if (typeof command === 'object' && command?.id) await this.runCommand(command)
      }
      const offset = Date.parse(data.server_time) - Date.now()
      await kv.set('clock_offset', offset)
      this.markOnline(true)
      this.set({ clockOffsetMs: offset })
      await this.flush()
      await this.rotateTokenIfDue()
    } catch (error) {
      if (error instanceof TypeError || (error as Error).name === 'TimeoutError' || (error as Error).name === 'AbortError') this.markOnline(false)
    }
  }

  /**
   * Pilotage de la dalle par l'agent local (PLY-08) et retour d'état (PLY-09), uniquement si un canal de pilotage est déclaré.
   * Sans agent joignable, l'état reste « non mesuré » côté serveur et l'écran noir logiciel s'applique.
   */
  async panel(power?: 'on' | 'off'): Promise<'on' | 'off' | 'standby' | 'unknown' | null> {
    const channel = this.state.active?.manifest.screen.control_channel
    if (!channel || channel === 'none') {
      this.agentCapabilities = []
      return null
    }
    try {
      const response = await fetch(PANEL_AGENT, power ? { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ power }) } : {})
      const data = (await response.json()) as { state?: string; error?: string; capabilities?: string[] }
      if (data.error) this.pushError(`Pilotage de la dalle : ${data.error}`)
      this.agentCapabilities = data.error ? [] : Array.isArray(data.capabilities) ? data.capabilities : ['panel_power']
      return (['on', 'off', 'standby'].includes(data.state ?? '') ? data.state : 'unknown') as 'on' | 'off' | 'standby' | 'unknown'
    } catch {
      this.agentCapabilities = []
      return 'unknown'
    }
  }

  /**
   * Caractéristiques observables depuis le navigateur (§9.1). Adresse MAC, système et firmware ne sont pas accessibles à une
   * application web : ils ne sont jamais inventés (l'agent local pourra les fournir).
   */
  private deviceInfo(): Record<string, unknown> {
    const nav = navigator as Navigator & { deviceMemory?: number }
    return {
      screen: `${screen.width}x${screen.height}`, pixel_ratio: window.devicePixelRatio, cores: navigator.hardwareConcurrency || null, memory_gb: nav.deviceMemory ?? null,
      language: navigator.language, timezone: Intl.DateTimeFormat().resolvedOptions().timeZone, touch: navigator.maxTouchPoints > 0,
    }
  }

  /**
   * §7 : compte rendu de la configuration du manifeste actif. Un paramètre n'est « appliqué » que si ce lecteur sait le faire ;
   * luminosité et contraste exigent un pilotage d'image de la dalle que ni le navigateur ni l'agent actuel ne fournissent.
   */
  private configReport(panel: string | null): { config?: { hash: string; applied: string[]; unsupported: string[] } } {
    const config = this.state.active?.manifest.config
    if (!config) return {}
    const applied: string[] = []
    const unsupported: string[] = []
    for (const key of Object.keys(config.settings ?? {})) {
      const ok = key === 'brightness' || key === 'contrast' ? this.agentCapabilities.includes('panel_picture')
        : key === 'standby_outside_window' ? Boolean(panel && panel !== 'unknown' && this.agentCapabilities.includes('panel_power'))
          : key === 'fullscreen' ? Boolean(document.fullscreenElement) || window.innerHeight >= screen.height - 2
            : key === 'idle_standby_min' ? Boolean(panel && panel !== 'unknown' && this.agentCapabilities.includes('panel_power'))
              : key === 'server_url' ? this.apiBase === `${String(config.settings.server_url).replace(/\/$/, '')}${API}`
                : true
      ;(ok ? applied : unsupported).push(key)
    }
    return { config: { hash: config.hash, applied, unsupported } }
  }

  /** §7.3 : plein écran demandé par le profil (refusé par le navigateur sans mode kiosque ni geste : signalé non appliqué). */
  applyFullscreen(): void {
    if (this.state.active?.manifest.config?.settings.fullscreen && !document.fullscreenElement) {
      document.documentElement.requestFullscreen?.().catch(() => undefined)
    }
  }

  /**
   * §10.16 : remet à l'agent local un jeton de portée « agent » et l'adresse du serveur, pour qu'il signale lui-même que
   * le poste répond quand l'application de lecture est arrêtée. Renouvelé chaque jour.
   */
  private agentTokenAt = 0
  private async provisionAgent(): Promise<void> {
    if (!this.agentSystem || Date.now() - this.agentTokenAt < 86_400_000) return
    try {
      const response = await this.call('/agent-token', { method: 'POST' })
      if (!response.ok) return
      const { token } = (await response.json()) as { token: string }
      const server = this.apiBase.startsWith('http') ? this.apiBase.replace(/\/api\/v1\/device$/, '') : location.origin
      const posted = await fetch(PANEL_AGENT.replace(/\/panel$/, '/credentials'), { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ server, token }), signal: AbortSignal.timeout(3000) })
      if (posted.ok) this.agentTokenAt = Date.now()
    } catch {
      // Agent absent : nouvel essai au prochain battement espacé.
    }
  }

  /** §10.2 : télémétrie matérielle fournie par l'agent local (disque, mémoire, processeur, température, système, MAC). */
  private async systemInfo(): Promise<Record<string, unknown>> {
    if (Date.now() < this.nextAgentProbe) return this.agentSystem ?? {}
    try {
      const response = await fetch(PANEL_AGENT.replace(/\/panel$/, '/system'), { signal: AbortSignal.timeout(3000) })
      this.agentSystem = response.ok ? ((await response.json()) as Record<string, unknown>) : null
    } catch {
      this.agentSystem = null
    }
    this.nextAgentProbe = Date.now() + (this.agentSystem ? 0 : 600_000)
    return this.agentSystem ?? {}
  }

  /** Capacités déclarées au serveur (RG-006) : jamais une commande que ce lecteur ne saurait exécuter. */
  private capabilities(panel: string | null): string[] {
    const fromAgent = panel && panel !== 'unknown' ? this.agentCapabilities.filter((c) => c === 'panel_power' || c === 'reboot_device') : []
    return [...SOFTWARE_CAPABILITIES, ...fromAgent]
  }

  private async ackCommand(id: string, status: 'received' | 'executed' | 'failed', message?: string, data?: Record<string, unknown>): Promise<boolean> {
    try {
      const response = await this.call(`/commands/${id}/ack`, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ status, message, data }) })
      return response.ok
    } catch {
      return false
    }
  }

  /**
   * Exécution d'une commande à distance : accusé de réception, puis compte rendu réel. Une commande déjà traitée (renvoyée
   * faute d'accusé reçu par le serveur) n'est pas réexécutée.
   */
  private async runCommand(command: RemoteCommand): Promise<void> {
    if (this.handledCommands.has(command.id)) return
    if (Date.parse(command.expires_at) < this.now().getTime()) return
    this.handledCommands.add(command.id)
    await this.ackCommand(command.id, 'received')
    let outcome: CommandOutcome
    try {
      outcome = await this.execute(command)
    } catch (error) {
      outcome = { status: 'failed', message: (error as Error).message.slice(0, 400) }
    }
    if (outcome !== 'deferred') await this.ackCommand(command.id, outcome.status, outcome.message, outcome.data)
  }

  private async execute(command: RemoteCommand): Promise<CommandOutcome> {
    switch (command.command) {
      case 'sync': {
        this.nextSync = 0
        await this.sync()
        return { status: 'executed', message: `Synchronisation effectuée : programme v${this.state.active?.manifest.version ?? '—'}`, data: { manifest_version: this.state.active?.manifest.version ?? null } }
      }
      case 'refresh': {
        // Recharge le manifeste sans condition (ETag oublié) et revérifie la présence de chaque ressource du programme.
        await kv.set('etag', null)
        await this.sync()
        this.set({ readyAssets: new Set(await cachedIds()) })
        const manifest = this.state.active?.manifest
        const missing = manifest ? manifest.assets.filter((a) => !this.state.readyAssets.has(a.id)).length : 0
        return missing ? { status: 'failed', message: `${missing} ressource(s) encore absente(s) du cache`, data: { missing } } : { status: 'executed', message: 'Contenus rechargés et vérifiés', data: { assets: manifest?.assets.length ?? 0 } }
      }
      case 'restart_player':
      case 'reboot_device': {
        // L'exécution est confirmée après la relance (confirmRestart), quand le lecteur a effectivement redémarré.
        await kv.set('pending_restart', { id: command.id, command: command.command, at: new Date().toISOString() })
        this.flushPlays(true)
        await this.flush().catch(() => undefined)
        if (command.command === 'reboot_device') {
          const response = await fetch(PANEL_AGENT, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ action: 'reboot' }) })
          if (!response.ok) {
            await kv.set('pending_restart', null)
            return { status: 'failed', message: `Agent local : redémarrage refusé (${response.status})` }
          }
          const body = (await response.json()) as { simulated?: boolean }
          if (!body.simulated) return 'deferred'
          // Agent en simulation : l'appareil n'est pas redémarré, seule l'application l'est (résultat signalé comme simulé).
          await kv.set('pending_restart', { id: command.id, command: command.command, at: new Date().toISOString(), simulated: true })
        }
        window.location.reload()
        return 'deferred'
      }
      case 'stop_playback':
      case 'resume_playback': {
        const paused = command.command === 'stop_playback'
        await kv.set('playback_paused', paused)
        this.set({ paused })
        return { status: 'executed', message: paused ? 'Diffusion arrêtée : écran noir, alertes maintenues' : 'Diffusion reprise' }
      }
      case 'standby':
      case 'wake': {
        const target = command.command === 'standby' ? 'off' : 'on'
        const state = await this.panel(target)
        this.panelAwake = target === 'on'
        const ok = target === 'on' ? state === 'on' : state === 'off' || state === 'standby'
        return ok ? { status: 'executed', message: `Dalle ${target === 'on' ? 'allumée' : 'en veille'} (état relu : ${state})`, data: { panel_state: state } }
          : { status: 'failed', message: `État de la dalle après la commande : ${state ?? 'non mesuré'}`, data: { panel_state: state } }
      }
      case 'screenshot': {
        const id = command.params?.screenshot_id
        if (!id) return { status: 'failed', message: 'Demande de capture sans identifiant' }
        const error = await this.captureScreenshot(id)
        return error ? { status: 'failed', message: error } : { status: 'executed', message: 'Capture du rendu logiciel transmise (la sortie vidéo de la dalle n’est pas capturée)' }
      }
      case 'logs': {
        const estimate = await storageEstimate()
        const queued = (await peekEvents()).length
        return {
          status: 'executed', message: `${this.state.errors.length} erreur(s) récente(s) transmise(s)`,
          data: {
            app_version: APP_VERSION, user_agent: navigator.userAgent.slice(0, 200), screen: `${screen.width}x${screen.height}`, viewport: `${window.innerWidth}x${window.innerHeight}`,
            uptime_s: Math.round((Date.now() - this.bootedAt) / 1000), online: this.state.online, last_contact: this.state.lastContact, manifest_version: this.state.active?.manifest.version ?? null,
            cached_assets: this.state.readyAssets.size, queued_events: queued, storage: estimate, paused: this.state.paused, clock_offset_ms: this.state.clockOffsetMs,
            errors: this.state.errors.slice(-50),
          },
        }
      }
      default:
        return { status: 'failed', message: `Commande inconnue de cette version du lecteur : ${command.command}` }
    }
  }

  /** Après une relance commandée, le lecteur revenu confirme l'exécution (preuve du redémarrage effectif). */
  private async confirmRestart(): Promise<void> {
    const pending = await kv.get<{ id: string; command: string; at: string; simulated?: boolean }>('pending_restart')
    if (!pending) return
    const label = pending.command === 'reboot_device' ? (pending.simulated ? 'Agent en simulation : application relancée, appareil non redémarré' : 'Appareil redémarré') : 'Application relancée'
    if (await this.ackCommand(pending.id, 'executed', `${label} (demande reçue à ${pending.at})`, { booted_at: new Date(this.bootedAt).toISOString(), simulated: pending.simulated ?? false })) {
      this.handledCommands.add(pending.id)
      await kv.set('pending_restart', null)
    }
  }

  /** Applique la plage de fonctionnement à la dalle lors des transitions (extinction hors plage, rallumage à l'ouverture). */
  /**
   * §7.2-c : rattachement à un autre serveur autorisé. Le nouveau serveur doit répondre avant la bascule ; sinon le
   * lecteur reste sur le serveur actuel et le signale.
   */
  private async applyServer(manifest: Manifest): Promise<void> {
    const target = manifest.config?.settings.server_url?.replace(/\/$/, '')
    if (!target || this.apiBase === `${target}${API}`) return
    try {
      const probe = await fetch(`${target}${API}/key`, { signal: AbortSignal.timeout(5000) })
      if (!probe.ok) throw new Error(`HTTP ${probe.status}`)
      await kv.set('server_url', target)
      this.apiBase = `${target}${API}`
      this.nextSync = 0
    } catch (error) {
      this.pushError(`Serveur de rattachement injoignable (${target}) : ${String(error)}`)
    }
  }

  syncPanel(awake: boolean): void {
    if (this.panelAwake === awake) return
    this.panelAwake = awake
    this.panel(awake ? 'on' : 'off')
  }

  /**
   * Mise à jour par canal (PLY-12) : la version cible vient du manifeste signé ; l'empreinte SHA-256 de son index.html est
   * vérifiée avant la bascule. Un retrait côté serveur ramène automatiquement le canal à la version précédente.
   */
  private async applyRelease(manifest: Manifest): Promise<void> {
    const release = manifest.player_release
    // Plus aucune version cible pour le canal (retrait de la dernière) : retour à la version de base (PLY-12, retour arrière).
    if (!release && location.pathname.startsWith('/player/releases/')) {
      await enqueue({ kind: 'activated', ref_type: 'release', ref_id: 'base', manifest_version: manifest.version })
      await this.flush()
      location.replace('/player/')
      return
    }
    if (!release || release.version === APP_VERSION || !release.url.startsWith('/player/releases/')) return
    if (location.pathname.startsWith(release.url)) return
    try {
      const response = await fetch(`${release.url}index.html`, { cache: 'no-store' })
      if (!response.ok) throw new Error(`Version ${release.version} indisponible (${response.status})`)
      const digest = await crypto.subtle.digest('SHA-256', await response.arrayBuffer())
      const hex = Array.from(new Uint8Array(digest)).map((b) => b.toString(16).padStart(2, '0')).join('')
      if (hex !== release.sha256) throw new Error(`Empreinte invalide pour la version ${release.version} : mise à jour refusée`)
      await enqueue({ kind: 'activated', ref_type: 'release', ref_id: release.version, manifest_version: manifest.version })
      await this.flush()
      location.replace(release.url)
    } catch (error) {
      this.pushError((error as Error).message)
    }
  }

  /**
   * Capture du rendu logiciel à la demande (PLY-11) : image du DOM affiché, téléversée une seule fois.
   * Elle prouve le rendu du lecteur, pas l'allumage de la dalle ; les images vidéo en cours ne sont pas restituées.
   */
  private async captureScreenshot(id: string): Promise<string | null> {
    if (this.captured.has(id)) return null
    this.captured.add(id)
    try {
      const stage = document.querySelector<HTMLElement>('.stage')
      if (!stage) throw new Error('Aucun rendu à capturer')
      const { toBlob } = await import('html-to-image')
      const blob = await toBlob(stage, { pixelRatio: 1, cacheBust: false, style: { transform: 'none' } })
      if (!blob) throw new Error('Capture vide')
      const form = new FormData()
      form.append('image', blob, `capture-${id}.png`)
      const response = await this.call(`/screenshots/${id}`, { method: 'POST', body: form })
      if (!response.ok) throw new Error(`Capture refusée (${response.status})`)
      return null
    } catch (error) {
      const message = `Capture impossible : ${(error as Error).message}`
      this.pushError(message)
      return message
    }
  }

  /** Remontée différée des événements ; le serveur dédoublonne par identifiant d'événement (PLY-06). */
  async flush(): Promise<void> {
    const events = await peekEvents()
    for (let i = 0; i < events.length; i += 200) {
      const batch = events.slice(i, i + 200)
      const response = await this.call('/receipts', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ events: batch }) })
      if (!response.ok) return
      await ackEvents(batch.map((e) => e.event_id))
    }
  }

  /** Diapositive en cours d'affichage, remontée au heartbeat (état courant, pas une preuve de lecture). */
  reportPlaying(entry: { entry_id: string; lang: string }): void {
    this.fallbackSince = null
    this.playback = { entry_id: entry.entry_id, lang: entry.lang, at: new Date().toISOString() }
  }

  /**
   * Lecture déclarée à la fin d'un créneau d'affichage réussi (PUB-07, annexe C : « lecture déclarée »).
   * La première lecture d'une entrée est remontée aussitôt ; les suivantes sont agrégées par tranche de 10 minutes
   * (nombre de passages et durée cumulée), ce qui borne le volume sans perdre le comptage.
   */
  reportPlay(entry: { entry_id: string; campaign_id: string; content_id?: string; content_version?: string; lang: string }, manifestVersion: number, seconds: number): void {
    const now = new Date().toISOString()
    const pending = this.pendingPlays.get(entry.entry_id)
    if (!pending) {
      this.pendingPlays.set(entry.entry_id, { entry, manifestVersion, plays: 0, seconds: 0, first: now, last: now, windowStart: Date.now() })
      this.emitPlay({ entry, manifestVersion, plays: 1, seconds, first: now, last: now })
      return
    }
    pending.plays++
    pending.seconds += seconds
    pending.last = now
    pending.manifestVersion = manifestVersion
    this.flushPlays()
  }

  /** Remonte les lectures agrégées dont la tranche de 10 minutes est écoulée. */
  flushPlays(force = false): void {
    for (const [id, p] of this.pendingPlays) {
      if (!force && Date.now() - p.windowStart < 600_000) continue
      if (p.plays > 0) this.emitPlay(p)
      this.pendingPlays.delete(id)
    }
  }

  private emitPlay(p: { entry: { entry_id: string; campaign_id: string; content_id?: string; content_version?: string; lang: string }; manifestVersion: number; plays: number; seconds: number; first: string; last: string }): void {
    enqueue({
      kind: 'played', ref_type: 'campaign', ref_id: p.entry.campaign_id, lang: p.entry.lang, manifest_version: p.manifestVersion, occurred_at: p.last,
      payload: { entry_id: p.entry.entry_id, content_id: p.entry.content_id ?? null, content_version: p.entry.content_version ?? null, plays: p.plays, duration_s: Math.round(p.seconds), first_played_at: p.first, last_played_at: p.last },
    })
  }

  reportAlertActivated(alertId: string, manifestVersion: number): void {
    const key = `alert:${alertId}`
    if (this.recentPlays.has(key)) return
    this.recentPlays.set(key, Date.now())
    this.playback = { alert: alertId, at: new Date().toISOString() }
    enqueue({ kind: 'alert_activated', ref_type: 'alert', ref_id: alertId, manifest_version: manifestVersion })
  }

  reportMode(mode: string): void {
    this.fallbackSince = mode === 'fallback' ? this.fallbackSince ?? Date.now() : null
    // Programme en cours : l'entrée affichée (posée par reportPlaying) fait foi, elle n'est pas remplacée par le mode.
    if (mode === 'program' && this.playback && 'entry_id' in this.playback) return
    if (this.playback?.mode === mode) return
    this.playback = { mode, at: new Date().toISOString() }
    if (mode === 'fallback' || mode === 'off') enqueue({ kind: mode === 'off' ? 'off_window' : 'fallback' })
  }

  /**
   * §10.9f : erreur codée (catégorie stable pour les statistiques) avec l'entrée et la ressource concernées si connues.
   */
  pushError(message: string, context: { entry_id?: string; asset_id?: string } = {}): void {
    const code = /vidéo/i.test(message) ? 'MEDIA_VIDEO' : /PDF|Document absent/i.test(message) ? 'MEDIA_PDF' : /dalle/i.test(message) ? 'PANEL_CONTROL'
      : /rattachement/i.test(message) ? 'SERVER_SWITCH' : /manifeste|signature|programme/i.test(message) ? 'MANIFEST'
        : /cache|ressource|téléchargement|empreinte|SHA/i.test(message) ? 'MEDIA_DOWNLOAD' : /heartbeat|réseau|fetch|timeout|délai|injoignable/i.test(message) ? 'NETWORK' : 'PLAYER'
    enqueue({ kind: 'error', ref_type: context.entry_id ? 'entry' : undefined, ref_id: context.entry_id, payload: { code, message, ...context } })
    this.set({ errors: [...this.state.errors, `${new Date().toISOString()} ${message}`].slice(-20) })
  }
}

export const engine = new Engine()
