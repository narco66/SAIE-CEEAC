import { readFileSync } from 'node:fs'
import { expect, test } from '@playwright/test'
import { apiAs, login } from './helpers'

/**
 * Fonctions P1 pilotées par l'interface : sources dynamiques et comptes de service (V39), versions du lecteur (V40),
 * notifications push (V03), confirmation de présence et export iCalendar (V28). Données à noms uniques, nettoyées si possible.
 */
const stamp = () => `${Date.now()}`
const PRESENCE_SUBJECT = 'Réunion présence e2e'

/** Annule les occurrences à venir des réunions de test laissées par une exécution interrompue. */
async function cancelPresenceLeftovers(api: Awaited<ReturnType<typeof apiAs>>): Promise<void> {
  const mine = (await api('GET', `/reservations?scope=mine&when=future&per_page=100&q=${encodeURIComponent(PRESENCE_SUBJECT)}`)).body.data as { occurrences: { id: string; status: string; starts_at: string }[] }[]
  for (const occurrence of mine.flatMap((r) => r.occurrences)) {
    if (['pending', 'confirmed'].includes(occurrence.status) && new Date(occurrence.starts_at).getTime() > Date.now()) {
      expect((await api('POST', `/occurrences/${occurrence.id}/cancel`, { scope: 'this', reason: 'Nettoyage du test automatisé' })).status).toBe(200)
    }
  }
}

test('V39 : déclarer et tester une source dynamique, créer puis révoquer un compte de service', async ({ page }) => {
  const id = stamp()
  await login(page, 'alain.mbeng')
  await page.goto('/administration/parametres')
  await page.getByRole('tab', { name: /Sources dynamiques/ }).click()
  await page.getByRole('button', { name: 'Déclarer une source' }).click()
  await page.getByLabel('Code').fill(`e2e-${id}`)
  await page.getByLabel('Nom').fill(`Compte à rebours e2e ${id}`)
  await page.getByLabel('Type').selectOption('countdown')
  await page.getByLabel('Libellé affiché').fill('Sommet')
  await page.getByLabel('Échéance').fill('2027-01-15')
  await page.getByRole('button', { name: 'Enregistrer' }).click()
  const row = page.getByRole('row').filter({ hasText: `Compte à rebours e2e ${id}` })
  await expect(row).toBeVisible()
  await row.getByRole('button', { name: 'Tester' }).click()
  await expect(row).toContainText('Réussie')

  await page.getByRole('tab', { name: 'Intégrations' }).click()
  await page.getByRole('button', { name: 'Créer un compte' }).click()
  await page.getByLabel('Nom du compte').fill(`SI e2e ${id}`)
  await page.getByLabel(/Réunions du jour autorisées/).check()
  await page.getByLabel(/Motif/).fill('Essai automatisé de bout en bout')
  await page.getByRole('button', { name: 'Créer le compte' }).click()
  const token = page.getByRole('textbox', { name: 'Jeton d’accès' })
  await expect(token).toBeVisible()
  await expect(token).toHaveValue(/\|/)
  await page.getByRole('button', { name: 'J’ai copié le jeton' }).click()
  const account = page.getByRole('row').filter({ hasText: `SI e2e ${id}` })
  await account.getByRole('button', { name: 'Révoquer' }).click()
  await page.getByLabel(/Motif/).fill('Fin de l’essai automatisé')
  await page.getByRole('dialog').getByRole('button', { name: 'Révoquer' }).click()
  await expect(account).toContainText('Révoqué')
})

test('V40 : publier une version pilote du lecteur puis la retirer', async ({ page }) => {
  const version = `0.0.${stamp()}`
  await login(page, 'alain.mbeng')
  await page.goto('/supervision')
  await expect(page.getByRole('heading', { name: 'Versions du lecteur' })).toBeVisible()
  await page.getByRole('button', { name: 'Publier une version' }).click()
  await page.getByRole('textbox', { name: /^Version/ }).fill(version)
  await page.getByLabel(/Empreinte SHA-256/).fill('a'.repeat(64))
  await page.getByRole('dialog').getByRole('button', { name: 'Publier' }).click()
  const row = page.getByRole('row').filter({ hasText: version })
  await expect(row).toContainText('Pilote')
  await expect(row).toContainText('Active')
  await row.getByRole('button', { name: 'Retirer' }).click()
  await page.getByLabel(/Motif/).fill('Version d’essai automatisé')
  await page.getByRole('dialog').getByRole('button', { name: 'Retirer la version' }).click()
  await expect(row).toContainText('Retirée')
})

test('V03 : la section push explique pourquoi le canal est indisponible tant qu’il n’est pas activé', async ({ page }) => {
  await login(page, 'alain.mbeng')
  await page.goto('/mon-compte')
  const section = page.locator('section').filter({ has: page.getByRole('heading', { name: 'Notifications push' }) })
  await expect(section).toContainText('ne sont pas activées sur ce serveur')
})

test('V28 : export iCalendar et confirmation de présence par l’organisateur', async ({ page, request }) => {
  const loic = await apiAs(request, 'loic.bouanga')
  await cancelPresenceLeftovers(loic)
  const rooms = (await loic('GET', '/rooms?per_page=100')).body.data as { id: string; status: string; approval_mode: string }[]
  const candidates = rooms.filter((r) => r.status === 'active' && r.approval_mode === 'auto')
  const start = new Date(Date.now() + 10 * 60_000)
  start.setSeconds(0, 0)
  const end = new Date(start.getTime() + 60 * 60_000)
  let created: { status: number; body: any } = { status: 0, body: null }
  for (const room of candidates) {
    created = await loic('POST', '/reservations', {
      room_id: room.id, subject: `${PRESENCE_SUBJECT} ${stamp()}`, participant_count: 2, confidentiality: false,
      start_at: start.toISOString(), end_at: end.toISOString(),
    })
    if (created.status === 201) break
  }
  expect(created.status, JSON.stringify(created.body).slice(0, 300)).toBe(201)
  const reservation = created.body.data

  await login(page, 'loic.bouanga')
  await page.goto(`/reservations/${reservation.id}`)
  const ics = page.getByRole('link', { name: 'Ajouter à l’agenda' })
  await expect(ics).toHaveAttribute('href', `/api/v1/reservations/${reservation.id}/calendar.ics`)
  const [download] = await Promise.all([page.waitForEvent('download'), ics.click()])
  expect(download.suggestedFilename()).toMatch(/\.ics$/)
  const file = await download.path()
  const content = readFileSync(file, 'utf8')
  expect(content).toContain('BEGIN:VCALENDAR')
  expect(content).toContain('BEGIN:VEVENT')

  await page.getByRole('button', { name: 'Confirmer la présence' }).click()
  await page.getByRole('dialog').getByRole('button', { name: 'Confirmer la présence' }).click()
  await expect(page.getByText(/Présence confirmée à/)).toBeVisible()

  expect((await loic('POST', `/occurrences/${reservation.occurrences[0].id}/cancel`, { scope: 'this', reason: 'Nettoyage du test automatisé' })).status).toBe(200)
})

test('V08 : affecter un lecteur au canal pilote puis le remettre en stable', async ({ page, request }) => {
  const alain = await apiAs(request, 'alain.mbeng')
  const screens = (await alain('GET', '/screens?per_page=100')).body.data as { id: string; device: { status: string; release_channel?: string } | null }[]
  const screen = screens.find((s) => s.device?.status === 'active' && (s.device.release_channel ?? 'stable') === 'stable')
  expect(screen, 'un écran avec un lecteur actif sur le canal stable').toBeTruthy()

  await login(page, 'alain.mbeng')
  await page.goto(`/affichage/ecrans/${screen!.id}`)
  await page.getByRole('tab', { name: 'Lecteur' }).click()
  const channel = page.getByRole('combobox', { name: 'Canal de version du lecteur' })
  await expect(channel).toHaveValue('stable')
  await channel.selectOption('pilot')
  await expect(page.getByText('Canal de version modifié')).toBeVisible()
  await page.reload()
  await page.getByRole('tab', { name: 'Lecteur' }).click()
  await expect(channel).toHaveValue('pilot')
  await channel.selectOption('stable')
  await expect.poll(async () => (await alain('GET', `/screens/${screen!.id}`)).body.data.device.release_channel).toBe('stable')
})
