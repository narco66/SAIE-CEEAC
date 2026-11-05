// Temps de service d'une requête isolée (sans concurrence) : manifeste complet, manifeste conditionnel (304), heartbeat.
import { readFileSync } from 'node:fs'
const [api = 'http://127.0.0.1:8001', file] = process.argv.slice(2)
const token = JSON.parse(readFileSync(file, 'utf8'))[0]
const headers = { Authorization: `Bearer ${token}`, Accept: 'application/json' }
const time = async (fn) => { const t = performance.now(); const r = await fn(); await r.arrayBuffer(); return [Math.round(performance.now() - t), r] }
const runs = { full: [], conditional: [], heartbeat: [] }
for (let i = 0; i < 10; i++) {
  const [a, r] = await time(() => fetch(`${api}/api/v1/device/manifest`, { headers }))
  runs.full.push(a)
  const [b] = await time(() => fetch(`${api}/api/v1/device/manifest`, { headers: { ...headers, 'If-None-Match': r.headers.get('ETag') } }))
  runs.conditional.push(b)
  const [c] = await time(() => fetch(`${api}/api/v1/device/heartbeat`, { method: 'POST', headers: { ...headers, 'Content-Type': 'application/json' }, body: JSON.stringify({ device_time: new Date().toISOString() }) }))
  runs.heartbeat.push(c)
}
const med = (a) => a.slice().sort((x, y) => x - y)[Math.floor(a.length / 2)]
console.log(JSON.stringify(Object.fromEntries(Object.entries(runs).map(([k, v]) => [k, { median_ms: med(v), max_ms: Math.max(...v) }]))))
