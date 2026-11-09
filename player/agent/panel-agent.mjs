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
 * Redémarrage de l'appareil (commande à distance « reboot_device », §11) : proposé seulement si SAIE_REBOOT_CMD est défini
 * (ex. « systemctl reboot » avec la règle sudo correspondante). En simulation, il est simulé et le signale.
 *
 * Usage : SAIE_PANEL_DRIVER=cec node panel-agent.mjs        (port : SAIE_PANEL_PORT, défaut 7071)
 */
import { createServer } from 'node:http'
import { execFile, exec } from 'node:child_process'
import os from 'node:os'
import { statfs, readFile, writeFile } from 'node:fs/promises'

const AGENT_VERSION = '1.2.0'
// Jeton de l'agent conservé hors des sources, dans le dossier de l'utilisateur système qui exécute l'agent (droits 600).
const STATE_FILE = process.env.SAIE_AGENT_STATE ?? `${os.homedir()}/.saie-agent.json`
let credentials = null
let playerContactAt = null
try { credentials = JSON.parse(await readFile(STATE_FILE, 'utf8')) } catch { credentials = null }

/**
 * §10.16 : battement propre de l'agent, envoyé au serveur avec le jeton « agent » remis par le lecteur. Le serveur
 * distingue ainsi une application de lecture arrêtée (agent joignable) d'une perte de réseau ou d'alimentation.
 */
async function agentBeat() {
  if (!credentials?.server || !credentials?.token) return
  try {
    const s = await system()
    await fetch(`${credentials.server}/api/v1/device/agent-heartbeat`, {
      method: 'POST', headers: { 'Content-Type': 'application/json', Accept: 'application/json', Authorization: `Bearer ${credentials.token}` },
      body: JSON.stringify({ agent_version: AGENT_VERSION, player_contact_at: playerContactAt, uptime_s: s.uptime_s, disk_free_gb: s.disk_free_gb, mem_free_gb: s.mem_free_gb, temperature_c: s.temperature_c }),
      signal: AbortSignal.timeout(5000),
    })
  } catch { /* serveur injoignable : nouvel essai à la minute suivante */ }
}
setInterval(agentBeat, 30_000)
const DISK_PATH = process.env.SAIE_DISK_PATH ?? (process.platform === 'win32' ? 'C:\\' : '/')

/**
 * Télémétrie matérielle (§9.1, §10.2) : ce que le navigateur ne peut pas lire. Une mesure indisponible vaut null,
 * jamais une valeur inventée (température : capteur Linux thermal_zone0 seulement).
 */
async function system() {
  const gb = (n) => Math.round((n / 1073741824) * 10) / 10
  let disk = null
  try {
    const s = await statfs(DISK_PATH)
    disk = { total_gb: gb(s.blocks * s.bsize), free_gb: gb(s.bavail * s.bsize) }
  } catch { disk = null }
  let temperature = null
  try {
    temperature = Math.round(Number(await readFile('/sys/class/thermal/thermal_zone0/temp', 'utf8')) / 100) / 10
  } catch { temperature = null }
  const nic = Object.values(os.networkInterfaces()).flat().find((i) => i && !i.internal && i.family === 'IPv4')
  const cpus = os.cpus()
  return {
    agent_version: AGENT_VERSION, hostname: os.hostname(), os: `${os.type()} ${os.release()} (${os.arch()})`,
    mac: nic?.mac?.toUpperCase() ?? null, ip: nic?.address ?? null, cpu_count: cpus.length,
    cpu_load: process.platform === 'win32' ? null : Math.round((os.loadavg()[0] / Math.max(1, cpus.length)) * 100),
    mem_total_gb: gb(os.totalmem()), mem_free_gb: gb(os.freemem()), uptime_s: Math.round(os.uptime()),
    disk_total_gb: disk?.total_gb ?? null, disk_free_gb: disk?.free_gb ?? null, temperature_c: temperature, simulated: driver === 'simulate',
  }
}

const driver = process.env.SAIE_PANEL_DRIVER ?? 'simulate'
const port = Number(process.env.SAIE_PANEL_PORT ?? 7071)
// Origine du lecteur obligatoire hors simulation : aucune autre page ouverte sur le kiosque ne doit piloter la dalle.
const allowedOrigin = process.env.SAIE_PLAYER_ORIGIN ?? (driver === 'simulate' ? 'http://localhost:5174' : null)
if (!allowedOrigin) {
  console.error('SAIE_PLAYER_ORIGIN doit indiquer l’origine du lecteur (ex. https://saie.ceeac-eccas.org).')
  process.exit(1)
}
let simulated = 'on'
const rebootCommand = process.env.SAIE_REBOOT_CMD ?? null
const canReboot = Boolean(rebootCommand) || driver === 'simulate'
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
  // Télémétrie en lecture seule, réservée à l'origine du lecteur.
  if (req.url === '/system' && req.method === 'GET') {
    if (req.headers.origin && req.headers.origin !== allowedOrigin) return send(res, 403, { error: 'origine non autorisée' })
    playerContactAt = new Date().toISOString()
    return send(res, 200, await system())
  }
  // Identifiants de l'agent, remis par le lecteur (origine du lecteur exigée, adresse du serveur en http(s)).
  if (req.url === '/credentials' && req.method === 'POST') {
    if (req.headers.origin !== allowedOrigin) return send(res, 403, { error: 'origine non autorisée' })
    let body = ''
    for await (const chunk of req) { body += chunk; if (body.length > 4096) return send(res, 413, { error: 'trop volumineux' }) }
    try {
      const { server, token } = JSON.parse(body)
      if (!/^https?:\/\/[^\s/]+/.test(server) || typeof token !== 'string' || token.length > 200) return send(res, 422, { error: 'identifiants invalides' })
      credentials = { server: server.replace(/\/$/, ''), token }
      await writeFile(STATE_FILE, JSON.stringify(credentials), { mode: 0o600 })
      void agentBeat()
      return send(res, 200, { ok: true })
    } catch { return send(res, 422, { error: 'identifiants invalides' }) }
  }
  if (req.url !== '/panel') return send(res, 404, { error: 'not_found' })
  // Une commande ne vient que du lecteur : le navigateur envoie l'origine, que CORS seul ne suffit pas à filtrer.
  if (req.method === 'POST' && req.headers.origin !== allowedOrigin) return send(res, 403, { error: 'origine non autorisée' })
  try {
    if (req.method === 'POST') {
      let raw = ''
      for await (const chunk of req) raw += chunk
      const { power, action } = JSON.parse(raw || '{}')
      if (action === 'reboot') {
        if (!canReboot) return send(res, 422, { error: 'redémarrage non configuré (SAIE_REBOOT_CMD)' })
        // Réponse envoyée avant le redémarrage : le lecteur confirmera l'exécution à sa relance.
        send(res, 202, { driver, reboot: 'scheduled', simulated: !rebootCommand })
        if (rebootCommand) setTimeout(() => run(rebootCommand).catch((e) => console.error(e)), 1500)
        return
      }
      if (!['on', 'off'].includes(power)) return send(res, 422, { error: 'power doit valoir on ou off' })
      await impl[power]()
    }
    const state = await impl.status()
    lastError = null
    return send(res, 200, { driver, state, capabilities: ['panel_power', ...(canReboot ? ['reboot_device'] : [])], simulated: driver === 'simulate' })
  } catch (error) {
    lastError = String(error.message ?? error).slice(0, 200)
    return send(res, 500, { driver, state: 'unknown', error: lastError })
  }
}).listen(port, '127.0.0.1', () => console.log(`Agent dalle SAIE (${driver}) sur http://127.0.0.1:${port}/panel`))
