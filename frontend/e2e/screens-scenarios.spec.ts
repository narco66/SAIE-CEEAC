import { expect, test, type APIRequestContext, type Browser } from '@playwright/test'
import { apiAs } from './helpers'

const PLAYER = process.env.E2E_PLAYER_URL ?? 'http://localhost:5174'

type Api = Awaited<ReturnType<typeof apiAs>>

/**
 * Recette du module « Gestion des écrans » (§20, mission phase 7) avec des lecteurs réels (application /player dans
 * Chromium). Les écrans de test sont créés pour le parcours (plage 7 j/7), puis archivés ; leurs fiches restent.
 *  A — mise en service : écran créé, localisé, lecteur enrôlé, profil, campagne, synchronisation, diffusion prouvée.
 *  B/G — perte de connexion : lecture continue depuis le cache, détection hors ligne et incident, reprise et résolution.
 *  D — diffusion collective : groupe de deux écrans, campagne au groupe, appliquée sur les deux lecteurs.
 *  F — sécurité : actions non autorisées refusées par le serveur.
 */
async function createScreen(api: Api, code: string, locationId: string): Promise<{ id: string; version: number }> {
  const created = await api('POST', '/screens', {
    code, name: `Recette ${code}`, kind: 'display', location_id: locationId, orientation: 'landscape', resolution: '1920x1080',
    operating_window: { days: [1, 2, 3, 4, 5, 6, 7], on: '00:00', off: '23:59' }, window_override_reason: 'Écran de recette automatisée, actif en continu',
  })
  expect(created.status, JSON.stringify(created.body)).toBe(201)
  return created.body.data
}

async function pairPlayer(browser: Browser, api: Api, screenId: string) {
  const context = await browser.newContext({ viewport: { width: 1280, height: 720 } })
  const page = await context.newPage()
  const errors: string[] = []
  page.on('pageerror', (e) => errors.push(e.message))
  await page.goto(PLAYER)
  const codeBox = page.locator('.code')
  await expect(codeBox).toBeVisible({ timeout: 30_000 })
  const paired = await api('POST', '/devices/pair', { code: (await codeBox.getAttribute('aria-label'))!.replace(/.*\s/, ''), screen_id: screenId })
  expect(paired.status).toBe(201)
  return { context, page, errors, deviceId: paired.body.data.id as string }
}

async function publishCampaign(api: Api, name: string, contentId: string, targets: { type: string; id: string }[]) {
  const now = Date.now()
  const created = await api('POST', '/campaigns', {
    name, priority: 90, starts_at: new Date(now - 60_000).toISOString(), ends_at: new Date(now + 3 * 3600_000).toISOString(),
    items: [{ content_id: contentId }], languages: ['fr'], targets,
  })
  expect(created.status, JSON.stringify(created.body)).toBe(201)
  const published = await api('POST', `/campaigns/${created.body.data.id}/publish`)
  expect(published.status, JSON.stringify(published.body)).toBe(200)
  return created.body.data.id as string
}

async function cleanup(api: Api, screens: { id: string }[], campaigns: string[]) {
  for (const id of campaigns) await api('POST', `/campaigns/${id}/suspend`, { reason: 'Fin de la recette automatisée' })
  for (const s of screens) {
    const current = (await api('GET', `/screens/${s.id}`)).body.data
    if (current.device) await api('POST', `/devices/${current.device.id}/revoke`, { reason: 'Fin de la recette automatisée' })
    for (const i of (await api('GET', `/incidents?status=open,acknowledged&per_page=100`)).body.data.filter((x: { screen: { id: string } | null }) => x.screen?.id === s.id)) {
      await api('PATCH', `/incidents/${i.id}`, { action: 'resolve', note: 'Écran de recette retiré' })
      await api('PATCH', `/incidents/${i.id}`, { action: 'verify', note: 'Écran de recette retiré' })
      await api('PATCH', `/incidents/${i.id}`, { action: 'close' })
    }
    const fresh = (await api('GET', `/screens/${s.id}`)).body.data
    await api('POST', `/screens/${s.id}/archive`, { reason: 'Fin de la recette automatisée', expected_version: fresh.version })
  }
}

async function contentAndLocation(api: Api) {
  const contents = (await api('GET', '/contents?per_page=100')).body.data
  const content = contents.find((c: { title: string }) => c.title === 'Message de la Présidence de la Commission')
  expect(content, 'contenu public de démonstration').toBeTruthy()
  const refs = (await api('GET', '/referentials')).body.data
  const location = refs.locations.find((l: { zone: { code: string } | null }) => l.zone?.code === 'Z-ACCUEIL') ?? refs.locations[0]
  return { contentId: content.id as string, locationId: location.id as string }
}

test('scénarios A, B et G : mise en service, perte de connexion, lecture hors ligne, reprise', async ({ browser, request }) => {
  test.setTimeout(900_000)
  const api = await apiAs(request, 'alain.mbeng')
  const { contentId, locationId } = await contentAndLocation(api)
  const suffix = Date.now().toString().slice(-6)
  const screen = await createScreen(api, `ECR-RCT-A-${suffix}`, locationId)
  const player = await pairPlayer(browser, api, screen.id)
  const campaign = await publishCampaign(api, `Recette A ${suffix}`, contentId, [{ type: 'screen', id: screen.id }])
  // Délais de supervision raccourcis pour la recette, rétablis à la fin.
  const settings = (await api('GET', '/settings')).body.data.settings
  const original = Object.fromEntries(settings.filter((s: { key: string }) => s.key.startsWith('supervision.')).map((s: { key: string; value: number }) => [s.key, s.value]))
  try {
    // A : le programme est téléchargé, vérifié, activé et la lecture est déclarée pour cette campagne.
    await expect(player.page.locator('.text-zone h1')).toBeVisible({ timeout: 120_000 })
    await expect.poll(async () => (await api('GET', `/campaigns/${campaign}/delivery`)).body.data.rows[0]?.played_at ?? null,
      { timeout: 180_000, intervals: [5000] }).not.toBeNull()
    // Lot 26 : le lecteur réel déclare l'entrée affichée ; l'écran passe « En diffusion » et la lecture en cours est résolue.
    await expect.poll(async () => (await api('GET', `/screens/${screen.id}`)).body.data.operational_state.code, { timeout: 120_000, intervals: [5000] }).toBe('broadcasting')
    expect((await api('GET', `/screens/${screen.id}`)).body.data.current_playback_resolved?.campaign_id).toBe(campaign)

    // B/G : coupure réseau — la lecture continue depuis le cache vérifié ; le serveur détecte le lecteur hors ligne.
    await api('PATCH', '/settings', { key: 'supervision.suspect_after_s', value: 45, reason: 'Recette : détection accélérée' })
    await api('PATCH', '/settings', { key: 'supervision.offline_after_s', value: 90, reason: 'Recette : détection accélérée' })
    await player.context.setOffline(true)
    await expect(player.page.locator('.ribbon')).toContainText('Mode hors connexion', { timeout: 150_000 })
    await expect(player.page.locator('.text-zone h1')).toBeVisible()
    await expect.poll(async () => (await api('GET', `/screens/${screen.id}`)).body.data.operational_state.code, { timeout: 240_000, intervals: [10_000] }).toBe('offline')
    await expect.poll(async () => (await api('GET', '/incidents?status=open,acknowledged&per_page=100')).body.data.some((i: { screen: { id: string } | null; kind: string }) => i.screen?.id === screen.id && i.kind === 'device_unavailable'),
      { timeout: 150_000, intervals: [10_000] }).toBe(true)

    // Reprise : le lecteur se resynchronise, l'incident est résolu automatiquement avec son résultat.
    await player.context.setOffline(false)
    await expect(player.page.locator('.ribbon')).toHaveCount(0, { timeout: 120_000 })
    await expect.poll(async () => (await api('GET', '/incidents?status=resolved&per_page=100')).body.data.find((i: { screen: { id: string } | null; kind: string }) => i.screen?.id === screen.id && i.kind === 'device_unavailable')?.resolution ?? null,
      { timeout: 150_000, intervals: [10_000] }).toContain('Contact rétabli')
    const history = (await api('GET', `/screens/${screen.id}/status-history`)).body.data.map((h: { state: string }) => h.state)
    expect(history).toContain('offline')
    expect(player.errors).toEqual([])
  } finally {
    for (const [key, value] of Object.entries(original)) await api('PATCH', '/settings', { key, value, reason: 'Fin de la recette : délais rétablis' })
    await player.context.close()
    await cleanup(api, [screen], [campaign])
  }
})

test('scénario D : diffusion collective par groupe sur deux lecteurs', async ({ browser, request }) => {
  test.setTimeout(400_000)
  const api = await apiAs(request, 'alain.mbeng')
  const { contentId, locationId } = await contentAndLocation(api)
  const suffix = Date.now().toString().slice(-6)
  const screens = [await createScreen(api, `ECR-RCT-D1-${suffix}`, locationId), await createScreen(api, `ECR-RCT-D2-${suffix}`, locationId)]
  const group = await api('POST', '/screen-groups', { code: `G-RCT-${suffix}`, name: `Recette D ${suffix}`, screen_ids: screens.map((s) => s.id) })
  expect(group.status).toBe(201)
  const players = [await pairPlayer(browser, api, screens[0].id), await pairPlayer(browser, api, screens[1].id)]
  const campaign = await publishCampaign(api, `Recette D ${suffix}`, contentId, [{ type: 'group', id: group.body.data.id }])
  try {
    for (const p of players) await expect(p.page.locator('.text-zone h1')).toBeVisible({ timeout: 150_000 })
    await expect.poll(async () => {
      const rows: { played_at: string | null }[] = (await api('GET', `/campaigns/${campaign}/delivery`)).body.data.rows
      return rows.filter((r) => r.played_at).length
    }, { timeout: 180_000, intervals: [5000] }).toBe(2)
  } finally {
    for (const p of players) await p.context.close()
    await cleanup(api, screens, [campaign])
    await api('DELETE', `/screen-groups/${group.body.data.id}`)
  }
})

test('scénario F : actions non autorisées refusées par le serveur', async ({ request, playwright }) => {
  const admin = await apiAs(request, 'alain.mbeng')
  const screen = (await admin('GET', '/screens?per_page=1')).body.data[0]
  const device = (await admin('GET', '/screens?per_page=50')).body.data.find((s: { device: unknown }) => s.device)?.device
  const asUser = async (login: string): Promise<Api> => apiAs(await playwright.request.newContext() as APIRequestContext, login)
  // Agent sans droit sur le parc (rôle « agent »), auditeur en consultation seule.
  const agent = await asUser('loic.bouanga')
  for (const [method, path, body] of [
    ['GET', '/screens', undefined], ['GET', '/screens/overview', undefined], ['POST', '/screens', { code: 'ECR-PIRATE' }],
    ['POST', `/screens/${screen.id}/archive`, { reason: 'Tentative non autorisée' }], ['GET', '/supervision', undefined],
  ] as const) {
    expect((await agent(method, path, body)).status, `${method} ${path}`).toBe(403)
  }
  const auditor = await asUser('sylvain.essono')
  expect((await auditor('PATCH', `/screens/${screen.id}`, { name: 'X', expected_version: screen.version })).status).toBe(403)
  if (device) expect((await auditor('POST', `/devices/${device.id}/commands`, { command: 'sync' })).status).toBe(403)
  expect((await auditor('POST', '/screen-profiles', { code: 'X', name: 'X', settings: {} })).status).toBe(403)
  // Le lecteur : jeton forgé refusé.
  const forged = await request.get(`${PLAYER}/api/v1/device/manifest`, { headers: { Accept: 'application/json', Authorization: 'Bearer 999|jeton-forge' } })
  expect(forged.status()).toBe(401)
})
