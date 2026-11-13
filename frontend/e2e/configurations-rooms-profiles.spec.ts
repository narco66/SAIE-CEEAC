import { mkdirSync, readFileSync, writeFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { expect, test } from '@playwright/test'
import { apiAs } from './helpers'

/**
 * Exécution du jeu d'essai des profils de configuration des écrans et des règles des salles, contre l'application démarrée.
 *
 * Jeu produit par `node e2e/tools/generate-configuration-cases.mjs` (fichier e2e/data/room-profile-cases.json).
 * Les essais portent sur un profil et une salle dédiés (code ESSAI-CONFIG), réutilisés d'un passage à l'autre et mis hors
 * service à la fin : aucun écran ni aucune réservation existants ne sont touchés. Les effets des règles de salle sont
 * vérifiés par la vérification de créneau (POST /reservations/check), qui ne crée aucune réservation.
 */

interface ProfileCase { id: string; key: string; value: unknown; expect: 'accepted' | 'rejected'; why: string }
interface FieldCase { id: string; field: string; value: unknown; expect: 'accepted' | 'rejected'; why: string }
interface RoomCase {
  id: string; field: string; payload: Record<string, unknown>; expect: 'accepted' | 'rejected'; why: string
  constraint?: 'max_gte_min' | 'min_lte_max'; effect?: 'room_capacity' | 'room_min_duration' | 'room_max_duration' | 'room_lead_days' | 'room_hours' | 'room_status' | 'room_approval'
}
interface Dataset { reason: string; total: number; profile: { cases: ProfileCase[]; fields: FieldCase[] }; room: { cases: RoomCase[] } }
type Api = Awaited<ReturnType<typeof apiAs>>
interface Outcome { id: string; why: string; payload: unknown; expected: string; status: number; code: string | null; ok: boolean; detail: string }

const dataset = JSON.parse(readFileSync(resolve(process.cwd(), 'e2e/data/room-profile-cases.json'), 'utf8')) as Dataset
const CODE = 'ESSAI-CONFIG'
/** État de référence de la salle d'essai, rétabli après chaque règle essayée. */
const BASELINE = {
  capacity: 20, min_minutes: 30, max_minutes: 600, lead_days: 60, prep_minutes: 0, cleanup_minutes: 0, open_from: '07:30', open_to: '19:00',
  pending_hold_hours: 24, auto_release_enabled: false, status: 'active', approval_mode: 'auto', manager_id: null,
}
const outcomes: Outcome[] = []

let technical: Api
let functional: Api
let agent: Api
let functionalId = ''
let profileId = ''
let roomId = ''

const call = async (api: Api, method: string, path: string, data?: unknown) => {
  for (let attempt = 0; attempt < 4; attempt++) {
    const response = await api(method, path, data)
    if (response.status !== 429) return response
    await new Promise((r) => setTimeout(r, 30_000))
  }
  return api(method, path, data)
}
const canonical = (v: unknown): unknown => (v === '' || v === undefined ? null : Array.isArray(v) ? v.map(canonical)
  : v && typeof v === 'object' ? Object.fromEntries(Object.keys(v).sort().map((k) => [k, canonical((v as Record<string, unknown>)[k])])) : v)
const same = (a: unknown, b: unknown) => JSON.stringify(canonical(a)) === JSON.stringify(canonical(b))
const resolveValue = (v: unknown) => (v === '$CURRENT_USER' ? functionalId : v)
const record = (o: Outcome) => outcomes.push(o)

/* --------------------------------------------- Vérification de créneau --------------------------------------------- */

/** Date locale (Libreville) dans `days` jours, au format AAAA-MM-JJ. */
const localDay = (days: number) => new Intl.DateTimeFormat('en-CA', { timeZone: 'Africa/Libreville' }).format(new Date(Date.now() + days * 86_400_000))
/** Instant ISO d'une heure locale de Libreville (UTC+1, sans heure d'été). */
const at = (day: string, hhmm: string, plusMinutes = 0) => new Date(new Date(`${day}T${hhmm}:00+01:00`).getTime() + plusMinutes * 60_000).toISOString()
const toMinutes = (hhmm: string) => Number(hhmm.slice(0, 2)) * 60 + Number(hhmm.slice(3, 5))
const fromMinutes = (m: number) => `${String(Math.floor(m / 60)).padStart(2, '0')}:${String(m % 60).padStart(2, '0')}`

async function check(start: string, end: string, participants = 4): Promise<{ status: number; code: string | null; body: any }> {
  const r = await call(agent, 'POST', '/reservations/check', { room_id: roomId, start_at: start, end_at: end, subject: 'Vérification de règle', participant_count: participants, confidentiality: false })
  return { status: r.status, code: r.body?.code ?? null, body: r.body }
}

/** Effet observable d'une règle de salle enregistrée ; renvoie le défaut constaté, ou « » si l'effet est conforme. */
async function roomEffect(c: RoomCase, room: Record<string, any>): Promise<string> {
  const day = localDay(3)
  switch (c.effect) {
    case 'room_capacity': {
      const fits = await check(at(day, '10:00'), at(day, '11:00'), room.capacity)
      const over = await check(at(day, '10:00'), at(day, '11:00'), room.capacity + 1)
      if (fits.body?.data?.capacity_ok !== true) return `capacité ${room.capacity} : ${room.capacity} participants jugés en surnombre`
      return over.body?.data?.capacity_ok === false ? '' : `capacité ${room.capacity} : ${room.capacity + 1} participants acceptés`
    }
    case 'room_min_duration': {
      const n = room.min_minutes as number
      const short = await check(at(day, '08:00'), at(day, '08:00', n - 1))
      if (short.code !== 'DURATION_OUT_OF_RANGE') return `durée minimale ${n} min : un créneau de ${n - 1} min n’est pas refusé (${short.status} ${short.code ?? ''})`
      const fits = await check(at(day, '08:00'), at(day, '08:00', n))
      return fits.status === 200 ? '' : `durée minimale ${n} min : un créneau de ${n} min est refusé (${fits.code})`
    }
    case 'room_max_duration': {
      const m = room.max_minutes as number
      const long = await check(at(day, '07:30'), at(day, '07:30', m + 1))
      return long.code === 'DURATION_OUT_OF_RANGE' ? '' : `durée maximale ${m} min : un créneau de ${m + 1} min n’est pas refusé (${long.status} ${long.code ?? ''})`
    }
    case 'room_lead_days': {
      const l = room.lead_days as number
      const far = await check(at(localDay(l + 1), '10:00'), at(localDay(l + 1), '11:00'))
      if (far.code !== 'TOO_FAR') return `délai ${l} j : un créneau à ${l + 1} jours n’est pas refusé (${far.status} ${far.code ?? ''})`
      if (l >= 2) {
        const near = await check(at(localDay(1), '10:00'), at(localDay(1), '11:00'))
        if (near.status !== 200) return `délai ${l} j : un créneau le lendemain est refusé (${near.code})`
      }
      return ''
    }
    case 'room_hours': {
      const from = toMinutes(room.open_from)
      const to = toMinutes(room.open_to)
      const inside = await check(at(day, fromMinutes(from)), at(day, fromMinutes(from), 30))
      if (inside.status !== 200) return `horaires ${room.open_from}–${room.open_to} : un créneau à l’ouverture est refusé (${inside.code})`
      if (from >= 30) {
        const early = await check(at(day, fromMinutes(from - 30)), at(day, fromMinutes(from)))
        if (early.code !== 'OUTSIDE_OPENING_HOURS') return `horaires ${room.open_from}–${room.open_to} : un créneau avant l’ouverture n’est pas refusé (${early.status})`
      }
      const late = await check(at(day, fromMinutes(to - 15)), at(day, fromMinutes(to - 15), 30))
      return late.code === 'OUTSIDE_OPENING_HOURS' ? '' : `horaires ${room.open_from}–${room.open_to} : un créneau débordant la fermeture n’est pas refusé (${late.status})`
    }
    case 'room_status': {
      const r = await check(at(day, '10:00'), at(day, '11:00'))
      if (room.status === 'inactive') return r.code === 'ROOM_INACTIVE' ? '' : `salle hors service encore réservable (${r.status})`
      return r.status === 200 ? '' : `salle réservable refusée (${r.code})`
    }
    case 'room_approval': {
      const r = await check(at(day, '10:00'), at(day, '11:00'))
      return r.body?.data?.approval_mode === room.approval_mode ? '' : `mode annoncé ${r.body?.data?.approval_mode} au lieu de ${room.approval_mode}`
    }
    default:
      return ''
  }
}

async function patchRoom(body: Record<string, unknown>) {
  const version = (await call(functional, 'GET', `/rooms/${roomId}`)).body.data.version
  return call(functional, 'PATCH', `/rooms/${roomId}`, { ...body, expected_version: version })
}

/* ------------------------------------------------------ Parcours ----------------------------------------------------- */

test.describe.serial('Jeu d’essai des profils d’écran et des règles de salle', () => {
  test.beforeAll(async ({ playwright }) => {
    technical = await apiAs(await playwright.request.newContext(), 'alain.mbeng')
    functional = await apiAs(await playwright.request.newContext(), 'clarisse.ondo')
    agent = await apiAs(await playwright.request.newContext(), 'loic.bouanga')
    functionalId = (await call(functional, 'GET', '/me')).body.data.id

    // Profil d'essai dédié : créé au premier passage, réactivé ensuite.
    const profiles = (await call(technical, 'GET', '/screen-profiles')).body.data as { id: string; code: string; version: number }[]
    const existing = profiles.find((p) => p.code === CODE)
    if (existing) {
      expect((await call(technical, 'PATCH', `/screen-profiles/${existing.id}`, { is_active: true, settings: {}, description: null })).status).toBe(200)
      profileId = existing.id
    } else {
      const created = await call(technical, 'POST', '/screen-profiles', { code: CODE, name: 'Profil du jeu d’essai des configurations', description: null, settings: { volume_max: 50 } })
      expect(created.status, JSON.stringify(created.body)).toBe(201)
      profileId = created.body.data.id
    }

    // Salle d'essai dédiée : créée au premier passage (administrateur fonctionnel), remise à l'état de référence ensuite.
    const found = ((await call(functional, 'GET', `/rooms?include_inactive=1&q=${CODE}&per_page=5`)).body.data as { id: string; code: string }[]).find((r) => r.code === CODE)
    if (found) {
      roomId = found.id
    } else {
      const floor = ((await call(functional, 'GET', '/referentials')).body.data.floors as { id: string; is_active?: boolean }[]).find((f) => f.is_active !== false)
      const created = await call(functional, 'POST', '/rooms', { code: CODE, name: 'Salle du jeu d’essai des configurations', floor_id: floor!.id, capacity: 20, approval_mode: 'auto' })
      expect(created.status, JSON.stringify(created.body)).toBe(201)
      roomId = created.body.data.id
    }
    const reset = await patchRoom({ ...BASELINE, confirm_affected: true })
    expect(reset.status, JSON.stringify(reset.body)).toBe(200)
  })

  test.afterAll(async () => {
    // Profil et salle d'essai mis hors service : ils ne sont proposés à personne entre deux passages.
    if (profileId) await call(technical, 'PATCH', `/screen-profiles/${profileId}`, { settings: {}, description: null, is_active: false })
    if (roomId) await patchRoom({ ...BASELINE, status: 'inactive', confirm_affected: true })
    const dir = resolve(process.cwd(), 'test-results')
    mkdirSync(dir, { recursive: true })
    const failed = outcomes.filter((o) => !o.ok)
    writeFileSync(resolve(dir, 'room-profile-report.json'), JSON.stringify({ executed_at: new Date().toISOString(), total: outcomes.length, passed: outcomes.length - failed.length, failed: failed.length, outcomes }, null, 2))
  })

  test('Profils de configuration : chaque réglage', async () => {
    for (const c of dataset.profile.cases) {
      const r = await call(technical, 'PATCH', `/screen-profiles/${profileId}`, { settings: { [c.key]: c.value }, reason: dataset.reason })
      let ok = c.expect === 'accepted' ? r.status === 200 : r.status === 422
      let detail = ok ? '' : `HTTP ${r.status} ${r.body?.code ?? ''} ${r.body?.message ?? ''}`
      if (ok && c.expect === 'accepted') {
        const stored = (r.body.data.settings ?? {}) as Record<string, unknown>
        const want = c.value === null ? {} : { [c.key]: c.value }
        if (!same(stored, want)) { ok = false; detail = `réglages relus ${JSON.stringify(stored)} au lieu de ${JSON.stringify(want)}` }
      }
      record({ id: c.id, why: c.why, payload: { [c.key]: c.value }, expected: c.expect, status: r.status, code: r.body?.code ?? null, ok, detail })
    }
  })

  test('Profils de configuration : identité du profil et accès', async () => {
    for (const c of dataset.profile.fields) {
      const r = await call(technical, 'PATCH', `/screen-profiles/${profileId}`, { [c.field]: c.value })
      const ok = c.expect === 'accepted' ? r.status === 200 : r.status === 422
      record({ id: c.id, why: c.why, payload: { [c.field]: String(c.value).length > 40 ? `${String(c.value).slice(0, 12)}… (${String(c.value).length})` : c.value }, expected: c.expect, status: r.status, code: r.body?.code ?? null, ok, detail: ok ? '' : `HTTP ${r.status}` })
    }
    const denied = await call(agent, 'PATCH', `/screen-profiles/${profileId}`, { settings: { volume_max: 10 } })
    record({ id: 'profil.acces#agent', why: 'Un agent ne modifie pas un profil', payload: { volume_max: 10 }, expected: 'forbidden', status: denied.status, code: denied.body?.code ?? null, ok: denied.status === 403, detail: denied.status === 403 ? '' : `HTTP ${denied.status}` })
  })

  test('Salles : chaque règle et son effet sur un créneau', async () => {
    for (const c of dataset.room.cases) {
      const payload = Object.fromEntries(Object.entries(c.payload).map(([k, v]) => [k, resolveValue(v)]))
      let expected = c.expect
      // Contraintes croisées avec l'état de référence : durée maximale ≥ durée minimale.
      if (c.constraint === 'max_gte_min' && expected === 'accepted' && Number(payload.max_minutes) < BASELINE.min_minutes) expected = 'rejected'
      if (c.constraint === 'min_lte_max' && expected === 'accepted' && Number(payload.min_minutes) > BASELINE.max_minutes) expected = 'rejected'
      const r = await patchRoom({ ...payload, confirm_affected: true })
      let ok = expected === 'accepted' ? r.status === 200 : r.status === 422
      let detail = ok ? '' : `HTTP ${r.status} ${r.body?.code ?? ''} ${r.body?.message ?? ''}`
      if (ok && expected === 'accepted') {
        const room = (await call(functional, 'GET', `/rooms/${roomId}`)).body.data
        const read = (k: string) => (k === 'manager_id' ? room.manager?.id ?? null : room[k])
        const mismatch = Object.entries(payload).filter(([k, v]) => !same(read(k), v))
        if (mismatch.length) { ok = false; detail = `valeurs relues différentes : ${mismatch.map(([k]) => `${k}=${JSON.stringify(room[k])}`).join(', ')}` }
        const effect = ok && c.effect ? await roomEffect(c, room) : ''
        if (effect) { ok = false; detail = effect }
      }
      record({ id: c.id, why: c.why, payload, expected, status: r.status, code: r.body?.code ?? null, ok, detail })
      // Retour à l'état de référence avant la règle suivante.
      const reset = await patchRoom({ ...BASELINE, confirm_affected: true })
      expect(reset.status, `remise à l’état de référence après ${c.id}`).toBe(200)
    }
    const denied = await call(agent, 'PATCH', `/rooms/${roomId}`, { capacity: 5, expected_version: (await call(functional, 'GET', `/rooms/${roomId}`)).body.data.version })
    record({ id: 'salle.acces#agent', why: 'Un agent ne modifie pas une salle', payload: { capacity: 5 }, expected: 'forbidden', status: denied.status, code: denied.body?.code ?? null, ok: denied.status === 403, detail: denied.status === 403 ? '' : `HTTP ${denied.status}` })
    await patchRoom({ ...BASELINE, confirm_affected: true })
  })

  test('Bilan : tous les cas conformes', () => {
    expect(outcomes.length, 'tous les cas du jeu ont été exécutés').toBe(dataset.total + 2)
    const failed = outcomes.filter((o) => !o.ok).map((o) => `${o.id} — ${o.why} : ${o.detail}`)
    expect(failed, `${failed.length} cas non conforme(s) sur ${outcomes.length}`).toEqual([])
  })
})
