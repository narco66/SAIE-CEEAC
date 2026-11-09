import { expect, test } from '@playwright/test'
import { apiAs, login, retireScreen } from './helpers'

const PLAYER = process.env.E2E_PLAYER_URL ?? 'http://localhost:5174'

/**
 * Lots 20 à 25 avec un lecteur réel : mise en service contrôlée (§15.1), battement de l'agent local (§10.16), journal des
 * échanges (§17.6), fiche écran PDF (§5.5-j), flux temps réel (§10.22) annonçant la mise en service à la supervision.
 */
test('mise en service, agent, échanges, fiche PDF et temps réel', async ({ browser, page, request }) => {
  test.setTimeout(360_000)
  const api = await apiAs(request, 'alain.mbeng')
  const refs = (await api('GET', '/referentials')).body.data
  const suffix = Date.now().toString().slice(-6)
  const screen = (await api('POST', '/screens', {
    code: `ECR-L25-${suffix}`, name: `Lots 20-25 ${suffix}`, kind: 'display', location_id: refs.locations[0].id, orientation: 'landscape', resolution: '1920x1080',
    operating_window: { days: [1, 2, 3, 4, 5, 6, 7], on: '00:00', off: '23:59' }, window_override_reason: 'Écran de test automatisé',
  })).body.data

  const context = await browser.newContext({ viewport: { width: 1280, height: 720 } })
  const player = await context.newPage()
  await player.goto(PLAYER)
  const codeBox = player.locator('.code')
  await expect(codeBox).toBeVisible({ timeout: 30_000 })
  expect((await api('POST', '/devices/pair', { code: (await codeBox.getAttribute('aria-label'))!.replace(/.*\s/, ''), screen_id: screen.id })).status).toBe(201)

  // Faits exigés par la mise en service : programme et configuration appliqués, rendu confirmé (repli affiché).
  await expect.poll(async () => {
    const s = (await api('GET', `/screens/${screen.id}`)).body.data
    return Boolean(s.device?.active_manifest_version && s.configuration?.sync === 'applied')
  }, { timeout: 150_000, intervals: [3000] }).toBe(true)
  // §10.16 : l'agent local reçoit son jeton du lecteur et bat de lui-même.
  await expect.poll(async () => (await api('GET', `/screens/${screen.id}`)).body.data.device?.agent_seen_at ?? null, { timeout: 150_000, intervals: [5000] }).not.toBeNull()

  await page.setViewportSize({ width: 1440, height: 1000 })
  await login(page, 'alain.mbeng')

  // Supervision : flux temps réel connecté.
  await page.goto('/supervision')
  await expect(page.getByText('Temps réel', { exact: true })).toBeVisible({ timeout: 30_000 })

  await page.goto(`/affichage/ecrans/${screen.id}`)
  await expect(page.getByText('Non mis en service')).toBeVisible()
  await page.getByRole('button', { name: 'Mettre en service' }).click()
  const dialog = page.getByRole('dialog', { name: 'Mettre en service' })
  await dialog.getByLabel(/J’atteste avoir vérifié visuellement/).check()
  await dialog.getByRole('button', { name: 'Mettre en service' }).click()
  await expect(dialog).toBeHidden({ timeout: 20_000 })
  await expect(page.getByText('Non mis en service')).toHaveCount(0)
  expect((await api('GET', `/screens/${screen.id}`)).body.data.commissioning?.report?.checks?.rendering_confirmed).toBe(true)

  // Fiche PDF : demande d'export prise en charge par le serveur.
  await page.getByRole('button', { name: 'Fiche écran (PDF)' }).click()
  await expect(page.getByRole('dialog', { name: /Fiche écran/ })).toBeVisible()
  await page.keyboard.press('Escape')

  // Onglet lecteur : battement de l'agent et journal des échanges.
  await page.getByRole('tab', { name: 'Lecteur' }).click()
  await expect(page.getByText('Dernier battement de l’agent local')).toBeVisible()
  await page.getByLabel('Source des journaux').selectOption('exchanges')
  await expect(page.getByText(/api\/v1\/device\/(heartbeat|manifest)/).first()).toBeVisible({ timeout: 20_000 })

  await context.close()
  await retireScreen(api, screen.id, 'Fin du test E2E des lots 20 à 25')
})
