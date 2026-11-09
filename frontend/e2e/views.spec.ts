import { expect, test } from '@playwright/test'
import { login } from './helpers'

const ADMIN_ROUTES = [
  '/accueil', '/mes-taches', '/notifications', '/affichage/contenus', '/mediatheque', '/documents', '/affichage/campagnes', '/affichage/programme',
  '/affichage/parc', '/affichage/ecrans', '/affichage/groupes', '/affichage/profils', '/affichage/appairage', '/salles', '/salles/calendrier', '/mes-reservations', '/reservations/nouvelle',
  '/affichage/alertes', '/rapports', '/traitements', '/supervision', '/administration/utilisateurs', '/administration/roles', '/administration/parametres', '/mon-compte', '/aide',
]

test('chaque vue de l’administrateur DSI se charge sans erreur ni appel API en échec', async ({ page }) => {
  const failures: string[] = []
  const consoleErrors: string[] = []
  page.on('response', (r) => { if (r.url().includes('/api/') && r.status() >= 500) failures.push(`${r.status()} ${r.url()}`) })
  page.on('pageerror', (e) => consoleErrors.push(e.message))
  await login(page, 'alain.mbeng')
  for (const route of ADMIN_ROUTES) {
    await page.goto(route)
    await page.waitForLoadState('networkidle')
    await expect(page.locator('h1').first(), route).toBeVisible()
    await expect(page.locator('.state-box.error'), `${route} affiche une erreur`).toHaveCount(0)
  }
  expect(failures).toEqual([])
  expect(consoleErrors).toEqual([])
})

test('les menus reflètent les permissions : un agent ne voit ni l’administration ni la diffusion', async ({ page }) => {
  await login(page, 'loic.bouanga')
  const nav = page.getByRole('navigation', { name: 'Menu principal' })
  await expect(nav.getByRole('link', { name: 'Documents' })).toBeVisible()
  await expect(nav.getByRole('link', { name: 'Mes réservations' })).toBeVisible()
  await expect(nav.getByRole('link', { name: 'Utilisateurs' })).toHaveCount(0)
  await expect(nav.getByRole('link', { name: 'Alertes urgentes' })).toHaveCount(0)
  await page.goto('/administration/utilisateurs')
  await expect(page.getByText('Accès non autorisé')).toBeVisible()
})

test('le tableau de bord affiche des indicateurs calculés et une réunion confidentielle masquée', async ({ page }) => {
  await login(page, 'alain.mbeng')
  await expect(page.getByText('Écrans connectés')).toBeVisible()
  await expect(page.getByText('Réunions du jour')).toBeVisible()
  await expect(page.getByText('arbitrages budgétaires', { exact: false })).toHaveCount(0)
})
