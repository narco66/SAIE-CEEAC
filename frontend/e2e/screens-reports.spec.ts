import { expect, test } from '@playwright/test'
import { apiAs, login } from './helpers'

/**
 * Module « Gestion des écrans », §13.3 : les rapports d'exploitation du parc sont réellement générés par la file de
 * traitement (worker), au format PDF et Excel, téléchargeables, et proposés dans le parcours « Générer un rapport ».
 */
test('rapports du parc : génération PDF et XLSX, téléchargement, présence dans l’interface', async ({ page, request }) => {
  test.setTimeout(240_000)
  const api = await apiAs(request, 'sylvain.essono')
  const origin = process.env.E2E_BASE_URL ?? 'http://localhost:5173'
  const jobs: [string, string][] = [['offline_screens', 'pdf'], ['remote_commands', 'xlsx'], ['warranty_expiry', 'pdf'], ['availability_report', 'xlsx'], ['maintenance_report', 'pdf']]
  for (const [type, format] of jobs) {
    const created = await api('POST', '/exports', { type, format, params: {} })
    expect(created.status, `${type} : ${JSON.stringify(created.body)}`).toBe(202)
    const id = created.body.data.id
    await expect.poll(async () => (await api('GET', `/exports/${id}`)).body.data.status, { timeout: 90_000, intervals: [1500] }).toBe('ready')
    const file = await request.get(`${origin}/api/v1/exports/${id}/download`, { headers: { Referer: `${origin}/` } })
    expect(file.status()).toBe(200)
    const body = await file.body()
    expect(body.subarray(0, 4).toString('latin1')).toBe(format === 'pdf' ? '%PDF' : 'PK\u0003\u0004')
    expect(body.length).toBeGreaterThan(1000)
  }

  await page.setViewportSize({ width: 1440, height: 1000 })
  await login(page, 'sylvain.essono')
  await page.goto('/rapports')
  await page.getByRole('button', { name: /Générer un rapport/ }).click()
  const dialog = page.getByRole('dialog')
  for (const label of ['Écrans hors ligne', 'Commandes à distance', 'Équipements en fin de garantie', 'Disponibilité du parc', 'Maintenance du parc']) {
    await expect(dialog.getByRole('combobox', { name: 'Type de document' }).locator('option', { hasText: label })).toHaveCount(1)
  }
})
