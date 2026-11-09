import { test } from '@playwright/test'
import { apiAs } from './helpers'
test('probe', async ({ request }) => {
  const who = process.env.WHO ?? 'nadia.kombila'
  const api = await apiAs(request, who)
  for (const p of (process.env.PATHS ?? '/tasks').split(',')) {
    const r = await api(process.env.METHOD ?? 'GET', p, process.env.DATA ? JSON.parse(process.env.DATA) : undefined)
    console.log(p, r.status, JSON.stringify(r.body).slice(0, Number(process.env.LEN ?? 1500)))
  }
})
