import { expect, test } from '@playwright/test'
import { apiAs, login, retireScreen } from './helpers'

const PLAYER = process.env.E2E_PLAYER_URL ?? 'http://localhost:5174'
const AGENT = process.env.E2E_PANEL_AGENT ?? 'http://127.0.0.1:7071/panel'

/**
 * Module « Gestion des écrans », scénario C (commande distante), avec le lecteur réel (application /player) dans Chromium.
 * Veille, réveil et redémarrage de l'appareil passent par l'agent local de la dalle, lancé en mode SIMULATION
 * (SAIE_PANEL_DRIVER=simulate) : ces trois résultats sont des validations logicielles, pas matérielles.
 * L'écran de test est créé pour le parcours (plage 7 j/7), puis archivé : sa fiche et son historique restent consultables.
 */
test('commandes à distance : capacités déclarées, cycle d’états confirmé par le lecteur, journal et interface', async ({ browser, request, page }) => {
  test.setTimeout(420_000)
  const agent = await request.get(AGENT).catch(() => null)
  test.skip(!agent?.ok(), `Agent local de la dalle injoignable (${AGENT}) : lancer « node player/agent/panel-agent.mjs » en simulation`)
  const api = await apiAs(request, 'alain.mbeng')
  const refs = (await api('GET', '/referentials')).body.data
  const location = refs.locations.find((l: { zone: { code: string } | null }) => l.zone?.code !== 'Z-PUBLIC') ?? refs.locations[0]
  const code = `ECR-E2E-CMD-${Date.now().toString().slice(-6)}`
  const created = await api('POST', '/screens', {
    code, name: 'Écran de test des commandes (E2E)', kind: 'display', location_id: location.id, orientation: 'landscape', resolution: '1920x1080', control_channel: 'cec',
    operating_window: { days: [1, 2, 3, 4, 5, 6, 7], on: '00:00', off: '23:59' }, window_override_reason: 'Écran de test automatisé, actif en continu',
  })
  expect(created.status).toBe(201)
  const screen = created.body.data

  const context = await browser.newContext({ viewport: { width: 1280, height: 720 }, timezoneId: 'Africa/Libreville' })
  const player = await context.newPage()
  const errors: string[] = []
  player.on('pageerror', (e) => errors.push(e.message))
  await player.goto(PLAYER)
  const codeBox = player.locator('.code')
  await expect(codeBox).toBeVisible({ timeout: 30_000 })
  const pairingCode = (await codeBox.getAttribute('aria-label'))!.replace(/.*\s/, '')
  const paired = await api('POST', '/devices/pair', { code: pairingCode, screen_id: screen.id })
  expect(paired.status).toBe(201)
  const deviceId = paired.body.data.id

  // RG-006 : le lecteur déclare ses capacités ; l'agent (simulé) ajoute veille et redémarrage de l'appareil.
  const commands = () => api('GET', `/devices/${deviceId}/commands?per_page=30`)
  await expect.poll(async () => (await commands()).body.meta.capabilities ?? [], { timeout: 120_000, intervals: [3000] }).toEqual(expect.arrayContaining(['logs', 'stop_playback', 'panel_power', 'reboot_device']))

  const run = async (command: string, reason?: string) => {
    const sent = await api('POST', `/devices/${deviceId}/commands`, { command, reason })
    expect(sent.status, `${command} : ${JSON.stringify(sent.body)}`).toBe(201)
    expect(sent.body.data.status).toBe('pending')
    const id = sent.body.data.id
    await expect.poll(async () => (await commands()).body.data.find((c: { id: string }) => c.id === id)?.status, { timeout: 120_000, intervals: [2000] }).toMatch(/^(executed|failed)$/)
    return (await commands()).body.data.find((c: { id: string }) => c.id === id)
  }

  const logs = await run('logs')
  expect(logs.status).toBe('executed')
  expect(logs.received_at).not.toBeNull()
  expect(logs.result_data.app_version).toBeTruthy()

  const stopped = await run('stop_playback', 'Test E2E : arrêt de diffusion')
  expect(stopped.status).toBe('executed')
  await expect(player.locator('.stage.off')).toBeVisible({ timeout: 10_000 })
  expect((await commands()).body.meta.playback_paused).toBe(true)
  expect((await run('resume_playback')).status).toBe('executed')
  await expect(player.locator('.stage.off')).toHaveCount(0, { timeout: 10_000 })

  const standby = await run('standby', 'Test E2E : veille simulée')
  expect(standby.status).toBe('executed')
  expect(standby.result_data.panel_state).toBe('standby')
  expect((await run('wake')).status).toBe('executed')

  // Relance de l'application : exécution confirmée seulement au retour du lecteur.
  const restarted = await run('restart_player', 'Test E2E : relance')
  expect(restarted.status).toBe('executed')
  expect(restarted.result_message).toContain('Application relancée')
  const rebooted = await run('reboot_device', 'Test E2E : redémarrage simulé')
  expect(rebooted.status).toBe('executed')
  expect(rebooted.result_message).toContain('simulation')

  // Interface : historique des commandes et états dans la fiche écran.
  await page.setViewportSize({ width: 1440, height: 1000 })
  await login(page, 'alain.mbeng')
  await page.goto(`/affichage/ecrans/${screen.id}`)
  await page.getByRole('tab', { name: 'Lecteur' }).click()
  const panel = page.getByRole('region', { name: 'Commandes à distance' })
  await expect(panel).toBeVisible()
  await expect(panel.getByRole('cell', { name: /Consulter les journaux/ })).toBeVisible()
  await expect(panel.getByText('Exécutée').first()).toBeVisible()
  await expect(panel.getByRole('button', { name: 'Reprendre la diffusion' })).toBeDisabled()
  await page.screenshot({ path: '../docs/AUDIT-GESTION-ECRANS/captures/commandes-a-distance.png', fullPage: true })

  // Nettoyage : lecteur révoqué et écran archivé (fiche conservée).
  await retireScreen(api, screen.id, 'Fin du test E2E des commandes')
  // Journalisation (RG-007) : chaque commande est tracée avec son compte rendu.
  // Journal consulté par l'auditeur (permission « audit.view »), après le nettoyage effectué par l'administrateur.
  const auditor = await apiAs(request, 'sylvain.essono')
  const audit = await auditor('GET', `/audit-logs?action=device.command_executed&object_id=${deviceId}&per_page=50`)
  expect(audit.status).toBe(200)
  expect(audit.body.data.length).toBeGreaterThanOrEqual(7)

  expect(errors).toEqual([])
  await context.close()
})
