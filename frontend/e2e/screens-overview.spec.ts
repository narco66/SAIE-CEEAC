import { expect, test } from '@playwright/test'
import { login } from './helpers'

/** Module « Gestion des écrans », §4 : vue d'ensemble, carte cliquable vers la liste filtrée, vue par niveau. */
test('vue d’ensemble du parc : indicateurs, carte vers la liste filtrée, vue par niveau', async ({ page }) => {
  await page.setViewportSize({ width: 1440, height: 1000 })
  await login(page, 'alain.mbeng')
  await page.goto('/affichage/parc')
  await expect(page.getByRole('heading', { name: 'Vue d’ensemble du parc' })).toBeVisible()
  await expect(page.getByRole('region', { name: 'Vue par niveau' })).toBeVisible()
  await page.screenshot({ path: '../docs/AUDIT-GESTION-ECRANS/captures/vue-ensemble-parc.png', fullPage: true })
  const notConfigured = page.getByRole('link', { name: /Non configurés/ })
  const count = Number((await notConfigured.locator('strong').innerText()).trim())
  await notConfigured.click()
  await expect(page).toHaveURL(/state=pending_configuration/)
  if (count > 0) await expect(page.getByText(/En attente de configuration/).first()).toBeVisible()
})
