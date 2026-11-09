import { expect, test } from '@playwright/test'
import { apiAs, demoLogin, login } from './helpers'

/** P01 (validation par langue, séparation auteur/validateur) et P03 (réservation, conflit, PDF01). */
test('un validateur distinct approuve la version FR depuis sa tâche ; l’auteure ne peut pas valider', async ({ page, request, browser }) => {
  const nadia = await apiAs(request, 'nadia.kombila')
  const refs = (await nadia('GET', '/referentials')).body.data
  const title = `Note e2e ${Date.now()}`
  const created = await nadia('POST', '/contents', { title, category_id: refs.categories[0].id, classification: 'interne', expires_at: new Date(Date.now() + 5 * 86400000).toISOString(), languages: { fr: { title: 'Réunion e2e', body: 'Texte court.' }, en: { title: 'E2E meeting', body: 'Short text.' } } })
  expect(created.status).toBe(201)
  expect((await nadia('POST', `/contents/${created.body.data.id}/submit`, { languages: ['fr'] })).status).toBe(200)
  // Le validateur est désigné par le serveur (habilitation, périmètre, charge) : on suit l'affectation réelle.
  const approvals = (await nadia('GET', `/contents/${created.body.data.id}`)).body.data.approvals as { lang: string; status: string; assignee: { name: string } | null }[]
  const assignee = approvals.find((a) => a.lang === 'fr' && a.status === 'open')?.assignee
  expect(assignee, 'une tâche de validation FR est affectée').toBeTruthy()
  const validator = demoLogin(assignee!.name)
  expect(validator).not.toBe('nadia.kombila')
  await login(page, validator)
  await page.goto('/mes-taches')
  const task = page.getByRole('row').filter({ hasText: title }).filter({ hasText: 'FR' })
  await expect(task).toBeVisible()
  await task.getByRole('button', { name: /Ouvrir/ }).click()
  await expect(page).toHaveURL(/validation\?lang=fr/)
  await page.getByRole('radio', { name: /Approuver la version/ }).click()
  await page.getByRole('button', { name: 'Approuver', exact: true }).click()
  const api = await apiAs(request, validator)
  const content = created.body.data
  await expect.poll(async () => (await api('GET', `/contents/${content.id}`)).body.data.languages.find((l: { lang: string }) => l.lang === 'fr').status).toBe('approved')

  const author = await browser.newPage()
  await login(author, 'nadia.kombila')
  await author.goto(`/affichage/contenus/${content.id}/validation?lang=en`)
  await expect(author.getByRole('button', { name: /^Approuver/ })).toHaveCount(0)
  await author.close()
})

test('réservation par l’interface puis conflit serveur sans titre du tiers et confirmation PDF01', async ({ page, request }) => {
  const api = await apiAs(request, 'loic.bouanga')
  const rooms = await api('GET', '/rooms?q=Congo')
  const room = rooms.body.data[0]
  // Recherche d'un créneau libre : un créneau déjà pris est légitimement refusé (409) par le serveur.
  let first = { status: 0, body: null as any }
  let date = ''
  for (let offset = 9; offset < 60 && first.status !== 201; offset++) {
    const day = new Date(Date.now() + offset * 86400000)
    if ([0, 6].includes(day.getDay())) continue
    date = day.toISOString().slice(0, 10)
    first = await api('POST', '/reservations', { room_id: room.id, start_at: `${date}T13:00:00+01:00`, end_at: `${date}T14:00:00+01:00`, confidentiality: true, subject: 'Séance restreinte e2e', participant_count: 6 })
    if (first.status !== 201) expect(first.status).toBe(409)
  }
  expect(first.status).toBe(201)

  const clash = await api('POST', '/reservations', { room_id: room.id, start_at: `${date}T13:30:00+01:00`, end_at: `${date}T14:30:00+01:00`, confidentiality: false, subject: 'Tentative concurrente', participant_count: 4 })
  expect(clash.status).toBe(409)
  expect(clash.body.code).toBe('BOOKING_CONFLICT')
  expect(JSON.stringify(clash.body)).not.toContain('Séance restreinte')

  await login(page, 'loic.bouanga')
  await page.goto(`/reservations/${first.body.data.id}`)
  await expect(page.getByText('Séance restreinte e2e').first()).toBeVisible()
  const exp = await api('POST', '/exports', { type: 'reservation_confirmation', format: 'pdf', params: { reservation_id: first.body.data.id } })
  expect(exp.status).toBe(202)
  await expect.poll(async () => (await api('GET', `/exports/${exp.body.data.id}`)).body.data.status, { timeout: 60_000 }).toBe('ready')

  const other = await apiAs(request, 'nadia.kombila')
  expect((await other('GET', `/reservations/${first.body.data.id}`)).status).toBe(404)
  await api('POST', `/occurrences/${first.body.data.occurrences[0].id}/cancel`, { scope: 'this', reason: 'Nettoyage du test e2e' })
})
