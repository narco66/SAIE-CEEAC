import { test } from 'node:test'
import assert from 'node:assert/strict'
import { resolve, withinWindow, fallbackValid } from '../src/schedule.ts'
import type { Manifest, ScheduleEntry } from '../src/types.ts'

const slide = { title: 't', body: null, category: null, kind: 'standard', lang: 'fr', media: [], subtitles: null, qr: null, layout: null, delegation: null, duration_s: 12 }
const entry = (over: Partial<ScheduleEntry>): ScheduleEntry => ({
  entry_id: 'e', campaign_id: 'c', campaign_ref: 'CMP', content_id: 'x', content_version: 'v', lang: 'fr', auth_class: 'public', priority: 50, position: 1, rotation_rank: 1,
  from: '2026-11-02T07:00:00Z', to: '2026-11-30T17:00:00Z', days: [], time_from: null, time_to: null, duration_s: 12, slide, ...over,
})
const manifest = (over: Partial<Manifest> = {}): Manifest => ({
  timezone: 'Africa/Libreville', operating_window: { days: [1, 2, 3, 4, 5], on: '08:00', off: '18:00', wake_before_min: 5, override_reason: null }, non_working_days: [],
  authorization: { public: '2026-12-31T00:00:00Z', interne: '2026-12-31T00:00:00Z' }, schedule: [], alerts: [], fallback: { content_version: null, valid_until: null, slide: null },
  ...over,
} as Manifest)

// Mercredi 4 novembre 2026, 10:00 à Libreville (UTC+1) = 09:00 UTC
const at = new Date('2026-11-04T09:00:00Z')

test('hors plage : écran éteint le soir et le week-end', () => {
  assert.equal(resolve(manifest(), new Date('2026-11-04T18:30:00Z')).mode, 'off')
  assert.equal(resolve(manifest(), new Date('2026-11-07T09:00:00Z')).mode, 'off')
  assert.equal(withinWindow(manifest(), new Date('2026-11-04T06:56:00Z'), 5), true, 'réveil 5 min avant l’ouverture')
})

test('jour non ouvré du référentiel : éteint', () => {
  assert.equal(resolve(manifest({ non_working_days: ['2026-11-04'] }), at).mode, 'off')
})

test('alerte active prioritaire sur les programmes, critique avant information', () => {
  const r = resolve(manifest({
    schedule: [entry({ priority: 90 })],
    alerts: [
      { id: 'b', reference: 'ALT-2', severity: 'info', title: 'i', message: 'm', translations: {}, starts_at: '2026-11-04T08:50:00Z', expires_at: '2026-11-04T09:20:00Z' },
      { id: 'a', reference: 'ALT-1', severity: 'critical', title: 'c', message: 'm', translations: {}, starts_at: '2026-11-04T08:55:00Z', expires_at: '2026-11-04T09:25:00Z' },
    ],
  }), at)
  assert.equal(r.mode, 'alert')
  assert.equal(r.mode === 'alert' && r.alerts[0].id, 'a')
})

test('priorité décroissante puis date de début puis identifiant, rotation à priorité égale', () => {
  const r = resolve(manifest({ schedule: [
    entry({ entry_id: 'low', priority: 40 }),
    entry({ entry_id: 'b-en', campaign_id: 'b', priority: 60, rotation_rank: 2, lang: 'en' }),
    entry({ entry_id: 'b-fr', campaign_id: 'b', priority: 60, rotation_rank: 1 }),
    entry({ entry_id: 'a-fr', campaign_id: 'a', priority: 60, from: '2026-11-03T07:00:00Z' }),
  ] }), at)
  assert.equal(r.mode, 'program')
  assert.deepEqual(r.mode === 'program' && r.playlist.map((e) => e.entry_id), ['b-fr', 'b-en', 'a-fr'])
})

test('campagne expirée exclue, créneau horaire et jours respectés', () => {
  const r = resolve(manifest({ schedule: [
    entry({ entry_id: 'expired', to: '2026-11-04T08:00:00Z' }),
    entry({ entry_id: 'afternoon', time_from: '14:00' }),
    entry({ entry_id: 'monday', days: [1] }),
  ] }), at)
  assert.equal(r.mode, 'fallback')
})

test('autorisation hors ligne expirée : contenu interne retiré, public conservé (PLY-05)', () => {
  const m = manifest({ authorization: { public: '2026-11-05T00:00:00Z', interne: '2026-11-04T08:30:00Z' }, schedule: [entry({ entry_id: 'int', auth_class: 'interne', priority: 80 }), entry({ entry_id: 'pub' })] })
  const r = resolve(m, at)
  assert.deepEqual(r.mode === 'program' && r.playlist.map((e) => e.entry_id), ['pub'])
})

test('ressource manquante : l’entrée n’est pas lancée (jamais de programme incomplet)', () => {
  const r = resolve(manifest({ schedule: [entry({ slide: { ...slide, media: [{ asset: 'missing', kind: 'image', pages: null, page_duration_s: null, role: 'main' }] } })] }), at, () => false)
  assert.equal(r.mode, 'fallback')
})

test('repli expiré remplacé par le repli neutre', () => {
  assert.equal(fallbackValid(manifest({ fallback: { content_version: 'f', valid_until: '2026-11-01T00:00:00Z', slide } }), at), false)
  assert.equal(fallbackValid(manifest({ fallback: { content_version: 'f', valid_until: '2026-12-01T00:00:00Z', slide } }), at), true)
})
