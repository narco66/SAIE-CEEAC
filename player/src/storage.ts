import type { Asset, DeviceEvent, Envelope, Identity, Manifest } from './types.ts'

/**
 * Persistance locale du lecteur (PLY-04) : IndexedDB pour l'identité, le manifeste actif et la file des événements
 * différés ; Cache Storage pour les ressources dont l'empreinte SHA-256 a été vérifiée.
 */

const DB_NAME = 'saie-player'
const STORE = 'kv'
const CACHE = 'saie-assets-v1'

function db(): Promise<IDBDatabase> {
  return new Promise((resolve, reject) => {
    const open = indexedDB.open(DB_NAME, 1)
    open.onupgradeneeded = () => open.result.createObjectStore(STORE)
    open.onsuccess = () => resolve(open.result)
    open.onerror = () => reject(open.error)
  })
}

async function tx<T>(mode: IDBTransactionMode, fn: (store: IDBObjectStore) => IDBRequest<T>): Promise<T> {
  const database = await db()
  return new Promise((resolve, reject) => {
    const t = database.transaction(STORE, mode)
    const request = fn(t.objectStore(STORE))
    t.oncomplete = () => resolve(request.result)
    t.onerror = () => reject(t.error)
  })
}

export const kv = {
  get: <T>(key: string) => tx<T | undefined>('readonly', (s) => s.get(key) as IDBRequest<T | undefined>),
  set: (key: string, value: unknown) => tx('readwrite', (s) => s.put(value, key)),
  del: (key: string) => tx('readwrite', (s) => s.delete(key)),
}

export const getIdentity = () => kv.get<Identity>('identity')
export const setIdentity = (identity: Identity) => kv.set('identity', identity)
export const clearIdentity = () => kv.del('identity')

export interface ActiveState { envelope: Envelope; manifest: Manifest; activatedAt: string }
export const getActive = () => kv.get<ActiveState>('active')
/** Bascule atomique : une seule écriture remplace le programme actif par la nouvelle version complète (PLY-03). */
export const setActive = (state: ActiveState) => kv.set('active', state)

const QUEUE_KEY = 'events'
export async function enqueue(event: Omit<DeviceEvent, 'event_id' | 'occurred_at'> & { occurred_at?: string }): Promise<void> {
  const queue = (await kv.get<DeviceEvent[]>(QUEUE_KEY)) ?? []
  queue.push({ event_id: crypto.randomUUID(), occurred_at: new Date().toISOString(), ...event })
  await kv.set(QUEUE_KEY, queue.slice(-2000))
}
export const peekEvents = async () => (await kv.get<DeviceEvent[]>(QUEUE_KEY)) ?? []
export async function ackEvents(ids: string[]): Promise<void> {
  const set = new Set(ids)
  const queue = (await kv.get<DeviceEvent[]>(QUEUE_KEY)) ?? []
  await kv.set(QUEUE_KEY, queue.filter((e) => !set.has(e.event_id)))
}

const assetKey = (id: string) => new Request(`/__saie_asset__/${id}`)

export async function hasAsset(asset: Asset): Promise<boolean> {
  const cache = await caches.open(CACHE)
  const match = await cache.match(assetKey(asset.id))
  return !!match && match.headers.get('X-SAIE-SHA256') === asset.sha256
}

export async function cachedIds(): Promise<string[]> {
  const cache = await caches.open(CACHE)
  return (await cache.keys()).map((r) => new URL(r.url).pathname.split('/').pop() ?? '')
}

export async function sha256Hex(buffer: ArrayBuffer): Promise<string> {
  const digest = await crypto.subtle.digest('SHA-256', buffer)
  return Array.from(new Uint8Array(digest)).map((b) => b.toString(16).padStart(2, '0')).join('')
}

/** Téléchargement vérifié par empreinte : une ressource corrompue n'entre jamais dans le cache. */
export async function downloadAsset(asset: Asset, token: string, signal?: AbortSignal): Promise<void> {
  const response = await fetch(asset.href, { headers: { Authorization: `Bearer ${token}`, Accept: '*/*' }, signal })
  if (!response.ok) throw new Error(`Téléchargement refusé (${response.status}) pour ${asset.id}`)
  const buffer = await response.arrayBuffer()
  const hash = await sha256Hex(buffer)
  if (hash !== asset.sha256) throw new Error(`Empreinte invalide pour ${asset.id}`)
  const cache = await caches.open(CACHE)
  await cache.put(assetKey(asset.id), new Response(buffer, { headers: { 'Content-Type': asset.type, 'X-SAIE-SHA256': asset.sha256 } }))
}

const urls = new Map<string, string>()
export async function assetUrl(id: string): Promise<string | null> {
  if (urls.has(id)) return urls.get(id)!
  const cache = await caches.open(CACHE)
  const match = await cache.match(assetKey(id))
  if (!match) return null
  const url = URL.createObjectURL(await match.blob())
  urls.set(id, url)
  return url
}

export async function assetBuffer(id: string): Promise<ArrayBuffer | null> {
  const cache = await caches.open(CACHE)
  const match = await cache.match(assetKey(id))
  return match ? match.arrayBuffer() : null
}

/** Libère d'abord les ressources non référencées par les manifestes conservés (PLY-04). */
export async function evictUnreferenced(keep: Set<string>): Promise<number> {
  const cache = await caches.open(CACHE)
  let removed = 0
  for (const request of await cache.keys()) {
    const id = new URL(request.url).pathname.split('/').pop() ?? ''
    if (!keep.has(id)) {
      await cache.delete(request)
      const url = urls.get(id)
      if (url) URL.revokeObjectURL(url)
      urls.delete(id)
      removed++
    }
  }
  return removed
}

export async function storageEstimate(): Promise<{ usage: number; quota: number; persisted: boolean }> {
  const estimate = (await navigator.storage?.estimate?.()) ?? {}
  const persisted = (await navigator.storage?.persisted?.()) ?? false
  return { usage: estimate.usage ?? 0, quota: estimate.quota ?? 0, persisted }
}

export async function requestPersistence(): Promise<boolean> {
  return (await navigator.storage?.persist?.()) ?? false
}
