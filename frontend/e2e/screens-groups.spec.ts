import { expect, test } from '@playwright/test'
import { apiAs, login } from './helpers'

/**
 * Module « Gestion des écrans », §8 : groupe dynamique créé par l'interface (critère de niveau), membres calculés côté
 * serveur et affichés avec leur supervision, puis suppression.
 */
test('groupe dynamique : critères, membres recalculés, statistiques, suppression', async ({ page, request }) => {
  const api = await apiAs(request, 'alain.mbeng')
  const refs = (await api('GET', '/referentials')).body.data
  const floor = refs.floors[0]
  const expected = (await api('GET', `/screens?floor_id=${floor.id}&per_page=100`)).body.meta.total
  const code = `G-E2E-${Date.now().toString().slice(-6)}`

  await page.setViewportSize({ width: 1440, height: 1000 })
  await login(page, 'alain.mbeng')
  await page.goto('/affichage/groupes')
  await page.getByRole('button', { name: 'Nouveau groupe' }).click()
  const dialog = page.getByRole('dialog', { name: 'Nouveau groupe' })
  await dialog.getByLabel('Code').fill(code)
  await dialog.getByRole('textbox', { name: 'Nom (obligatoire)' }).fill(`Niveau ${floor.label} (E2E)`)
  await dialog.getByRole('combobox', { name: /^Composition/ }).selectOption('dynamic')
  await dialog.getByRole('group', { name: 'Niveaux' }).getByLabel(floor.label, { exact: true }).check()
  await dialog.getByRole('button', { name: 'Créer le groupe' }).click()
  await expect(dialog).toHaveCount(0)

  const row = page.getByRole('row', { name: new RegExp(code) })
  await expect(row).toContainText('Dynamique')
  await expect(row).toContainText(`${expected} écran(s)`)
  await expect(row).toContainText('en ligne')
  await page.screenshot({ path: '../docs/AUDIT-GESTION-ECRANS/captures/groupes-dynamiques.png', fullPage: true })

  const group = (await api('GET', '/screen-groups')).body.data.find((g: { code: string }) => g.code === code)
  expect(group.criteria.floor_ids).toEqual([floor.id])
  expect((await api('DELETE', `/screen-groups/${group.id}`)).status).toBe(204)
})
