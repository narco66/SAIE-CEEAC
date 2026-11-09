// Test de charge du parc (R15, PERF-01) : chaque lecteur simulé interroge son manifeste (If-None-Match) et envoie un heartbeat
// toutes les 30 s avec un aléa de 0 à 5 s, comme le vrai lecteur. Mesure p50/p95/p99 et erreurs par type d'appel.
// Usage : node infra/loadtest/devices.mjs <api> <fichier-jetons.json> <durée-secondes>
import { readFileSync } from 'node:fs'

const [api = 'http://127.0.0.1:8001', file, seconds = '180'] = process.argv.slice(2)
const tokens = JSON.parse(readFileSync(file, 'utf8'))
const end = Date.now() + Number(seconds) * 1000
const stats = { manifest: [], heartbeat: [] }
const errors = { manifest: 0, heartbeat: 0 }
let notModified = 0

async function timed(kind, fn) {
  const t = performance.now()
  try {
    const r = await fn()
    stats[kind].push(performance.now() - t)
    if (r.status === 304) notModified++
    else if (!r.ok) errors[kind]++
    await r.arrayBuffer().catch(() => undefined)
    return r
  } catch {
    errors[kind]++
  }
}

async function device(token, index) {
  let etag = null
  await new Promise((r) => setTimeout(r, (index / tokens.length) * 30000))
  while (Date.now() < end) {
    const headers = { Authorization: `Bearer ${token}`, Accept: 'application/json' }
    const m = await timed('manifest', () => fetch(`${api}/api/v1/device/manifest`, { headers: etag ? { ...headers, 'If-None-Match': etag } : headers }))
    if (m?.headers.get('ETag')) etag = m.headers.get('ETag')
    await timed('heartbeat', () => fetch(`${api}/api/v1/device/heartbeat`, { method: 'POST', headers: { ...headers, 'Content-Type': 'application/json' }, body: JSON.stringify({ device_time: new Date().toISOString(), app_version: 'load' }) }))
    await new Promise((r) => setTimeout(r, 30000 + Math.random() * 5000))
  }
}

const pct = (arr, p) => (arr.length ? arr.slice().sort((a, b) => a - b)[Math.min(arr.length - 1, Math.floor((p / 100) * arr.length))] : null)
await Promise.all(tokens.map(device))
const summary = Object.fromEntries(Object.entries(stats).map(([k, v]) => [k, { requests: v.length, errors: errors[k], p50_ms: Math.round(pct(v, 50)), p95_ms: Math.round(pct(v, 95)), p99_ms: Math.round(pct(v, 99)), max_ms: Math.round(Math.max(...v)) }]))
console.log(JSON.stringify({ devices: tokens.length, duration_s: Number(seconds), not_modified_304: notModified, ...summary }, null, 2))
