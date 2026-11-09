#!/usr/bin/env node
/**
 * Bloc 4 — diagnostic matériel en lecture seule, à lancer sur le poste du lecteur, agent démarré :
 *   node agent/diagnose.mjs [--origin http://localhost:5174] [--agent http://127.0.0.1:7071]
 *
 * Il interroge l'agent local (état de la dalle, télémétrie) SANS changer l'état de la dalle, et indique pour chaque
 * fonction du cahier des charges si elle peut être validée sur ce poste, et comment. Il ne déclare rien « validé » :
 * la validation reste un essai réel (veille, réveil, redémarrage) suivi du parcours `screens-commands.spec.ts`.
 */
const arg = (name, fallback) => {
  const i = process.argv.indexOf(`--${name}`)
  return i > -1 ? process.argv[i + 1] : fallback
}
const origin = arg('origin', process.env.SAIE_PLAYER_ORIGIN ?? 'http://localhost:5174')
const agent = arg('agent', 'http://127.0.0.1:7071')

async function get(path) {
  try {
    const response = await fetch(`${agent}${path}`, { headers: { Origin: origin }, signal: AbortSignal.timeout(20_000) })
    return { ok: response.ok, status: response.status, body: await response.json().catch(() => null) }
  } catch (error) {
    return { ok: false, status: 0, body: { error: String(error.message ?? error) } }
  }
}

const panel = await get('/panel')
const system = await get('/system')
const s = system.body ?? {}
const p = panel.body ?? {}
const simulated = Boolean(p.simulated ?? s.simulated)
const rows = [
  ['Agent joignable', system.ok || panel.ok, system.ok ? `v${s.agent_version} sur ${s.hostname}` : `aucune réponse (${system.body?.error ?? system.status})`],
  ['Pilote de dalle', panel.ok && !simulated, panel.ok ? `${p.driver}${simulated ? ' (SIMULATION : rien n’est piloté)' : ''} · état lu : ${p.state}` : `indisponible (${p.error ?? panel.status})`],
  ['Lecture de l’état de la dalle', panel.ok && !simulated && p.state !== 'unknown', panel.ok ? `état « ${p.state} »` : '—'],
  ['Redémarrage de l’appareil', Array.isArray(p.capabilities) && p.capabilities.includes('reboot_device') && !simulated, 'exige SAIE_REBOOT_CMD et le droit système'],
  ['Disque réel', s.disk_total_gb != null, s.disk_total_gb != null ? `${s.disk_free_gb} Go libres / ${s.disk_total_gb} Go` : 'non mesuré'],
  ['Mémoire', s.mem_total_gb != null, s.mem_total_gb != null ? `${s.mem_free_gb} Go libres / ${s.mem_total_gb} Go` : 'non mesurée'],
  ['Charge processeur', s.cpu_load != null, s.cpu_load != null ? `${s.cpu_load} %` : 'non mesurée sur ce système (Windows)'],
  ['Température', s.temperature_c != null, s.temperature_c != null ? `${s.temperature_c} °C` : 'capteur absent (Linux thermal_zone0 requis)'],
  ['Adresse MAC et système', Boolean(s.mac && s.os), `${s.mac ?? '—'} · ${s.os ?? '—'}`],
]
const width = Math.max(...rows.map((r) => r[0].length))
console.log(`Diagnostic matériel SAIE — ${new Date().toISOString()} — agent ${agent}, origine ${origin}\n`)
for (const [label, ok, detail] of rows) console.log(`${ok ? 'DISPONIBLE ' : 'À FOURNIR  '} ${label.padEnd(width)}  ${detail}`)
console.log(`
Étapes de validation (à faire avec un écran réel, hors heures de diffusion) :
  1. Démarrer l'agent avec SAIE_PANEL_DRIVER=cec (ou command) et SAIE_PLAYER_ORIGIN=<origine du lecteur>.
  2. Déclarer le canal de pilotage de l'écran (fiche écran › Fonctionnement) puis lancer, depuis l'administration,
     les commandes « Mettre en veille », « Réveiller » et « Redémarrer » ; constater l'effet sur la dalle.
  3. Relancer le parcours automatisé : npx playwright test e2e/screens-commands.spec.ts (agent en mode réel).
  4. Consigner le résultat dans docs/AUDIT-GESTION-ECRANS/14-LIMITATIONS-ET-DEPENDANCES.md.
Ce diagnostic ne modifie pas l'état de la dalle et ne valide rien à lui seul.`)
process.exit(system.ok || panel.ok ? 0 : 1)
