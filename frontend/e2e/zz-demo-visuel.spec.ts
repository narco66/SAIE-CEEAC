import { expect, test, type Page } from '@playwright/test'
import { apiAs, login } from './helpers'

/**
 * Sonde (E2E_PROBES=1) : parcourt les écrans qui affichent les scénarios de démonstration (DemoScenariosSeeder),
 * pour plusieurs profils, et relève erreurs de console, réponses 5xx et écrans d'erreur. Captures dans E2E_SHOTS.
 */
const SHOTS = process.env.E2E_SHOTS ?? 'test-results/demo-visuel'

type Problem = string
async function visit(page: Page, route: string, name: string, problems: Problem[]): Promise<void> {
  const errors: string[] = []
  const onConsole = (m: { type(): string; text(): string }) => { if (m.type() === 'error') { errors.push(m.text().slice(0, 200)) } }
  const onResponse = (r: { status(): number; url(): string }) => { if (r.status() >= 500) { errors.push(`${r.status()} ${r.url()}`) } }
  page.on('console', onConsole)
  page.on('response', onResponse)
  await page.goto(route)
  await page.waitForLoadState('networkidle')
  await page.waitForTimeout(400)
  const body = (await page.locator('body').innerText()).slice(0, 4000)
  if (/Une erreur est survenue|Something went wrong|Erreur inattendue|Page introuvable/i.test(body)) { errors.push('écran d’erreur affiché') }
  await page.screenshot({ path: `${SHOTS}/${name}.png`, fullPage: true })
  page.off('console', onConsole)
  page.off('response', onResponse)
  for (const e of errors) { problems.push(`${name} (${route}) : ${e}`) }
}

test('écrans des scénarios de démonstration', async ({ page, request }) => {
  test.setTimeout(900_000)
  await page.setViewportSize({ width: 1440, height: 900 })
  const api = await apiAs(request, 'alain.mbeng')
  const find = async (path: string, match: (row: any) => boolean): Promise<string> => {
    const rows = (await api('GET', path)).body?.data ?? []
    const row = rows.find(match)
    return row ? String(row.id) : ''
  }
  const ids = {
    ecranSuspect: await find('/screens?per_page=100', (s) => s.code === 'ECR-5E-03'),
    ecranMaintenance: await find('/screens?per_page=100', (s) => s.code === 'ECR-4E-03'),
    annuaire: await find('/documents?per_page=100&search=Annuaire', (d) => d.title === 'Annuaire téléphonique interne'),
    collecte: await find('/campaigns?per_page=100', (c) => /Collecte de sang/.test(c.name)),
    minko: await find('/rooms?per_page=100', (r) => /MINKO/i.test(r.code ?? '')),
    // En dernier : la recherche se fait avec le compte de l'organisatrice, qui remplace la session.
    atelier: String((await (await apiAs(request, 'nadia.kombila'))('GET', '/reservations?per_page=5&search=libre')).body?.data?.[0]?.id ?? ''),
  }
  console.log('ids', JSON.stringify(ids))

  const problems: Problem[] = []
  const plan: [string, [string, string][]][] = [
    ['alain.mbeng', [
      ['/accueil', 'admin-accueil'], ['/administration/utilisateurs', 'admin-utilisateurs'], ['/administration/roles', 'admin-roles'],
      ['/administration/journaux', 'admin-journaux'], ['/administration/imports', 'admin-imports'], ['/administration/parametres', 'admin-parametres'],
      ['/affichage/ecrans', 'admin-ecrans'], [`/affichage/ecrans/${ids.ecranSuspect}`, 'admin-ecran-suspect'], [`/affichage/ecrans/${ids.ecranMaintenance}`, 'admin-ecran-maintenance'],
      ['/affichage/campagnes', 'admin-campagnes'], ['/affichage/programme', 'admin-programme'], ['/affichage/modeles', 'admin-modeles'], ['/affichage/alertes', 'admin-alertes'],
      ['/affichage/contenus', 'admin-contenus'], ['/documents', 'admin-documents'], [`/documents/${ids.annuaire}`, 'admin-document-annuaire'],
      ['/supervision', 'admin-supervision'], ['/traitements', 'admin-traitements'], ['/rapports', 'admin-rapports'], ['/notifications', 'admin-notifications'],
      ['/salles', 'admin-salles'], [`/salles/${ids.minko}`, 'admin-salle-minko'], ['/salles/calendrier', 'admin-calendrier'], [`/reservations/${ids.atelier}`, 'admin-atelier'],
    ]],
    ['mireille.tchibinda', [['/reservations/a-valider', 'gest-a-valider'], ['/salles/calendrier', 'gest-calendrier'], ['/prestations', 'gest-prestations'], ['/accueil-protocole', 'gest-protocole']]],
    ['clarisse.ondo', [['/mes-reservations', 'clarisse-mes-reservations'], ['/mes-taches', 'clarisse-taches']]],
    ['patrick.obiang', [['/affichage/alertes', 'patrick-alertes'], ['/mes-taches', 'patrick-taches']]],
    ['jean.moussavou', [['/affichage/contenus', 'jean-contenus'], [`/affichage/campagnes`, 'jean-campagnes'], ['/affichage/programme', 'jean-programme']]],
    ['didier.ella', [['/accueil', 'technicien-accueil'], ['/affichage/ecrans', 'technicien-ecrans'], ['/supervision', 'technicien-supervision'], ['/traitements', 'technicien-traitements']]],
    ['sandra.bivigou', [['/accueil', 'sandra-accueil'], ['/reservations/a-valider', 'sandra-a-valider'], ['/salles', 'sandra-salles']]],
    ['ruth.mabika', [['/accueil', 'ruth-accueil'], ['/documents', 'ruth-documents'], ['/salles/calendrier', 'ruth-calendrier']]],
  ]
  for (const [who, pages] of plan) {
    await page.context().clearCookies()
    await login(page, who)
    for (const [route, name] of pages) {
      await visit(page, route, name, problems)
    }
  }

  // Comptes bloqués : la connexion doit être refusée avec un message, sans erreur serveur.
  // olga.mintsa n'est verrouillée que 30 minutes après le chargement des données : non testée ici.
  for (const who of ['herve.mba', 'bernard.nzue']) {
    await page.context().clearCookies()
    await page.goto('/connexion')
    await page.getByLabel('Identifiant professionnel').fill(`${who}@demo.saie-ceeac.local`)
    await page.locator('input[autocomplete="current-password"]').fill(process.env.E2E_PASSWORD ?? (await import('./helpers')).PASSWORD)
    await page.getByRole('button', { name: 'Se connecter' }).click()
    await page.waitForTimeout(1500)
    await page.screenshot({ path: `${SHOTS}/refus-${who}.png` })
    if (/\/accueil/.test(page.url())) { problems.push(`${who} : connexion acceptée alors que le compte est bloqué`) }
  }
  // Nouvel arrivant : redirigé vers le changement de mot de passe.
  await page.context().clearCookies()
  await page.goto('/connexion')
  await page.getByLabel('Identifiant professionnel').fill('kevin.assoumou@demo.saie-ceeac.local')
  await page.locator('input[autocomplete="current-password"]').fill((await import('./helpers')).PASSWORD)
  await page.getByRole('button', { name: 'Se connecter' }).click()
  await page.waitForURL(/mon-compte/, { timeout: 15_000 }).catch(() => problems.push('kevin : pas redirigé vers le changement de mot de passe'))
  await page.waitForLoadState('networkidle')
  await page.screenshot({ path: `${SHOTS}/kevin-premiere-connexion.png` })
  console.log('kevin →', page.url())
  // Second facteur : l'étape TOTP est demandée.
  await page.context().clearCookies()
  await page.goto('/connexion')
  await page.getByLabel('Identifiant professionnel').fill('paul.nguema@demo.saie-ceeac.local')
  await page.locator('input[autocomplete="current-password"]').fill((await import('./helpers')).PASSWORD)
  await page.getByRole('button', { name: 'Se connecter' }).click()
  await page.waitForTimeout(1500)
  await page.screenshot({ path: `${SHOTS}/paul-second-facteur.png` })
  console.log('paul →', page.url())

  console.log(`PROBLEMES (${problems.length})\n` + problems.join('\n'))
  expect(problems).toEqual([])
})
