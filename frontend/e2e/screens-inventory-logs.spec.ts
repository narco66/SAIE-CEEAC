import { expect, test } from '@playwright/test'
import { apiAs, login, retireScreen } from './helpers'

const PLAYER = process.env.E2E_PLAYER_URL ?? 'http://localhost:5174'

/**
 * Lots 17 à 19 : code d'inventaire saisi puis retrouvé par la recherche, désactivation et réactivation motivées et
 * journalisées, journaux techniques et adresse observée d'un lecteur réel.
 */
test('inventaire, recherche, désactivation motivée et journaux techniques', async ({ browser, page, request }) => {
  test.setTimeout(300_000)
  const api = await apiAs(request, 'alain.mbeng')
  const refs = (await api('GET', '/referentials')).body.data
  const suffix = Date.now().toString().slice(-6)
  const screen = (await api('POST', '/screens', {
    code: `ECR-INVLOG-${suffix}`, name: `Inventaire ${suffix}`, kind: 'display', location_id: refs.locations[0].id, orientation: 'landscape', resolution: '1920x1080',
    operating_window: { days: [1, 2, 3, 4, 5, 6, 7], on: '00:00', off: '23:59' }, window_override_reason: 'Écran de test automatisé',
  })).body.data

  const context = await browser.newContext({ viewport: { width: 1280, height: 720 } })
  const player = await context.newPage()
  await player.goto(PLAYER)
  const codeBox = player.locator('.code')
  await expect(codeBox).toBeVisible({ timeout: 30_000 })
  const paired = await api('POST', '/devices/pair', { code: (await codeBox.getAttribute('aria-label'))!.replace(/.*\s/, ''), screen_id: screen.id })
  expect(paired.status).toBe(201)
  await expect.poll(async () => (await api('GET', `/screens/${screen.id}`)).body.data.device?.observed_ip ?? null, { timeout: 120_000, intervals: [3000] }).not.toBeNull()

  await page.setViewportSize({ width: 1440, height: 1000 })
  await login(page, 'alain.mbeng')
  await page.goto(`/affichage/ecrans/${screen.id}`)

  // Code d'inventaire saisi dans la fiche.
  await page.getByRole('button', { name: 'Modifier l’écran' }).click()
  let dialog = page.getByRole('dialog', { name: 'Modifier l’écran' })
  await dialog.getByLabel('Code d’inventaire').fill(`PAT-E2E-${suffix}`)
  await dialog.getByRole('button', { name: 'Enregistrer' }).click()
  await expect(dialog).toBeHidden()
  await expect(page.getByText(`PAT-E2E-${suffix}`)).toBeVisible()

  // Désactivation : motif exigé, puis journalisé.
  await page.getByRole('button', { name: 'Modifier l’écran' }).click()
  dialog = page.getByRole('dialog', { name: 'Modifier l’écran' })
  await dialog.getByLabel('Statut de l’écran').selectOption('inactive')
  await dialog.getByLabel(/Motif de la désactivation/).fill('Dalle déposée (test E2E)')
  await dialog.getByRole('button', { name: 'Enregistrer' }).click()
  await expect(dialog).toBeHidden()
  await page.getByRole('button', { name: 'Modifier l’écran' }).click()
  dialog = page.getByRole('dialog', { name: 'Modifier l’écran' })
  await dialog.getByLabel('Statut de l’écran').selectOption('active')
  await dialog.getByLabel(/Motif de la réactivation/).fill('Dalle reposée (test E2E)')
  await dialog.getByRole('button', { name: 'Enregistrer' }).click()
  await expect(dialog).toBeHidden()
  const history = (await api('GET', `/screens/${screen.id}/history`)).body.data as { label: string; detail: string | null }[]
  expect(history.some((h) => /désactivé/i.test(h.label))).toBe(true)
  expect(history.some((h) => /réactivé/i.test(h.label))).toBe(true)

  // Recherche par code d'inventaire dans la liste.
  await page.goto(`/affichage/ecrans?q=PAT-E2E-${suffix}`)
  await expect(page.getByRole('link', { name: new RegExp(`ECR-INVLOG-${suffix}`) }).first()).toBeVisible()

  // Journaux techniques et adresse observée dans l'onglet Lecteur.
  await page.goto(`/affichage/ecrans/${screen.id}`)
  await page.getByRole('tab', { name: 'Lecteur' }).click()
  await expect(page.getByRole('heading', { name: 'Journaux techniques du lecteur' })).toBeVisible()
  await expect(page.getByText('Adresse vue par le serveur')).toBeVisible()
  await page.getByLabel('Source des journaux').selectOption('telemetry')
  await expect(page.getByRole('heading', { name: 'Journaux techniques du lecteur' })).toBeVisible()

  await context.close()
  await retireScreen(api, screen.id, 'Fin du test E2E inventaire et journaux')
})
