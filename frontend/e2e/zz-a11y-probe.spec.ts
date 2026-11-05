import { writeFileSync } from 'node:fs'
import { AxeBuilder } from '@axe-core/playwright'
import { test } from '@playwright/test'
import { login } from './helpers'
const PAGES: [string, string][] = [['/accueil','alain.mbeng'],['/affichage/contenus','nadia.kombila'],['/affichage/contenus/nouveau','nadia.kombila'],['/affichage/ecrans','alain.mbeng'],['/affichage/alertes','alain.mbeng'],['/salles','loic.bouanga'],['/salles/calendrier','loic.bouanga'],['/reservations/nouvelle','loic.bouanga'],['/documents','nadia.kombila'],['/mes-taches','grace.ndong'],['/administration/utilisateurs','alain.mbeng'],['/rapports','sylvain.essono'],['/mon-compte','alain.mbeng']]
test('probe', async ({ browser }) => {
  test.setTimeout(600000)
  const pairs: Record<string, { n: number; sel: Set<string>; pages: Set<string> }> = {}
  for (const [path, as] of PAGES) {
    const ctx = await browser.newContext({ locale: "fr-FR", timezoneId: "Africa/Libreville", baseURL: "http://localhost:5173" }); const page = await ctx.newPage()
    await login(page, as); await page.goto(path); await page.waitForLoadState('networkidle')
    const r = await new AxeBuilder({ page }).withTags(['wcag2a','wcag2aa','wcag21aa','wcag22aa']).analyze()
    for (const v of r.violations) for (const n of v.nodes) {
      const d: any = n.any[0]?.data ?? {}
      const key = `${v.id} fg=${d.fgColor} bg=${d.bgColor} ratio=${d.contrastRatio} size=${d.fontSize} w=${d.fontWeight}`
      const e = (pairs[key] ??= { n: 0, sel: new Set(), pages: new Set() })
      e.n++; e.pages.add(path); if (e.sel.size < 4) e.sel.add(n.target.join(' ') + ' :: ' + n.html.slice(0, 90))
    }
    await ctx.close()
  }
  const out = Object.entries(pairs).sort((a, b) => b[1].n - a[1].n).map(([k, v]) => `${v.n}x ${k}\n   pages: ${[...v.pages].join(', ')}\n   ${[...v.sel].join('\n   ')}`).join('\n')
  writeFileSync('test-results/a11y-probe.txt', out)
})
