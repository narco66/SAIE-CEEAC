import { chunkComplete, missingRanges } from './chunks.ts'
import { signRequest } from './sign.ts'
import type { Asset, DeviceEvent, Envelope, Identity, Manifest } from './types.ts'

/**
 * Persistance locale du lecteur (PLY-04) : IndexedDB pour l'identité, le manifeste actif et la file des événements
 * différés ; Cache Storage pour les ressources dont l'empreinte SHA-256 a été vérifiée.
 */

const DB_NAME = 'saie-player'
const STORE = 'kv'
const CACHE = 'saie-assets-v1'
/** Blocs d'un téléchargement en cours, conservés pour la reprise ; jamais lus comme ressource prête. */
const PARTIAL = 'saie-partial-v1'

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

/**
 * Bloc 3 (activé par la DSI) : jeton chiffré au repos par une clé AES-GCM non exportable, conservée par le navigateur.
 * Protège contre la lecture directe du jeton dans le stockage ; pas contre un code exécuté dans l'origine du lecteur.
 */
let secureStorage = false
type StoredIdentity = Identity & { tokenEnc?: { iv: number[]; data: ArrayBuffer } }

async function storageKey(): Promise<CryptoKey> {
  const existing = await kv.get<CryptoKey>('storage_key')
  if (existing) return existing
  const key = await crypto.subtle.generateKey({ name: 'AES-GCM', length: 256 }, false, ['encrypt', 'decrypt'])
  await kv.set('storage_key', key)
  return key
}

export async function getIdentity(): Promise<Identity | undefined> {
  const stored = await kv.get<StoredIdentity>('identity')
  if (!stored?.tokenEnc) return stored
  const plain = await crypto.subtle.decrypt({ name: 'AES-GCM', iv: new Uint8Array(stored.tokenEnc.iv) }, await storageKey(), stored.tokenEnc.data)
  const { tokenEnc: _encrypted, ...identity } = stored
  void _encrypted
  return { ...identity, token: new TextDecoder().decode(plain) }
}

export async function setIdentity(identity: Identity): Promise<void> {
  if (!secureStorage) {
    await kv.set('identity', identity)
    return
  }
  const iv = crypto.getRandomValues(new Uint8Array(12))
  const data = await crypto.subtle.encrypt({ name: 'AES-GCM', iv }, await storageKey(), new TextEncoder().encode(identity.token))
  await kv.set('identity', { ...identity, token: '', tokenEnc: { iv: [...iv], data } } satisfies StoredIdentity)
}

/** Active le chiffrement au repos et réenregistre l'identité courante chiffrée (sans retour arrière automatique). */
export async function enableSecureStorage(identity: Identity | null): Promise<void> {
  if (secureStorage) return
  secureStorage = true
  await kv.set('secure_storage', true)
  if (identity) await setIdentity(identity)
}

export async function loadSecureStorageFlag(): Promise<void> {
  secureStorage = Boolean(await kv.get<boolean>('secure_storage'))
}
export const clearIdentity = () => kv.del('identity')

export interface ActiveState { envelope: Envelope; manifest: Manifest; activatedAt: string }
export const getActive = () => kv.get<ActiveState>('active')
/** Bascule atomique : une seule écriture remplace le programme actif par la nouvelle version complète (PLY-03). */
export const setActive = (state: ActiveState) => kv.set('active', state)

const QUEUE_KEY = 'events'
/**
 * File d'événements différés (PLY-06). Toutes les opérations passent par une même chaîne de promesses : lecture et écriture
 * ne s'entrelacent jamais, même quand plusieurs événements sont émis sans être attendus. Au-delà de 2 000 événements, les plus
 * anciens sont écartés et leur nombre est conservé pour être signalé au serveur.
 */
const QUEUE_LIMIT = 2000
let queueChain: Promise<unknown> = Promise.resolve()
function serialized<T>(operation: () => Promise<T>): Promise<T> {
  const run = queueChain.then(operation, operation)
  queueChain = run.catch(() => undefined)
  return run
}
export function enqueue(event: Omit<DeviceEvent, 'event_id' | 'occurred_at'> & { occurred_at?: string }): Promise<void> {
  return serialized(async () => {
    const queue = (await kv.get<DeviceEvent[]>(QUEUE_KEY)) ?? []
    queue.push({ event_id: crypto.randomUUID(), occurred_at: new Date().toISOString(), ...event })
    if (queue.length > QUEUE_LIMIT) await kv.set('events_dropped', ((await kv.get<number>('events_dropped')) ?? 0) + queue.length - QUEUE_LIMIT)
    await kv.set(QUEUE_KEY, queue.slice(-QUEUE_LIMIT))
  })
}
export const peekEvents = () => serialized(async () => (await kv.get<DeviceEvent[]>(QUEUE_KEY)) ?? [])
export function ackEvents(ids: string[]): Promise<void> {
  return serialized(async () => {
    const set = new Set(ids)
    const queue = (await kv.get<DeviceEvent[]>(QUEUE_KEY)) ?? []
    await kv.set(QUEUE_KEY, queue.filter((e) => !set.has(e.event_id)))
  })
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
/**
 * Téléchargement vérifié et reprenable (PLY-04) : le fichier est demandé par blocs (en-tête Range) conservés au fur et à
 * mesure ; après une coupure, seuls les blocs manquants sont redemandés. L'empreinte SHA-256 du fichier complet est
 * vérifiée avant la mise en cache ; une empreinte fausse efface les blocs et fait repartir de zéro. Un serveur qui ignore
 * Range (réponse 200) renvoie le fichier entier en une fois.
 */
export async function downloadAsset(asset: Asset, token: string, signal?: AbortSignal): Promise<void> {
  const partial = await caches.open(PARTIAL)
  const partKey = (index: number) => new Request(`/__saie_part__/${asset.id}/${index}`)
  const stored = new Set<number>()
  for (const request of await partial.keys()) {
    const [, , id, index] = new URL(request.url).pathname.split('/')
    if (id === asset.id) stored.add(Number(index))
  }
  let whole: Blob | null = null
  for (const range of missingRanges(asset.size, stored)) {
    // §18.5 : les téléchargements de médias sont signés comme les autres requêtes du lecteur.
    const signature = await signRequest(token, 'GET', new URL(asset.href, location.origin).pathname, null)
    const response = await fetch(asset.href, { headers: { Authorization: `Bearer ${token}`, Accept: '*/*', Range: `bytes=${range.start}-${range.end}`, ...signature }, signal })
    if (response.status === 200) {
      whole = await response.blob()
      break
    }
    if (response.status !== 206) throw new Error(`Téléchargement refusé (${response.status}) pour ${asset.id}`)
    const chunk = await response.blob()
    if (!chunkComplete(range, chunk.size)) throw new Error(`Bloc incomplet pour ${asset.id} : reprise au prochain cycle`)
    await partial.put(partKey(range.index), new Response(chunk))
    stored.add(range.index)
  }
  if (!whole) {
    const parts: Blob[] = []
    for (const index of [...stored].sort((a, b) => a - b)) {
      const match = await partial.match(partKey(index))
      if (!match) throw new Error(`Bloc ${index} perdu pour ${asset.id} : reprise au prochain cycle`)
      parts.push(await match.blob())
    }
    whole = new Blob(parts)
  }
  const buffer = await whole.arrayBuffer()
  const clear = async () => { for (const index of stored) await partial.delete(partKey(index)) }
  if ((await sha256Hex(buffer)) !== asset.sha256) {
    await clear()
    throw new Error(`Empreinte invalide pour ${asset.id}`)
  }
  const cache = await caches.open(CACHE)
  await cache.put(assetKey(asset.id), new Response(buffer, { headers: { 'Content-Type': asset.type, 'X-SAIE-SHA256': asset.sha256 } }))
  await clear()
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
  // Blocs de téléchargements interrompus dont le média n'est plus attendu.
  const partial = await caches.open(PARTIAL)
  for (const request of await partial.keys()) {
    const id = new URL(request.url).pathname.split('/')[2] ?? ''
    if (!keep.has(id)) await partial.delete(request)
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
