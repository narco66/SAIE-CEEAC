import { mkdirSync, readFileSync, writeFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { expect, test, type APIRequestContext } from '@playwright/test'
import { apiAs } from './helpers'

/**
 * Exécution du jeu d'essai des configurations (V39) contre l'application démarrée.
 *
 * Le jeu est produit par `node e2e/tools/generate-configuration-cases.mjs` à partir des règles du serveur. Pour chaque
 * paramètre : chaque valeur est envoyée, la réponse attendue (acceptée ou refusée) est contrôlée, la valeur enregistrée est
 * relue, l'effet observable est vérifié quand il existe, puis la valeur d'origine est restaurée (même en cas d'échec).
 * Toutes les modifications passent par l'API avec un motif : elles figurent au journal d'audit.
 */

interface Case {
  id: string; key: string; tab?: string; type?: string; technical?: boolean; value: unknown; expect: 'accepted' | 'rejected' | 'forbidden'
  why: string; constraint?: 'supervision_order'; effect?: 'referentials_default_slide' | 'referentials_directory' | 'enrollment_allowed' | 'enrollment_refused'
  login?: string | null; reason?: string
}
interface Dataset { reason: string; settings: number; total: number; cases: Case[]; access: Case[] }
interface Setting { key: string; type: string; technical: boolean; value: unknown; editable: boolean }
type Api = Awaited<ReturnType<typeof apiAs>>
interface Outcome { id: string; key: string; why: string; value: unknown; expected: string; status: number; code: string | null; stored: unknown; effect: string | null; ok: boolean; detail: string }

const dataset = JSON.parse(readFileSync(resolve(process.cwd(), 'e2e/data/configuration-cases.json'), 'utf8')) as Dataset
const ORIGIN = process.env.E2E_BASE_URL ?? 'http://localhost:5173'
const outcomes: Outcome[] = []
const keys = [...new Set(dataset.cases.map((c) => c.key))]

let admin: Api
let functional: Api
let agent: Api
let originals: Map<string, Setting>
let currentUserId = ''

/** Les limites d'appels (300 par minute et par compte) sont respectées : un refus 429 est rejoué après une pause. */
const call = async (api: Api, method: string, path: string, data?: unknown) => {
  for (let attempt = 0; attempt < 4; attempt++) {
    const response = await api(method, path, data)
    if (response.status !== 429) return response
    await new Promise((r) => setTimeout(r, 30_000))
  }
  return api(method, path, data)
}

/**
 * Comparaison de valeurs : un texte facultatif effacé est enregistré vide (null et « » équivalents), et l'ordre des champs
 * d'un objet n'a pas de sens (PostgreSQL réordonne les clés JSON).
 */
const canonical = (v: unknown): unknown => (v === '' || v === undefined ? null : Array.isArray(v) ? v.map(canonical)
  : v && typeof v === 'object' ? Object.fromEntries(Object.keys(v).sort().map((k) => [k, canonical((v as Record<string, unknown>)[k])])) : v)
const same = (a: unknown, b: unknown) => JSON.stringify(canonical(a)) === JSON.stringify(canonical(b))
const resolveValue = (value: unknown) => (value === '$CURRENT_USER' ? currentUserId : value)

async function settingsOf(api: Api): Promise<Map<string, Setting>> {
  const response = await call(api, 'GET', '/settings')
  expect(response.status).toBe(200)
  return new Map((response.body.data.settings as Setting[]).map((s) => [s.key, s]))
}

/** Compte habilité pour la clé : l'administrateur technique, sinon l'administrateur fonctionnel. */
const actorFor = (key: string) => (originals.get(key)?.editable ? admin : functional)

async function pairingStatus(request: APIRequestContext, n: string): Promise<number> {
  const r = await request.post(`${ORIGIN}/api/v1/device/pairing-codes`, { headers: { Accept: 'application/json' }, data: { hardware_id: `essai-configuration-${n}`, info: { platform: 'Jeu d’essai des configurations' } } })
  return r.status()
}

async function checkEffect(c: Case, value: unknown, request: APIRequestContext): Promise<string> {
  switch (c.effect) {
    case 'referentials_default_slide': {
      const d = (await call(admin, 'GET', '/referentials')).body.data
      return d.defaults.slide_duration_s === value ? '' : `référentiels : durée par défaut ${d.defaults.slide_duration_s} au lieu de ${value}`
    }
    case 'referentials_directory': {
      const users = (await call(agent, 'GET', '/referentials')).body.data.users as unknown[]
      return value === true ? (users.length === 0 ? '' : `annuaire encore visible par un agent (${users.length} comptes)`) : (users.length > 0 ? '' : 'annuaire masqué alors que la restriction est levée')
    }
    case 'enrollment_allowed': {
      const status = await pairingStatus(request, c.id.replace(/[^a-z0-9]/gi, '-'))
      return status === 201 ? '' : `enrôlement refusé (HTTP ${status}) alors qu’il devrait être permis`
    }
    case 'enrollment_refused': {
      const status = await pairingStatus(request, c.id.replace(/[^a-z0-9]/gi, '-'))
      return status === 403 ? '' : `enrôlement accepté (HTTP ${status}) alors que le réseau du poste est exclu`
    }
    default:
      return ''
  }
}

test.describe.serial('Jeu d’essai des configurations', () => {
  test.beforeAll(async ({ playwright }) => {
    admin = await apiAs(await playwright.request.newContext(), 'alain.mbeng')
    functional = await apiAs(await playwright.request.newContext(), 'clarisse.ondo')
    agent = await apiAs(await playwright.request.newContext(), 'loic.bouanga')
    originals = await settingsOf(admin)
    currentUserId = (await call(admin, 'GET', '/me')).body.data.id
    expect(originals.size, 'le jeu couvre tous les paramètres exposés par le serveur').toBe(dataset.settings)
  })

  test.afterAll(() => {
    const dir = resolve(process.cwd(), 'test-results')
    mkdirSync(dir, { recursive: true })
    const failed = outcomes.filter((o) => !o.ok)
    writeFileSync(resolve(dir, 'configuration-report.json'), JSON.stringify({ executed_at: new Date().toISOString(), total: outcomes.length, passed: outcomes.length - failed.length, failed: failed.length, outcomes }, null, 2))
  })

  for (const key of keys) {
    test(`Paramètre ${key}`, async ({ request }) => {
      const original = originals.get(key)
      expect(original, `paramètre ${key} exposé par GET /settings`).toBeDefined()
      const api = actorFor(key)
      try {
        for (const c of dataset.cases.filter((x) => x.key === key)) {
          const value = resolveValue(c.value)
          // Supervision : le délai « hors ligne » doit rester supérieur au délai « suspect » (valeur de l'autre clé en place).
          let expected = c.expect
          if (c.constraint === 'supervision_order' && expected === 'accepted') {
            const suspect = key === 'supervision.suspect_after_s' ? Number(value) : Number(originals.get('supervision.suspect_after_s')?.value)
            const offline = key === 'supervision.offline_after_s' ? Number(value) : Number(originals.get('supervision.offline_after_s')?.value)
            if (offline <= suspect) expected = 'rejected'
          }
          const response = await call(api, 'PATCH', '/settings', { key, value, reason: dataset.reason })
          const code = response.body?.code ?? null
          let stored: unknown = null
          let detail = ''
          let ok = expected === 'accepted' ? response.status === 200 : response.status === 422
          if (!ok) detail = `HTTP ${response.status}${code ? ` ${code}` : ''} : ${response.body?.message ?? ''}`
          if (ok && expected === 'accepted') {
            stored = (response.body.data.settings as Setting[]).find((s) => s.key === key)?.value
            if (!same(stored, value)) { ok = false; detail = `valeur relue ${JSON.stringify(stored)} au lieu de ${JSON.stringify(value)}` }
            const effect = ok ? await checkEffect(c, value, request) : ''
            if (effect) { ok = false; detail = effect }
          }
          // Chaque écart est consigné ; le bilan final les présente tous (un écart n'interrompt pas les paramètres suivants).
          outcomes.push({ id: c.id, key, why: c.why, value, expected, status: response.status, code, stored, effect: c.effect ?? null, ok, detail })
        }
      } finally {
        // Restauration systématique de la valeur d'origine.
        const restore = await call(api, 'PATCH', '/settings', { key, value: original!.value, reason: 'Restauration après le jeu d’essai des configurations' })
        expect(restore.status, `restauration de ${key}`).toBe(200)
        const back = (restore.body.data.settings as Setting[]).find((s) => s.key === key)?.value
        expect(same(back, original!.value), `${key} restauré à sa valeur d’origine`).toBe(true)
      }
    })
  }

  test('Contrôles d’accès aux paramètres', async () => {
    for (const c of dataset.access) {
      const api = c.login === 'loic.bouanga' ? agent : admin
      const response = await call(api, 'PATCH', '/settings', { key: c.key, value: c.value, reason: c.reason ?? dataset.reason })
      const ok = c.expect === 'forbidden' ? response.status === 403 : response.status === 422
      outcomes.push({ id: c.id, key: c.key, why: c.why, value: c.value, expected: c.expect, status: response.status, code: response.body?.code ?? null, stored: null, effect: null, ok, detail: ok ? '' : `HTTP ${response.status}` })
    }
    // Aucune trace durable : le paramètre visé par l'agent n'a pas changé.
    expect((await settingsOf(admin)).get('display.default_slide_s')?.value).toEqual(originals.get('display.default_slide_s')?.value)
  })

  test('Bilan : tous les cas conformes et paramètres restaurés', async () => {
    const after = await settingsOf(admin)
    const notRestored = [...originals.values()].filter((s) => !same(after.get(s.key)?.value, s.value)).map((s) => s.key)
    expect(notRestored, 'paramètres non revenus à leur valeur d’origine').toEqual([])
    expect(outcomes.length, 'tous les cas du jeu ont été exécutés').toBe(dataset.total)
    const failed = outcomes.filter((o) => !o.ok).map((o) => `${o.id} — ${o.why} : ${o.detail}`)
    expect(failed, `${failed.length} cas non conforme(s) sur ${outcomes.length}`).toEqual([])
  })
})
