import { expect, test } from '@playwright/test'
import { apiAs, login, retireScreen } from './helpers'

const PLAYER = process.env.E2E_PLAYER_URL ?? 'http://localhost:5174'

/**
 * §9.2 : un lecteur réel est réaffecté à un autre écran depuis la fiche, sans révocation ; il reçoit ensuite le manifeste
 * du nouvel écran (accusé de réception rattaché au nouvel écran) et l'ancien écran redevient « en attente de configuration ».
 */
test('réaffectation d’un lecteur réel à un autre écran', async ({ browser, page, request }) => {
  test.setTimeout(300_000)
  const api = await apiAs(request, 'alain.mbeng')
  const refs = (await api('GET', '/referentials')).body.data
  const suffix = Date.now().toString().slice(-6)
  const create = async (code: string) => (await api('POST', '/screens', {
    code, name: `Réaffectation ${code}`, kind: 'display', location_id: refs.locations[0].id, orientation: 'landscape', resolution: '1920x1080',
    operating_window: { days: [1, 2, 3, 4, 5, 6, 7], on: '00:00', off: '23:59' }, window_override_reason: 'Écran de test automatisé',
  })).body.data
  const first = await create(`ECR-RAF-A-${suffix}`)
  const second = await create(`ECR-RAF-B-${suffix}`)

  const context = await browser.newContext({ viewport: { width: 1280, height: 720 } })
  const player = await context.newPage()
  await player.goto(PLAYER)
  const codeBox = player.locator('.code')
  await expect(codeBox).toBeVisible({ timeout: 30_000 })
  const paired = await api('POST', '/devices/pair', { code: (await codeBox.getAttribute('aria-label'))!.replace(/.*\s/, ''), screen_id: first.id })
  expect(paired.status).toBe(201)
  await expect.poll(async () => (await api('GET', `/screens/${first.id}`)).body.data.device?.active_manifest_version ?? null, { timeout: 120_000, intervals: [3000] }).not.toBeNull()

  await page.setViewportSize({ width: 1440, height: 1000 })
  await login(page, 'alain.mbeng')
  await page.goto(`/affichage/ecrans/${first.id}`)
  await page.getByRole('tab', { name: 'Lecteur' }).click()
  await page.getByRole('button', { name: 'Réaffecter à un autre écran' }).click()
  const dialog = page.getByRole('dialog', { name: 'Réaffecter le lecteur' })
  await dialog.getByLabel('Nouvel écran').selectOption(second.id)
  await dialog.getByLabel('Motif').fill('Boîtier déplacé (test E2E)')
  const movedAt = Date.now() - 2000
  await dialog.getByRole('button', { name: 'Réaffecter' }).click()
  await expect(page).toHaveURL(new RegExp(`/affichage/ecrans/${second.id}`))

  // Le même lecteur (non révoqué) applique le programme du nouvel écran.
  await expect.poll(async () => Date.parse((await api('GET', `/screens/${second.id}`)).body.data.manifest?.generated_at ?? '') || 0, { timeout: 120_000, intervals: [3000] }).toBeGreaterThan(movedAt)
  const moved = (await api('GET', `/screens/${second.id}`)).body.data.device
  expect([moved.id, moved.status]).toEqual([paired.body.data.id, 'active'])
  expect((await api('GET', `/screens/${first.id}`)).body.data.operational_state.code).toBe('pending_configuration')

  await context.close()
  for (const s of [first, second]) await retireScreen(api, s.id, 'Fin du test E2E de réaffectation')
})
