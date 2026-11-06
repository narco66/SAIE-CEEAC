import nacl from 'tweetnacl'
import { acceptManifest, liveData, withinWindow, type LiveData } from './schedule.ts'
import {
  ackEvents, cachedIds, clearIdentity, downloadAsset, enqueue, evictUnreferenced, getActive, getIdentity, hasAsset, kv, peekEvents,
  requestPersistence, setActive, setIdentity, storageEstimate, type ActiveState,
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
}

type Listener = () => void

function b64ToBytes(b64: string): Uint8Array {
  const bin = atob(b64)
  const out = new Uint8Array(bin.length)
  for (let i = 0; i < bin.length; i++) out[i] = bin.charCodeAt(i)
  return out
}

export class Engine {
  state: EngineState = { phase: 'boot', identity: null, active: null, online: navigator.onLine, lastContact: null, lastManifestAt: null, clockOffsetMs: 0, pairing: null, download: null, errors: [], readyAssets: new Set(), live: null }
  private listeners = new Set<Listener>()
  private timers: number[] = []
  private nextSync = 0
  private nextHeartbeat = 0
  private syncing = false
  private beating = false
  private offlineSince: string | null = null
  private playback: Record<string, unknown> | null = null
  private recentPlays = new Map<string, number>()
  private captured = new Set<string>()
  private panelAwake: boolean | null = null

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
    const [identity, active, offset, live] = await Promise.all([getIdentity(), getActive(), kv.get<number>('clock_offset'), kv.get<LiveData>('live')])
    const ready = new Set(await cachedIds())
    this.set({ identity: identity ?? null, active: active ?? null, clockOffsetMs: offset ?? 0, readyAssets: ready, lastManifestAt: active?.activatedAt ?? null, live: live ?? null })
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
        this.nextHeartbeat = now + HEARTBEAT_MS
        this.beating = true
        void this.heartbeat().finally(() => { this.beating = false })
      }
      if (now >= this.nextSync && !this.syncing) {
        const awake = !manifest || withinWindow(manifest, this.now(), manifest.operating_window.wake_before_min ?? 5)
        if (manifest) this.syncPanel(awake)
        this.nextSync = now + (awake ? SYNC_MS : OFF_WINDOW_SYNC_MS) + Math.round(Math.random() * 5000)
        await this.sync()
      }
      this.timers.push(window.setTimeout(tick, 1000))
    }
    tick()
  }

  private async call(path: string, init: RequestInit = {}): Promise<Response> {
    const token = this.state.identity?.token
    const response = await fetch(`${API}${path}`, { signal: AbortSignal.timeout(CALL_TIMEOUT_MS), ...init, headers: { Accept: 'application/json', Authorization: `Bearer ${token}`, ...(init.headers ?? {}) } })
    if (response.status === 401) {
      await this.markRevoked()
      throw new Error('Lecteur révoqué')
    }
    return response
  }

  /** PLY-02 : renouvellement du jeton après 30 jours (durée de vie serveur : 90 jours) ; l'ancien jeton est révoqué par le serveur. */
  private async rotateTokenIfDue(): Promise<void> {
    const identity = this.state.identity
    if (!identity || identity.revoked) return
    const issued = Date.parse(identity.tokenIssuedAt ?? identity.pairedAt)
    if (Number.isFinite(issued) && Date.now() - issued < TOKEN_ROTATE_MS) return
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
      if (response.status === 304) return
      if (!response.ok) throw new Error(`Manifeste indisponible (${response.status})`)
      const envelope = (await response.json()) as Envelope
      const manifest = this.verify(envelope)
      const verdict = acceptManifest(manifest, this.state.identity.deviceCode, this.state.active?.manifest ?? null, this.now())
      if (verdict === 'same') {
        await kv.set('etag', response.headers.get('ETag'))
        return
      }
      if (verdict !== 'accept') throw new Error(verdict)
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
    } catch (error) {
      const message = (error as Error).message
      if (error instanceof TypeError || (error as Error).name === 'TimeoutError' || (error as Error).name === 'AbortError') this.markOnline(false)
      else this.pushError(message)
    } finally {
      this.syncing = false
    }
  }

  /** Vérifie la signature Ed25519 sur la chaîne exacte du manifeste avant tout usage (PLY-03). */
  private verify(envelope: Envelope): Manifest {
    const identity = this.state.identity!
    const ok = nacl.sign.detached.verify(new TextEncoder().encode(envelope.payload), b64ToBytes(envelope.signature.value), b64ToBytes(identity.publicKey))
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
      const estimate = await storageEstimate()
      const panel = await this.panel()
      const response = await this.call('/heartbeat', {
        method: 'POST', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          app_version: APP_VERSION, platform: navigator.userAgent.slice(0, 120), resolution: `${window.innerWidth}x${window.innerHeight}`,
          device_time: new Date().toISOString(), active_manifest_version: this.state.active?.manifest.version ?? null,
          storage: estimate, errors: this.state.errors.slice(-10), playback: this.playback,
          cache_inventory: [...this.state.readyAssets].slice(0, 500), offline_since: this.offlineSince, ...(panel ? { panel_state: panel } : {}),
        }),
      })
      if (!response.ok) throw new Error(`Heartbeat refusé (${response.status})`)
      const data = (await response.json()) as { server_time: string; screenshot_requests?: string[] }
      for (const id of data.screenshot_requests ?? []) await this.captureScreenshot(id)
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
    if (!channel || channel === 'none') return null
    try {
      const response = await fetch(PANEL_AGENT, power ? { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ power }) } : {})
      const data = (await response.json()) as { state?: string; error?: string }
      if (data.error) this.pushError(`Pilotage de la dalle : ${data.error}`)
      return (['on', 'off', 'standby'].includes(data.state ?? '') ? data.state : 'unknown') as 'on' | 'off' | 'standby' | 'unknown'
    } catch {
      return 'unknown'
    }
  }

  /** Applique la plage de fonctionnement à la dalle lors des transitions (extinction hors plage, rallumage à l'ouverture). */
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
  private async captureScreenshot(id: string): Promise<void> {
    if (this.captured.has(id)) return
    this.captured.add(id)
    try {
      const stage = document.querySelector<HTMLElement>('.stage')
      if (!stage) return
      const { toBlob } = await import('html-to-image')
      const blob = await toBlob(stage, { pixelRatio: 1, cacheBust: false, style: { transform: 'none' } })
      if (!blob) throw new Error('Capture vide')
      const form = new FormData()
      form.append('image', blob, `capture-${id}.png`)
      const response = await this.call(`/screenshots/${id}`, { method: 'POST', body: form })
      if (!response.ok) throw new Error(`Capture refusée (${response.status})`)
    } catch (error) {
      this.pushError(`Capture impossible : ${(error as Error).message}`)
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

  /** Lecture déclarée : au plus un événement par entrée et par tranche de 10 minutes. */
  reportPlay(entry: { entry_id: string; campaign_id: string; lang: string }, manifestVersion: number): void {
    this.playback = { entry_id: entry.entry_id, lang: entry.lang, at: new Date().toISOString() }
    const last = this.recentPlays.get(entry.entry_id) ?? 0
    if (Date.now() - last < 600_000) return
    this.recentPlays.set(entry.entry_id, Date.now())
    enqueue({ kind: 'played', ref_type: 'campaign', ref_id: entry.campaign_id, lang: entry.lang, manifest_version: manifestVersion, payload: { entry_id: entry.entry_id } })
  }

  reportAlertActivated(alertId: string, manifestVersion: number): void {
    const key = `alert:${alertId}`
    if (this.recentPlays.has(key)) return
    this.recentPlays.set(key, Date.now())
    this.playback = { alert: alertId, at: new Date().toISOString() }
    enqueue({ kind: 'alert_activated', ref_type: 'alert', ref_id: alertId, manifest_version: manifestVersion })
  }

  reportMode(mode: string): void {
    if (this.playback?.mode === mode) return
    this.playback = { mode, at: new Date().toISOString() }
    if (mode === 'fallback' || mode === 'off') enqueue({ kind: mode === 'off' ? 'off_window' : 'fallback' })
  }

  pushError(message: string): void {
    enqueue({ kind: 'error', payload: { message } })
    this.set({ errors: [...this.state.errors, `${new Date().toISOString()} ${message}`].slice(-20) })
  }
}

export const engine = new Engine()
