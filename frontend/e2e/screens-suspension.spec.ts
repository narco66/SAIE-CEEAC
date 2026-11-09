import { expect, test } from '@playwright/test'
import { apiAs, login, retireScreen } from './helpers'

const PLAYER = process.env.E2E_PLAYER_URL ?? 'http://localhost:5174'

/**
 * §10.7, §10.2, §21.4 : un lecteur réel transmet la télémétrie de l'agent local ; il est suspendu puis rétabli depuis la
 * fiche (nouveau programme servi à chaque fois) ; le suivi de déploiement d'une version s'ouvre en lecture.
 */
test('suspension d’un lecteur réel, télémétrie de l’agent et suivi de déploiement', async ({ browser, page, request }) => {
  test.setTimeout(300_000)
  const api = await apiAs(request, 'alain.mbeng')
  const refs = (await api('GET', '/referentials')).body.data
  const suffix = Date.now().toString().slice(-6)
  const screen = (await api('POST', '/screens', {
    code: `ECR-SUSP-${suffix}`, name: `Suspension ${suffix}`, kind: 'display', location_id: refs.locations[0].id, orientation: 'landscape', resolution: '1920x1080',
    operating_window: { days: [1, 2, 3, 4, 5, 6, 7], on: '00:00', off: '23:59' }, window_override_reason: 'Écran de test automatisé',
  })).body.data

  const context = await browser.newContext({ viewport: { width: 1280, height: 720 } })
  const player = await context.newPage()
  await player.goto(PLAYER)
  const codeBox = player.locator('.code')
  await expect(codeBox).toBeVisible({ timeout: 30_000 })
  const paired = await api('POST', '/devices/pair', { code: (await codeBox.getAttribute('aria-label'))!.replace(/.*\s/, ''), screen_id: screen.id })
  expect(paired.status).toBe(201)
  // Télémétrie réelle de l'agent local, transmise avec le battement.
  await expect.poll(async () => (await api('GET', `/screens/${screen.id}`)).body.data.device?.hardware_info?.agent_version ?? null, { timeout: 120_000, intervals: [3000] }).not.toBeNull()

  await page.setViewportSize({ width: 1440, height: 1000 })
  await login(page, 'alain.mbeng')
  await page.goto(`/affichage/ecrans/${screen.id}`)
  await page.getByRole('tab', { name: 'Lecteur' }).click()
  await expect(page.getByRole('heading', { name: 'Télémétrie de l’agent local' })).toBeVisible()
  await expect(page.getByText(/Go libres sur/).first()).toBeVisible()

  const step = async (button: string, title: string, confirm: string, reason: string) => {
    const before = Date.now() - 2000
    await page.getByRole('button', { name: button }).click()
    const dialog = page.getByRole('dialog', { name: title })
    await dialog.getByLabel(/Motif/).fill(reason)
    await dialog.getByRole('button', { name: confirm, exact: true }).click()
    await expect(dialog).toBeHidden()
    // Le lecteur reçoit un nouveau programme après le changement.
    await expect.poll(async () => Date.parse((await api('GET', `/screens/${screen.id}`)).body.data.manifest?.generated_at ?? '') || 0, { timeout: 120_000, intervals: [3000] }).toBeGreaterThan(before)
  }
  await step('Suspendre le lecteur', 'Suspendre le lecteur', 'Suspendre', 'Lecteur suspect (test E2E)')
  await expect(page.getByText(/Lecteur suspendu depuis le/)).toBeVisible()
  expect((await api('GET', `/screens/${screen.id}`)).body.data.operational_state.code).toBe('suspended')
  await step('Rétablir le lecteur', 'Rétablir le lecteur', 'Rétablir', 'Contrôle effectué (test E2E)')
  expect((await api('GET', `/screens/${screen.id}`)).body.data.operational_state.code).not.toBe('suspended')

  // Suivi de déploiement en lecture seule (aucune version n'est publiée ni modifiée par ce test).
  const releases = (await api('GET', '/player-releases')).body.data as { id: string; version: string; status: string }[]
  const active = releases.find((r) => r.status === 'active')
  if (active) {
    await page.goto('/supervision')
    await page.getByRole('row', { name: new RegExp(active.version.replace(/\./g, '\\.')) }).getByRole('button', { name: 'Suivi' }).click()
    const dialog = page.getByRole('dialog', { name: 'Suivi du déploiement' })
    await expect(dialog.getByText(/lecteur\(s\) actif\(s\) sur le canal/)).toBeVisible()
    await dialog.getByRole('button', { name: 'Fermer' }).last().click()
  }

  await context.close()
  await retireScreen(api, screen.id, 'Fin du test E2E de suspension')
})
