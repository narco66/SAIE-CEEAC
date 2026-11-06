import { test } from 'node:test'
import assert from 'node:assert/strict'
import { acceptManifest, liveData, liveView, resolve, verificationKey, withinWindow } from '../src/schedule.ts'
import type { Manifest, ManifestAlert } from '../src/types.ts'

const manifest = (over: Partial<Manifest> = {}): Manifest => ({
  device_id: 'LCT-1', version: 5, content_hash: 'h5', valid_until: '2026-11-04T10:00:00Z',
  timezone: 'Africa/Libreville', operating_window: { days: [1, 2, 3, 4, 5], on: '08:00', off: '18:00', wake_before_min: 5, override_reason: null }, non_working_days: [],
  authorization: { public: '2026-11-05T09:00:00Z', interne: '2026-11-04T10:00:00Z' }, schedule: [], alerts: [], agenda: [],
  fallback: { content_version: null, valid_until: null, slide: null },
  ...over,
} as Manifest)

const at = new Date('2026-11-04T09:00:00Z')
const alert: ManifestAlert = { id: 'a', reference: 'ALT-1', severity: 'critical', title: 'Évacuation', message: 'm', translations: {}, starts_at: '2026-11-04T08:55:00Z', expires_at: '2026-11-04T09:25:00Z' }

test('une alerte du dernier manifeste vérifié s’affiche avant la fin du téléchargement des médias (PUB-06)', () => {
  const active = manifest()
  const incoming = manifest({ version: 6, alerts: [alert] })
  const view = liveView(active, liveData(incoming), at)
  assert.equal(resolve(view, at).mode, 'alert')
  assert.equal(view.schedule, active.schedule, 'le programme reste celui du manifeste actif (bascule atomique)')
})

test('hors ligne, agenda, panneau et bienvenues cessent d’être affichés à l’expiration de l’autorisation (PLY-05)', () => {
  const active = manifest({
    agenda: [{ room: 'Kivu', floor: 'RDC', label: 'Comité', start: '2026-11-04T09:00:00Z', end: '2026-11-04T10:00:00Z' }],
    orientation: { floors: [], welcomes: [{ lang: 'fr', title: 'Bienvenue', body: '', delegation: null }] },
  })
  assert.equal(liveView(active, null, at).agenda.length, 1)
  const later = new Date('2026-11-05T09:30:00Z')
  assert.equal(liveView(active, null, later).agenda.length, 0)
  assert.equal(liveView(active, null, later).orientation?.welcomes.length, 0)
})

test('manifeste refusé s’il est expiré, plus ancien ou destiné à un autre lecteur (PLY-03)', () => {
  const active = manifest()
  assert.equal(acceptManifest(manifest({ version: 6, content_hash: 'h6' }), 'LCT-1', active, at), 'accept')
  assert.equal(acceptManifest(manifest(), 'LCT-1', active, at), 'same')
  assert.match(acceptManifest(manifest({ version: 4, content_hash: 'h4' }), 'LCT-1', active, at), /plus ancien/)
  assert.match(acceptManifest(manifest({ version: 7, content_hash: 'h7', valid_until: '2026-11-04T08:00:00Z' }), 'LCT-1', active, at), /expiré/)
  assert.match(acceptManifest(manifest({ version: 7, content_hash: 'h7' }), 'LCT-2', active, at), /autre lecteur/)
})

test('dérogation « jours non ouvrés » d’un écran : allumé un jour férié (PLY-08)', () => {
  const holiday = { non_working_days: ['2026-11-04'] }
  assert.equal(withinWindow(manifest(holiday), at), false)
  assert.equal(withinWindow(manifest({ ...holiday, operating_window: { days: [1, 2, 3, 4, 5], on: '08:00', off: '18:00', wake_before_min: 5, override_reason: 'Sommet', ignore_holidays: true } }), at), true)
})

test('rotation de clé : la clé suivante annoncée par un manifeste vérifié est reconnue, une clé inconnue jamais (PLY-03)', () => {
  const identity = { keyId: 'mk-2026-11', publicKey: 'cle-courante', trustedKeys: { 'mk-2027-01': 'cle-suivante' } }
  assert.equal(verificationKey(identity, 'mk-2026-11'), 'cle-courante')
  assert.equal(verificationKey(identity, 'mk-2027-01'), 'cle-suivante')
  assert.equal(verificationKey(identity, 'mk-pirate'), null)
})
