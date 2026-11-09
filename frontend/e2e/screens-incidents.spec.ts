import { expect, test, type Page } from '@playwright/test'
import { apiAs, login } from './helpers'

/**
 * Module « Gestion des écrans », scénario E (maintenance, §15.2) dans l'interface : incident déclaré, qualifié, assigné,
 * intervention consignée, résolu, clôture refusée avant vérification, vérifié par un autre technicien, clos.
 */
test('incident : qualification, affectation, intervention, résolution, vérification, clôture', async ({ page, browser, request }) => {
  test.setTimeout(180_000)
  const api = await apiAs(request, 'alain.mbeng')
  const screen = (await api('GET', '/screens?per_page=1')).body.data[0]
  const title = `Image figée (E2E ${Date.now().toString().slice(-6)})`
  expect((await api('POST', '/incidents', { title, severity: 'minor', screen_id: screen.id })).status).toBe(201)

  await page.setViewportSize({ width: 1440, height: 1000 })
  await login(page, 'alain.mbeng')
  await page.goto('/supervision')
  await page.getByRole('row', { name: new RegExp(title.replace(/[()]/g, '\\$&')) }).getByRole('button', { name: 'Ouvrir' }).click()
  const act = async (p: Page, label: string, note?: string, extra?: (dialog: ReturnType<Page['getByRole']>) => Promise<void>) => {
    const dialog = p.getByRole('dialog')
    await dialog.getByRole('button', { name: label, exact: true }).first().click()
    if (extra) await extra(dialog)
    if (note) await dialog.locator('textarea').last().fill(note)
    await dialog.getByRole('button', { name: label, exact: true }).last().click()
  }
  const dialog = page.getByRole('dialog')
  await act(page, 'Qualifier', 'Plusieurs écrans du niveau touchés', async (d) => { await d.getByLabel('Gravité').selectOption('major') })
  await expect(dialog.getByText('Qualification')).toBeVisible()
  await act(page, 'Assigner', undefined, async (d) => { await d.getByLabel('Assigner à').selectOption({ label: 'Didier Ella' }) })
  await act(page, 'Consigner une intervention', 'Câble HDMI remplacé')
  await act(page, 'Résoudre', 'Image rétablie après remplacement du câble')
  await expect(dialog.getByText('Résultat du traitement :')).toBeVisible()
  await dialog.getByRole('button', { name: 'Clore', exact: true }).first().click()
  await expect(dialog.getByText('Vérifiez d’abord le fonctionnement rétabli')).toBeVisible()
  await dialog.screenshot({ path: '../docs/AUDIT-GESTION-ECRANS/captures/incident-circuit.png' })

  // Vérification par un autre technicien (Didier Ella), puis clôture.
  const other = await browser.newContext({ viewport: { width: 1440, height: 1000 } })
  const tech = await other.newPage()
  await login(tech, 'didier.ella')
  await tech.goto('/supervision')
  await tech.getByRole('combobox', { name: 'État' }).selectOption('resolved')
  await tech.getByRole('row', { name: new RegExp(title.replace(/[()]/g, '\\$&')) }).getByRole('button', { name: 'Ouvrir' }).click()
  await act(tech, 'Vérifier le fonctionnement', 'Diffusion contrôlée sur place pendant 15 minutes')
  await expect(tech.getByRole('dialog').getByText('Didier Ella').first()).toBeVisible()
  await act(tech, 'Clore')
  await expect(tech.getByRole('dialog').getByText('Clos', { exact: true }).first()).toBeVisible()
  await other.close()
})
