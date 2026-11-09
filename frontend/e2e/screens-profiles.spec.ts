import { expect, test } from '@playwright/test'
import { apiAs, login, retireScreen } from './helpers'

const PLAYER = process.env.E2E_PLAYER_URL ?? 'http://localhost:5174'

/**
 * Module « Gestion des écrans », §7 : un profil de configuration affecté à un écran atteint le lecteur réel, qui confirme
 * l'empreinte appliquée et signale les paramètres qu'il ne prend pas en charge (luminosité).
 */
test('profil de configuration : transmis, confirmé par le lecteur, paramètres non pris en charge signalés', async ({ browser, request, page }) => {
  test.setTimeout(300_000)
  const api = await apiAs(request, 'alain.mbeng')
  const suffix = Date.now().toString().slice(-6)
  const profile = await api('POST', '/screen-profiles', { code: `E2E-${suffix}`, name: `Profil E2E ${suffix}`, settings: { sync_interval_s: 20, heartbeat_interval_s: 15, languages: ['fr', 'pt'], brightness: 60 } })
  expect(profile.status).toBe(201)
  const refs = (await api('GET', '/referentials')).body.data
  const created = await api('POST', '/screens', {
    code: `ECR-E2E-PRF-${suffix}`, name: 'Écran de test des profils (E2E)', kind: 'display', location_id: refs.locations[0].id, orientation: 'landscape', resolution: '1920x1080',
    operating_window: { days: [1, 2, 3, 4, 5, 6, 7], on: '00:00', off: '23:59' }, window_override_reason: 'Écran de test automatisé, actif en continu', profile_id: profile.body.data.id,
  })
  expect(created.status).toBe(201)
  const screen = created.body.data

  const context = await browser.newContext({ viewport: { width: 1280, height: 720 } })
  const player = await context.newPage()
  await player.goto(PLAYER)
  const codeBox = player.locator('.code')
  await expect(codeBox).toBeVisible({ timeout: 30_000 })
  const paired = await api('POST', '/devices/pair', { code: (await codeBox.getAttribute('aria-label'))!.replace(/.*\s/, ''), screen_id: screen.id })
  expect(paired.status).toBe(201)

  await expect.poll(async () => (await api('GET', `/screens/${screen.id}`)).body.data.configuration.sync, { timeout: 150_000, intervals: [3000] }).toBe('applied')
  const conf = (await api('GET', `/screens/${screen.id}`)).body.data.configuration
  expect(conf.unsupported).toContain('brightness')
  expect(conf.applied).toEqual(expect.arrayContaining(['sync_interval_s', 'heartbeat_interval_s', 'languages']))

  await page.setViewportSize({ width: 1440, height: 1000 })
  await login(page, 'alain.mbeng')
  await page.goto(`/affichage/ecrans/${screen.id}`)
  await page.getByRole('tab', { name: 'Fonctionnement' }).click()
  await expect(page.getByText('Appliquée et confirmée par le lecteur')).toBeVisible()
  await expect(page.getByText('non pris en charge par ce lecteur')).toBeVisible()
  await page.screenshot({ path: '../docs/AUDIT-GESTION-ECRANS/captures/configuration-profil.png', fullPage: true })
  await page.goto('/affichage/profils')
  await expect(page.getByRole('cell', { name: new RegExp(`Profil E2E ${suffix}`) })).toBeVisible()

  const current = (await api('GET', `/screens/${screen.id}`)).body.data
  expect((await api('PATCH', `/screens/${screen.id}`, { profile_id: null, expected_version: current.version })).status).toBe(200)
  await retireScreen(api, screen.id, 'Fin du test E2E des profils')
  expect((await api('PATCH', `/screen-profiles/${profile.body.data.id}`, { is_active: false })).status).toBe(200)
  await context.close()
})
