import { mkdirSync, writeFileSync } from 'node:fs'
import { AxeBuilder } from '@axe-core/playwright'
import { expect, test, type Page } from '@playwright/test'
import { login } from './helpers'

/**
 * UX-01 — audit automatisé WCAG 2.2 AA (axe-core). Échoue sur toute violation « serious » ou « critical » ;
 * les violations « moderate » et « minor » sont jointes au rapport (annotation) sans faire échouer le test.
 * L'audit automatisé ne remplace pas un test manuel au clavier et avec lecteur d'écran (voir docs/tests/rapport.md).
 */
const TAGS = ['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa']

const PAGES: { path: string; as: string | null; ready?: (page: Page) => Promise<void> }[] = [
  { path: '/connexion', as: null },
  { path: '/compte/verification', as: null },
  { path: '/accueil', as: 'alain.mbeng' },
  { path: '/affichage/contenus', as: 'nadia.kombila' },
  { path: '/affichage/contenus/nouveau', as: 'nadia.kombila' },
  { path: '/affichage/ecrans', as: 'alain.mbeng' },
  { path: '/affichage/alertes', as: 'alain.mbeng' },
  { path: '/salles', as: 'loic.bouanga' },
  { path: '/salles/calendrier', as: 'loic.bouanga' },
  { path: '/reservations/nouvelle', as: 'loic.bouanga' },
  { path: '/documents', as: 'nadia.kombila' },
  { path: '/mes-taches', as: 'grace.ndong' },
  { path: '/administration/utilisateurs', as: 'alain.mbeng' },
  { path: '/rapports', as: 'sylvain.essono' },
  { path: '/mon-compte', as: 'alain.mbeng' },
  { path: '/administration/parametres', as: 'alain.mbeng' },
  { path: '/supervision', as: 'alain.mbeng' },
]

for (const { path, as } of PAGES) {
  test(`axe WCAG 2.2 AA · ${path}${as ? ` (${as})` : ''}`, async ({ page }, info) => {
    if (as) await login(page, as)
    await page.goto(path)
    await page.waitForLoadState('networkidle')
    await expect(page.locator('h1').first()).toBeVisible()
    await expect(page.locator('.state-box[role="status"]')).toHaveCount(0)
    const results = await new AxeBuilder({ page }).withTags(TAGS).analyze()
    const summary = results.violations.map((v) => ({
      id: v.id, impact: v.impact, help: v.help, nodes: v.nodes.length,
      targets: v.nodes.slice(0, 5).map((n) => `${n.target.join(' ')} — ${n.failureSummary?.split('\n').slice(1, 2).join(' ').trim() ?? ''}`),
    }))
    const body = JSON.stringify({ path, as, summary }, null, 2)
    await info.attach('axe-violations.json', { body, contentType: 'application/json' })
    mkdirSync('test-results/a11y', { recursive: true })
    writeFileSync(`test-results/a11y/${path.replace(/\W+/g, '_')}.json`, body)
    for (const v of summary.filter((s) => s.impact === 'moderate' || s.impact === 'minor')) {
      info.annotations.push({ type: `a11y-${v.impact}`, description: `${v.id} (${v.nodes}) : ${v.help}` })
    }
    const blocking = summary.filter((s) => s.impact === 'serious' || s.impact === 'critical')
    expect(blocking, JSON.stringify(blocking, null, 2)).toEqual([])
  })
}
