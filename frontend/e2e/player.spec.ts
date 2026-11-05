import { expect, test } from '@playwright/test'
import { apiAs } from './helpers'

const PLAYER = process.env.E2E_PLAYER_URL ?? 'http://localhost:5174'

/**
 * P05/P06, R05/R06/R17, PUB-06, PUB-08 : lecteur réel (application /player) piloté contre l'API réelle.
 */
test('appairage, diffusion multilingue, alerte prioritaire, coupure réseau et révocation', async ({ browser, request }) => {
  const api = await apiAs(request, 'alain.mbeng')
  const screens = await api('GET', '/screens?q=ECR-6E-02')
  const screen = screens.body.data[0]
  expect(screen, 'écran de démonstration non appairé').toBeTruthy()
  if (screen.device) await api('POST', `/devices/${screen.device.id}/revoke`, { reason: 'Réinitialisation du test E2E' })

  const context = await browser.newContext({ viewport: { width: 1280, height: 720 }, timezoneId: 'Africa/Libreville' })
  const player = await context.newPage()
  const errors: string[] = []
  player.on('pageerror', (e) => errors.push(e.message))
  await player.goto(PLAYER)

  // T04 : le lecteur non appairé affiche un code temporaire.
  const codeBox = player.locator('.code')
  await expect(codeBox).toBeVisible({ timeout: 30_000 })
  const code = (await codeBox.getAttribute('aria-label'))!.replace(/.*\s/, '')
  expect(code).toMatch(/^[A-Z0-9]{6}$/)

  const paired = await api('POST', '/devices/pair', { code, screen_id: screen.id, replace: true })
  expect(paired.status).toBe(201)
  expect(JSON.stringify(paired.body)).not.toContain('token')

  // T01/T02 : programme diffusé après téléchargement vérifié et bascule atomique.
  const title = player.locator('.text-zone h1')
  await expect(title).toBeVisible({ timeout: 60_000 })
  const shown = await title.innerText()
  expect(['Horaires de fin d’année', 'End-of-year working hours', 'Ensemble pour une Afrique centrale intégrée']).toContain(shown)
  await expect(player.getByText('Together for an integrated Central Africa')).toHaveCount(0)
  await expect(player.getByText('Réunion de coordination interservices')).toHaveCount(0)

  // PUB-06 : alerte sur cet écran, activée en moins de 60 s, puis levée et reprise du programme.
  const alert = await api('POST', '/alerts', {
    title: 'Exercice d’évacuation', message: 'Exercice : rejoignez le point de rassemblement.', severity: 'exercise', duration_min: 10,
    justification: 'Test de bout en bout automatisé', targets: [{ type: 'screen', id: screen.id }], confirm: true,
  })
  expect(alert.status).toBe(201)
  const started = Date.now()
  await expect(player.locator('.alert-view')).toBeVisible({ timeout: 60_000 })
  expect(Date.now() - started).toBeLessThan(60_000)
  await expect.poll(async () => (await api('GET', `/alerts/${alert.body.data.id}`)).body.data.receipts.activated, { timeout: 90_000 }).toBeGreaterThan(0)
  expect((await api('POST', `/alerts/${alert.body.data.id}/revoke`, { reason: 'Fin du test' })).status).toBe(200)
  await expect(player.locator('.alert-view')).toHaveCount(0, { timeout: 60_000 })
  await expect(title).toBeVisible()

  // PLY-05 : hors ligne, le lecteur continue le programme valide et signale la perte de contact.
  await context.setOffline(true)
  await expect(player.locator('.ribbon')).toContainText('Mode hors connexion', { timeout: 150_000 })
  await expect(title).toBeVisible()
  await context.setOffline(false)
  await expect(player.locator('.ribbon')).toHaveCount(0, { timeout: 90_000 })

  // R17 : lecteur révoqué → API refusée ; le cache reste lisible jusqu'à expiration de l'autorisation.
  const device = (await api('GET', `/screens/${screen.id}`)).body.data.device
  expect((await api('POST', `/devices/${device.id}/revoke`, { reason: 'Fin du test E2E' })).status).toBe(200)
  const denied = await request.get('http://localhost:5174/api/v1/device/manifest', { headers: { Accept: 'application/json', Authorization: 'Bearer invalide' } })
  expect(denied.status()).toBe(401)
  await player.waitForTimeout(35_000)
  await expect(title).toBeVisible()

  expect(errors).toEqual([])
  await context.close()
})
