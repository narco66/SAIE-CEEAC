/**
 * Client HTTP de l'API /api/v1 (API-01, API-02, API-05) : session Sanctum par cookies HttpOnly, jeton CSRF,
 * clé d'idempotence sur les POST sensibles, erreurs normalisées. Aucun jeton n'est conservé dans localStorage.
 */

export interface ApiErrorBody {
  code: string
  message: string
  errors?: Record<string, string[]>
  details?: Record<string, unknown>
  request_id?: string
}

export class ApiError extends Error {
  status: number
  code: string
  errors: Record<string, string[]>
  details: Record<string, unknown>
  requestId?: string

  constructor(status: number, body: Partial<ApiErrorBody>) {
    super(body.message ?? 'Erreur inattendue')
    this.status = status
    this.code = body.code ?? `HTTP_${status}`
    this.errors = body.errors ?? {}
    this.details = body.details ?? {}
    this.requestId = body.request_id
  }

  field(name: string): string | undefined {
    return this.errors[name]?.[0]
  }
}

export interface Paginated<T> {
  data: T[]
  meta: { current_page: number; per_page: number; total: number; last_page: number; [key: string]: unknown }
}

const BASE = '/api/v1'
let csrfReady: Promise<void> | null = null

function readCookie(name: string): string | null {
  const match = document.cookie.match(new RegExp('(^|; )' + name.replace(/[-.]/g, '\\$&') + '=([^;]*)'))
  return match ? decodeURIComponent(match[2]) : null
}

export function ensureCsrf(force = false): Promise<void> {
  if (!csrfReady || force) {
    csrfReady = fetch('/sanctum/csrf-cookie', { credentials: 'include' }).then(() => undefined)
  }
  return csrfReady
}

export function newIdempotencyKey(): string {
  return (crypto.randomUUID?.() ?? `${Date.now()}-${Math.random().toString(36).slice(2)}`).replace(/[^A-Za-z0-9_-]/g, '')
}

type Query = Record<string, string | number | boolean | null | undefined | string[]>

export function qs(query?: Query): string {
  if (!query) return ''
  const params = new URLSearchParams()
  for (const [key, value] of Object.entries(query)) {
    if (value === undefined || value === null || value === '' || value === false) continue
    params.set(key, Array.isArray(value) ? value.join(',') : String(value))
  }
  const s = params.toString()
  return s ? `?${s}` : ''
}

export interface RequestOptions {
  query?: Query
  body?: unknown
  idempotent?: boolean
  headers?: Record<string, string>
  signal?: AbortSignal
}

let onUnauthenticated: (() => void) | null = null
export function setUnauthenticatedHandler(handler: () => void): void {
  onUnauthenticated = handler
}

export async function request<T>(method: string, path: string, options: RequestOptions = {}): Promise<T> {
  const upper = method.toUpperCase()
  if (upper !== 'GET') await ensureCsrf()
  const headers: Record<string, string> = { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest', ...options.headers }
  const token = readCookie('XSRF-TOKEN')
  if (token) headers['X-XSRF-TOKEN'] = token
  let body: BodyInit | undefined
  if (options.body instanceof FormData) {
    body = options.body
  } else if (options.body !== undefined) {
    headers['Content-Type'] = 'application/json'
    body = JSON.stringify(options.body)
  }
  if (options.idempotent && upper === 'POST') headers['Idempotency-Key'] = newIdempotencyKey()

  let response: Response
  try {
    response = await fetch(BASE + path + qs(options.query), { method: upper, headers, body, credentials: 'include', signal: options.signal })
  } catch (error) {
    if ((error as Error).name === 'AbortError') throw error
    throw new ApiError(0, { code: 'NETWORK', message: 'Connexion au serveur impossible. Vérifiez le réseau puis réessayez.' })
  }
  if (response.status === 419 && upper !== 'GET') {
    await ensureCsrf(true)
    return request<T>(method, path, { ...options, idempotent: false, headers: options.headers })
  }
  if (response.status === 204) return undefined as T
  const contentType = response.headers.get('Content-Type') ?? ''
  const payload = contentType.includes('application/json') ? await response.json() : await response.text()
  if (!response.ok) {
    const error = new ApiError(response.status, typeof payload === 'object' ? payload : { message: String(payload).slice(0, 200) })
    if (response.status === 401 && onUnauthenticated && !path.startsWith('/auth/')) onUnauthenticated()
    throw error
  }
  return payload as T
}

export const api = {
  get: <T>(path: string, query?: Query, signal?: AbortSignal) => request<T>('GET', path, { query, signal }),
  post: <T>(path: string, body?: unknown, idempotent = true) => request<T>('POST', path, { body, idempotent }),
  patch: <T>(path: string, body?: unknown, headers?: Record<string, string>) => request<T>('PATCH', path, { body, headers }),
  put: <T>(path: string, body?: unknown) => request<T>('PUT', path, { body }),
  delete: <T>(path: string) => request<T>('DELETE', path),
  upload: <T>(path: string, form: FormData) => request<T>('POST', path, { body: form, idempotent: true }),
}

/** URL directe d'un fichier servi par l'API (téléchargement réautorisé côté serveur à chaque appel). */
export function fileUrl(path: string, query?: Query): string {
  return BASE + path + qs(query)
}
