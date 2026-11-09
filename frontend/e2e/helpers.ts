import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { expect, type APIRequestContext, type Page } from '@playwright/test'

const accounts = readFileSync(resolve(process.cwd(), '../backend/storage/app/private/demo-accounts.txt'), 'utf8')
export const PASSWORD = process.env.E2E_PASSWORD ?? accounts.match(/démonstration : (\S+)/)?.[1] ?? ''
export const email = (login: string) => `${login}@demo.saie-ceeac.local`
/** Identifiant de démonstration déduit du nom affiché (« Jean Moussavou » → « jean.moussavou »). */
export const demoLogin = (name: string) => name.normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase().trim().replace(/\s+/g, '.')

export async function login(page: Page, login: string): Promise<void> {
  await page.goto('/connexion')
  await page.getByLabel('Identifiant professionnel').fill(email(login))
  await page.locator('input[autocomplete="current-password"]').fill(PASSWORD)
  await page.getByRole('button', { name: 'Se connecter' }).click()
  await expect(page).toHaveURL(/\/accueil/)
}

/** Client API authentifié par session, comme la SPA (cookie + jeton CSRF). */
export async function apiAs(request: APIRequestContext, login: string): Promise<(method: string, path: string, data?: unknown) => Promise<{ status: number; body: any }>> {
  const origin = process.env.E2E_BASE_URL ?? 'http://localhost:5173'
  await request.get(`${origin}/sanctum/csrf-cookie`)
  const xsrf = async () => decodeURIComponent((await request.storageState()).cookies.find((c) => c.name === 'XSRF-TOKEN')?.value ?? '')
  const headers = async () => ({ Accept: 'application/json', Referer: `${origin}/`, 'X-XSRF-TOKEN': await xsrf() })
  const r = await request.post(`${origin}/api/v1/auth/login`, { headers: await headers(), data: { email: email(login), password: PASSWORD } })
  expect(r.status()).toBe(200)
  return async (method, path, data) => {
    const response = await request.fetch(`${origin}/api/v1${path}`, { method, headers: await headers(), data })
    const text = await response.text()
    return { status: response.status(), body: text ? JSON.parse(text) : null }
  }
}

type Api = (method: string, path: string, data?: unknown) => Promise<{ status: number; body: any }>

/**
 * Retrait d'un écran créé par un test : lecteur révoqué, incidents ouverts de cet écran clos avec motif (la surveillance
 * en ouvre, par exemple « aucun programme » sur un écran de test sans campagne), puis archivage. Rien n'est supprimé.
 */
export async function retireScreen(api: Api, screenId: string, reason: string): Promise<void> {
  const screen = (await api('GET', `/screens/${screenId}`)).body.data
  if (screen.device && screen.device.status === 'active') {
    expect((await api('POST', `/devices/${screen.device.id}/revoke`, { reason })).status).toBe(200)
  }
  const open = (await api('GET', `/incidents?screen_id=${screenId}&status=open,acknowledged&per_page=100`)).body.data as { id: string }[]
  for (const incident of open) {
    expect((await api('PATCH', `/incidents/${incident.id}`, { action: 'resolve', note: `${reason} : écran de test retiré.` })).status).toBe(200)
  }
  const fresh = (await api('GET', `/screens/${screenId}`)).body.data
  expect((await api('POST', `/screens/${screenId}/archive`, { reason, expected_version: fresh.version })).status).toBe(200)
}
