#!/usr/bin/env node
/*
 * Agent local de pilotage de la dalle (PLY-08, PLY-09) — à installer sur le lecteur, à côté du navigateur kiosque.
 * Écoute uniquement 127.0.0.1 ; le lecteur l'appelle pour allumer/éteindre la dalle et lire son état.
 *
 * Pilotes (variable SAIE_PANEL_DRIVER) :
 *   cec       : HDMI-CEC via « cec-client » (paquet libcec) ; adresse logique de l'écran : 0
 *   command   : commandes système configurées (RS-232, API constructeur…) :
 *               SAIE_PANEL_ON_CMD, SAIE_PANEL_OFF_CMD, SAIE_PANEL_STATUS_CMD (doit afficher on | off | standby)
 *   simulate  : essais sans matériel (état conservé en mémoire)
 *
 * Usage : SAIE_PANEL_DRIVER=cec node panel-agent.mjs        (port : SAIE_PANEL_PORT, défaut 7071)
 */
import { createServer } from 'node:http'
import { execFile, exec } from 'node:child_process'

const driver = process.env.SAIE_PANEL_DRIVER ?? 'simulate'
const port = Number(process.env.SAIE_PANEL_PORT ?? 7071)
// Origine du lecteur obligatoire hors simulation : aucune autre page ouverte sur le kiosque ne doit piloter la dalle.
const allowedOrigin = process.env.SAIE_PLAYER_ORIGIN ?? (driver === 'simulate' ? 'http://localhost:5174' : null)
if (!allowedOrigin) {
  console.error('SAIE_PLAYER_ORIGIN doit indiquer l’origine du lecteur (ex. https://saie.ceeac-eccas.org).')
  process.exit(1)
}
let simulated = 'on'
let lastError = null

const run = (command) => new Promise((resolve, reject) => exec(command, { timeout: 10_000 }, (error, stdout) => (error ? reject(error) : resolve(String(stdout)))))
const cec = (instruction) => new Promise((resolve, reject) => {
  const child = execFile('cec-client', ['-s', '-d', '1'], { timeout: 15_000 }, (error, stdout) => (error ? reject(error) : resolve(String(stdout))))
  child.stdin.end(`${instruction}\n`)
})

const drivers = {
  simulate: { on: async () => { simulated = 'on' }, off: async () => { simulated = 'standby' }, status: async () => simulated },
  cec: {
    on: () => cec('on 0'), off: () => cec('standby 0'),
    status: async () => { const out = await cec('pow 0'); return /power status: on/i.test(out) ? 'on' : /standby/i.test(out) ? 'standby' : 'unknown' },
  },
  command: {
    on: () => run(process.env.SAIE_PANEL_ON_CMD), off: () => run(process.env.SAIE_PANEL_OFF_CMD),
    status: async () => { const out = (await run(process.env.SAIE_PANEL_STATUS_CMD)).trim().toLowerCase(); return ['on', 'off', 'standby'].includes(out) ? out : 'unknown' },
  },
}
const impl = drivers[driver]
if (!impl) { console.error(`Pilote inconnu : ${driver}`); process.exit(1) }

const send = (res, status, body) => {
  res.writeHead(status, {
    'Content-Type': 'application/json', 'Access-Control-Allow-Origin': allowedOrigin, 'Access-Control-Allow-Methods': 'GET, POST, OPTIONS',
    'Access-Control-Allow-Headers': 'Content-Type', 'Access-Control-Allow-Private-Network': 'true', 'Cache-Control': 'no-store',
  })
  res.end(JSON.stringify(body))
}

createServer(async (req, res) => {
  if (req.method === 'OPTIONS') return send(res, 204, {})
  if (req.url !== '/panel') return send(res, 404, { error: 'not_found' })
  // Une commande ne vient que du lecteur : le navigateur envoie l'origine, que CORS seul ne suffit pas à filtrer.
  if (req.method === 'POST' && req.headers.origin !== allowedOrigin) return send(res, 403, { error: 'origine non autorisée' })
  try {
    if (req.method === 'POST') {
      let raw = ''
      for await (const chunk of req) raw += chunk
      const { power } = JSON.parse(raw || '{}')
      if (!['on', 'off'].includes(power)) return send(res, 422, { error: 'power doit valoir on ou off' })
      await impl[power]()
    }
    const state = await impl.status()
    lastError = null
    return send(res, 200, { driver, state })
  } catch (error) {
    lastError = String(error.message ?? error).slice(0, 200)
    return send(res, 500, { driver, state: 'unknown', error: lastError })
  }
}).listen(port, '127.0.0.1', () => console.log(`Agent dalle SAIE (${driver}) sur http://127.0.0.1:${port}/panel`))
