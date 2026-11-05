import { expect, test } from '@playwright/test'
import { apiAs, login } from './helpers'

/**
 * Exigences P2 pilotées par l'interface : mode événement (PUB-12), diffusion d'un document par courriel (DOC-09),
 * pause-café et restauration (SAL-15). Données à noms uniques, nettoyées en fin de test.
 */
const stamp = () => `${Date.now()}`
const day = (n: number) => new Date(Date.now() + n * 86400000).toLocaleDateString('sv-SE', { timeZone: 'Africa/Libreville' })

test('PUB-12 : un mode événement de plus de 31 jours est refusé, puis enregistré et repéré dans la liste (brouillon : jamais diffusé)', async ({ page, request }) => {
  const name = `Sommet e2e ${stamp()}`
  await login(page, 'patrick.obiang')
  await page.goto('/affichage/campagnes/nouvelle')
  await page.getByLabel('Nom de la campagne').fill(name)
  const row = page.getByRole('row').filter({ hasText: /FR rév\./ }).filter({ has: page.getByRole('button', { name: 'Ajouter' }) }).first()
  await row.getByRole('button', { name: 'Ajouter' }).click()
  await page.getByRole('button', { name: /^Continuer/ }).click()
  await page.locator('.pub-select-list label').filter({ hasText: 'Français' }).getByRole('checkbox').check()
  await page.getByRole('button', { name: /^Continuer/ }).click()
  await page.locator('.pub-select-list label').filter({ hasText: 'Écrans internes' }).getByRole('checkbox').check()
  await page.getByRole('button', { name: /^Continuer/ }).click()
  await expect(page.getByRole('heading', { name: 'Calendrier', exact: true, level: 3 })).toBeVisible()
  await page.getByRole('radio', { name: /Mode événement/ }).check()
  await expect(page.getByText(/remplace tout le programme ordinaire/)).toBeVisible()
  await page.getByLabel('Début — date').fill(day(1))
  await page.getByLabel('Fin — date').fill(day(40))
  await page.getByRole('button', { name: /^Continuer/ }).click()
  await page.getByRole('button', { name: 'Enregistrer en brouillon' }).click()
  await expect(page.getByText(/au plus 31 jours/)).toBeVisible()
  await expect(page.getByRole('heading', { name: 'Calendrier', exact: true, level: 3 })).toBeVisible()

  await page.getByLabel('Fin — date').fill(day(3))
  await page.getByRole('button', { name: /^Continuer/ }).click()
  await page.getByRole('button', { name: 'Enregistrer en brouillon' }).click()
  await expect(page).toHaveURL(/\/affichage\/campagnes\/[^/]+\/modifier/)
  const id = page.url().match(/campagnes\/([^/]+)\/modifier/)![1]
  const api = await apiAs(request, 'patrick.obiang')
  expect((await api('GET', `/campaigns/${id}`)).body.data.kind).toBe('event')

  await page.goto('/affichage/campagnes')
  await page.getByPlaceholder('Rechercher par nom ou référence…').fill(name)
  await expect(page.getByRole('row').filter({ hasText: name }).getByText('Mode événement')).toBeVisible()
})

test('DOC-09 : diffuser un document publié par courriel à sa structure et le retrouver dans l’historique', async ({ page, request }) => {
  const nadia = await apiAs(request, 'nadia.kombila')
  const me = (await nadia('GET', '/me')).body.data
  const published = (await nadia('GET', '/documents?status=published&per_page=50')).body.data as { id: string }[]
  let documentId: string | undefined
  for (const doc of published) {
    if ((await nadia('GET', `/documents/${doc.id}`)).body.data.can_manage) { documentId = doc.id; break }
  }
  expect(documentId, 'un document publié géré par la contributrice').toBeTruthy()

  await login(page, 'nadia.kombila')
  await page.goto(`/documents/${documentId}`)
  await page.getByRole('button', { name: 'Diffuser par courriel' }).click()
  const dialog = page.getByRole('dialog', { name: 'Diffuser par courriel' })
  await expect(dialog.getByText(/Aucune pièce jointe n’est envoyée/)).toBeVisible()
  await dialog.getByRole('checkbox', { name: me.structure.name }).check()
  await dialog.getByLabel('Message d’accompagnement').fill('Diffusion de test automatisé.')
  await dialog.getByRole('button', { name: 'Envoyer' }).click()
  await expect(dialog).toHaveCount(0)
  await expect(page.getByText('Document diffusé')).toBeVisible()
  const history = page.locator('section').filter({ has: page.getByRole('heading', { name: 'Diffusions par courriel' }) })
  await expect(history.getByText(/destinataire\(s\)/).first()).toBeVisible()
  await expect(history.getByText(me.structure.name).first()).toBeVisible()
})

test('SAL-15 : couverts et heure de service exigés, contrôlés dans le créneau, puis visibles par les prestations', async ({ page, request }) => {
  await login(page, 'loic.bouanga')
  await page.goto('/reservations/nouvelle')
  await page.getByLabel('Objet interne').fill(`Atelier restauration e2e ${stamp()}`)
  await page.getByLabel('Date').fill(day(12))
  await page.getByLabel('Participants').fill('12')
  await page.getByLabel('Début').fill('09:00')
  await page.getByLabel('Fin').fill('11:00')
  await page.getByRole('checkbox', { name: 'Pause-café et restauration' }).check()
  await expect(page.getByLabel('Nombre de couverts')).toHaveValue('12')
  await page.getByLabel('Heure de service').fill('13:30')
  await page.getByRole('button', { name: 'Continuer' }).click()
  await expect(page.getByText(/L’heure doit se situer pendant la réunion \(09:00–11:00\)/)).toBeVisible()
  await page.getByLabel('Heure de service').fill('10:15')
  await page.getByRole('button', { name: 'Continuer' }).click()
  await expect(page.getByText(/L’heure doit se situer pendant la réunion/)).toHaveCount(0)

  const loic = await apiAs(request, 'loic.bouanga')
  const refs = (await loic('GET', '/referentials')).body.data
  const catering = refs.service_types.find((s: { code: string }) => s.code === 'RESTAURATION')
  const rooms = (await loic('GET', '/rooms?per_page=100')).body.data as { id: string; status: string; capacity: number }[]
  let created = { status: 0, body: null as any }
  for (const room of rooms.filter((r) => r.status === 'active' && r.capacity >= 12)) {
    for (let offset = 12; offset < 40 && created.status !== 201; offset++) {
      const date = day(offset)
      if ([0, 6].includes(new Date(`${date}T12:00:00Z`).getUTCDay())) continue
      created = await loic('POST', '/reservations', {
        room_id: room.id, start_at: `${date}T09:00:00+01:00`, end_at: `${date}T11:00:00+01:00`, confidentiality: false, subject: `Atelier restauration e2e ${stamp()}`, participant_count: 12,
        services: [{ service_type_id: catering.id, headcount: 12, serve_time: '10:15', details: 'Deux repas sans gluten' }],
      })
    }
    if (created.status === 201) break
  }
  expect(created.status, JSON.stringify(created.body).slice(0, 300)).toBe(201)
  const reservation = created.body.data

  await page.goto(`/reservations/${reservation.id}`)
  await expect(page.getByText('Pause-café et restauration · 12 couverts à 10:15').first()).toBeVisible()
  expect((await loic('POST', `/occurrences/${reservation.occurrences[0].id}/cancel`, { scope: 'this', reason: 'Nettoyage du test automatisé' })).status).toBe(200)
})
