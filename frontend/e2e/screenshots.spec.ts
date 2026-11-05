import { test } from '@playwright/test'
import { login } from './helpers'

/** Captures représentatives pour la comparaison visuelle avec la maquette (docs/tests/captures). */
const PAGES: [string, string][] = [
  ['/accueil', 'v04-accueil'], ['/affichage/contenus', 'v17-contenus'], ['/affichage/ecrans', 'v07-parc'], ['/salles', 'v30-salles'],
  ['/affichage/alertes', 'v23-alertes'], ['/rapports', 'v33-rapports'], ['/administration/utilisateurs', 'v34-utilisateurs'], ['/salles/calendrier', 'v25-calendrier'],
]

test('captures des vues principales', async ({ page }) => {
  await page.setViewportSize({ width: 1440, height: 900 })
  await login(page, 'alain.mbeng')
  for (const [route, name] of PAGES) {
    await page.goto(route)
    await page.waitForLoadState('networkidle')
    await page.waitForTimeout(500)
    await page.screenshot({ path: `../docs/tests/captures/${name}.png` })
  }
})
