/**
 * Générateur du jeu d'essai des configurations (paramètres globaux, V39).
 *
 * Source de vérité : backend/app/Http/Controllers/Api/V1/SettingController.php (constantes KEYS et RULES). Pour chaque
 * paramètre, le générateur dérive des règles du serveur :
 *  - les valeurs acceptées (bornes basse et haute, valeur médiane, vrai/faux, chaîne maximale, null si facultatif) ;
 *  - les valeurs refusées (hors bornes, mauvais type, chaîne trop longue, valeur obligatoire vide) ;
 *  - les effets observables de l'extérieur pour les paramètres qui en ont (référentiels, enrôlement des lecteurs).
 *
 * Usage : node e2e/tools/generate-configuration-cases.mjs  →  e2e/data/configuration-cases.json
 * Le jeu est exécuté par e2e/configurations.spec.ts contre l'application démarrée.
 */
import { mkdirSync, readFileSync, writeFileSync } from 'node:fs'
import { dirname, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'

const here = dirname(fileURLToPath(import.meta.url))
const source = readFileSync(resolve(here, '../../../backend/app/Http/Controllers/Api/V1/SettingController.php'), 'utf8')
const output = resolve(here, '../data/configuration-cases.json')

/** Extrait le corps d'une constante tableau PHP « private const NAME = [ ... ]; ». */
function constantBody(name) {
  const start = source.indexOf(`const ${name} = [`)
  if (start < 0) throw new Error(`Constante ${name} introuvable dans SettingController.php`)
  let depth = 0
  for (let i = source.indexOf('[', start); i < source.length; i++) {
    if (source[i] === '[') depth++
    if (source[i] === ']' && --depth === 0) return source.slice(source.indexOf('[', start) + 1, i)
  }
  throw new Error(`Constante ${name} non terminée`)
}

/** Entrées « 'clé' => [ 'a', 'b', ... ] » ; les chaînes PHP entre apostrophes sont lues telles quelles. */
function entries(body) {
  const map = new Map()
  const entry = /'([a-z_.]+)'\s*=>\s*\[((?:[^\[\]]|\[[^\]]*\])*)\]/g
  for (const m of body.matchAll(entry)) {
    const items = [...m[2].matchAll(/'((?:[^'\\]|\\.)*)'|\b(true|false)\b/g)].map((x) => (x[2] ? x[2] === 'true' : x[1].replace(/\\'/g, "'")))
    map.set(m[1], items)
  }
  return map
}

const keys = entries(constantBody('KEYS'))
const rules = entries(constantBody('RULES'))

const REASON = 'Jeu d’essai des configurations (recette locale)'
const cases = []
const add = (key, value, expect, why, extra = {}) => {
  const [tab, type, technical] = keys.get(key)
  cases.push({ id: `${key}#${cases.filter((c) => c.key === key).length + 1}`, key, tab, type, technical, value, expect, why, ...extra })
}

for (const [key, [, type]] of keys) {
  const rule = rules.get(key) ?? []
  const required = rule.includes('required')
  const nullable = rule.includes('nullable')
  const between = rule.find((r) => typeof r === 'string' && r.startsWith('between:'))
  const max = rule.find((r) => typeof r === 'string' && r.startsWith('max:'))
  const supervision = key.startsWith('supervision.') ? { constraint: 'supervision_order' } : {}

  if (key === 'operating.default_window') {
    add(key, { days: [1, 2, 3, 4, 5], on: '07:00', off: '19:00' }, 'accepted', 'Plage ouvrée du lundi au vendredi')
    add(key, { days: [1, 2, 3, 4, 5, 6, 7], on: '00:00', off: '23:59' }, 'accepted', 'Plage maximale, tous les jours')
    add(key, { days: [], on: '07:00', off: '19:00' }, 'rejected', 'Aucun jour choisi')
    add(key, { days: [1, 2], on: '19:00', off: '07:00' }, 'rejected', 'Extinction avant l’allumage')
    add(key, { days: [8], on: '07:00', off: '19:00' }, 'rejected', 'Jour inexistant (8)')
    add(key, { days: [1], off: '19:00' }, 'rejected', 'Heure d’allumage absente')
    continue
  }
  if (key === 'devices.enroll_cidrs') {
    add(key, '', 'accepted', 'Liste vide : enrôlement ouvert', { effect: 'enrollment_allowed' })
    add(key, '127.0.0.1', 'accepted', 'Poste local autorisé', { effect: 'enrollment_allowed' })
    add(key, '10.255.255.0/24', 'accepted', 'Réseau étranger au poste', { effect: 'enrollment_refused' })
    add(key, '10.20.0.0/16, 192.168.1.10, ::1', 'accepted', 'Plusieurs réseaux IPv4 et IPv6')
    add(key, 'pas-une-adresse', 'rejected', 'Texte qui n’est pas une adresse')
    add(key, '10.0.0.0/16; 10.1.0.0/16', 'rejected', 'Séparateur « ; » non admis')
    continue
  }
  if (key === 'incidents.default_assignee_id') {
    add(key, null, 'accepted', 'Aucun destinataire par défaut')
    add(key, '$CURRENT_USER', 'accepted', 'Compte actif existant (résolu à l’exécution)')
    add(key, '00000000-0000-4000-8000-000000000000', 'rejected', 'Identifiant inconnu')
    add(key, 'pas-un-uuid', 'rejected', 'Identifiant mal formé')
    continue
  }
  if (type === 'integer' && between) {
    const [lo, hi] = between.slice(8).split(',').map(Number)
    const effect = key === 'display.default_slide_s' ? { effect: 'referentials_default_slide' } : {}
    add(key, lo, 'accepted', `Borne basse (${lo})`, { ...supervision, ...effect })
    add(key, hi, 'accepted', `Borne haute (${hi})`, { ...supervision, ...effect })
    add(key, Math.round((lo + hi) / 2), 'accepted', 'Valeur médiane', { ...supervision, ...effect })
    add(key, lo - 1, 'rejected', `Sous la borne basse (${lo - 1})`)
    add(key, hi + 1, 'rejected', `Au-dessus de la borne haute (${hi + 1})`)
    add(key, 'douze', 'rejected', 'Texte au lieu d’un entier')
    continue
  }
  if (type === 'boolean') {
    const effect = key === 'referentials.restricted' ? { effect: 'referentials_directory' } : {}
    add(key, true, 'accepted', 'Activé', effect)
    add(key, false, 'accepted', 'Désactivé', effect)
    add(key, 'peut-être', 'rejected', 'Valeur non booléenne')
    continue
  }
  if (type === 'string' && max) {
    const n = Number(max.slice(4))
    add(key, 'Commission de la CEEAC – essai de configuration', 'accepted', 'Texte ordinaire avec accents')
    add(key, 'x'.repeat(n), 'accepted', `Longueur maximale (${n})`)
    add(key, 'x'.repeat(n + 1), 'rejected', `Trop long (${n + 1})`)
    if (required) add(key, '', 'rejected', 'Valeur obligatoire vide')
    if (nullable) add(key, null, 'accepted', 'Valeur facultative effacée')
    continue
  }
  throw new Error(`Paramètre ${key} : règle non prise en charge par le générateur (${JSON.stringify(rule)}). Complétez le générateur.`)
}

// Contrôles d'accès (HAB-01) : un agent ne modifie aucun paramètre ; une clé inconnue est refusée.
const access = [
  { id: 'access#agent', login: 'loic.bouanga', key: 'display.default_slide_s', value: 30, expect: 'forbidden', why: 'Un agent ne modifie pas les paramètres' },
  { id: 'access#unknown-key', login: null, key: 'inconnu.parametre', value: 1, expect: 'rejected', why: 'Clé de paramètre inconnue' },
  { id: 'access#no-reason', login: null, key: 'display.default_slide_s', value: 30, reason: '', expect: 'rejected', why: 'Motif obligatoire' },
]

mkdirSync(dirname(output), { recursive: true })
writeFileSync(output, JSON.stringify({
  generated_at: new Date().toISOString(),
  source: 'backend/app/Http/Controllers/Api/V1/SettingController.php (KEYS, RULES)',
  reason: REASON,
  settings: keys.size,
  total: cases.length + access.length,
  cases,
  access,
}, null, 2) + '\n')
console.log(`${keys.size} paramètres, ${cases.length} cas de valeur et ${access.length} cas d’accès → ${output}`)

/* ---------------------------------------------------------------------------------------------------------------------
 * Profils de configuration des écrans (ScreenProfile::SETTINGS) et règles des salles (RoomController).
 * ------------------------------------------------------------------------------------------------------------------- */

const backend = (path) => readFileSync(resolve(here, '../../../backend', path), 'utf8')

/** Corps d'un tableau PHP ouvert juste après le marqueur donné. */
function arrayAfter(text, marker) {
  const start = text.indexOf(marker)
  if (start < 0) throw new Error(`Marqueur introuvable : ${marker}`)
  const open = text.indexOf('[', start + marker.length - 1)
  let depth = 0
  for (let i = open; i < text.length; i++) {
    if (text[i] === '[') depth++
    if (text[i] === ']' && --depth === 0) return text.slice(open + 1, i)
  }
  throw new Error(`Tableau non terminé après ${marker}`)
}

/** Règles d'un champ : chaînes entre apostrophes de la liste (les règles objets « Rule:: » sont ignorées). */
const ruleList = (raw) => [...raw.matchAll(/'((?:[^'\\]|\\.)*)'/g)].map((x) => x[1])

/**
 * Valeurs dérivées d'une règle numérique ou booléenne : acceptées (bornes, médiane) et refusées (hors bornes, mauvais type).
 *
 * @returns {{ accepted: Array<[unknown, string]>, rejected: Array<[unknown, string]> } | null}
 */
function derive(rules) {
  const between = rules.find((r) => r.startsWith('between:'))
  const min = rules.find((r) => r.startsWith('min:'))
  const max = rules.find((r) => r.startsWith('max:'))
  if (rules.includes('integer') && (between || (min && max))) {
    const [lo, hi] = between ? between.slice(8).split(',').map(Number) : [Number(min.slice(4)), Number(max.slice(4))]
    return {
      accepted: [[lo, `Borne basse (${lo})`], [hi, `Borne haute (${hi})`], [Math.round((lo + hi) / 2), 'Valeur médiane']],
      rejected: [[lo - 1, `Sous la borne basse (${lo - 1})`], [hi + 1, `Au-dessus de la borne haute (${hi + 1})`], ['douze', 'Texte au lieu d’un entier']],
    }
  }
  if (rules.includes('boolean')) return { accepted: [[true, 'Activé'], [false, 'Désactivé']], rejected: [['peut-être', 'Valeur non booléenne']] }
  return null
}

// Profils de configuration : chaque réglage est essayé seul sur un profil d'essai dédié.
const profileSpec = arrayAfter(backend('app/Models/ScreenProfile.php'), 'const SETTINGS = [')
const servers = (backend('.env').match(/^SAIE_DEVICE_SERVERS=(.*)$/m)?.[1] ?? '').replace(/^["']|["']$/g, '').split(',').map((s) => s.trim()).filter(Boolean)
const profileCases = []
const addProfile = (key, value, expect, why) => profileCases.push({ id: `profil.${key}#${profileCases.filter((c) => c.key === key).length + 1}`, key, value, expect, why })
for (const m of profileSpec.matchAll(/'([a-z_]+)'\s*=>\s*\[\s*'label'\s*=>\s*'((?:[^'\\]|\\.)*)'\s*,\s*'rules'\s*=>\s*\[([^\]]*)\]/g)) {
  const [, key, , raw] = m
  const rules = ruleList(raw)
  if (key === 'languages') {
    addProfile(key, ['fr'], 'accepted', 'Une seule langue')
    addProfile(key, ['fr', 'en', 'pt', 'es'], 'accepted', 'Les quatre langues')
    addProfile(key, [], 'rejected', 'Aucune langue')
    addProfile(key, ['fr', 'fr'], 'rejected', 'Langue en double')
    addProfile(key, ['de'], 'rejected', 'Langue non prise en charge')
  } else if (key === 'server_url') {
    // Seuls les serveurs déclarés dans SAIE_DEVICE_SERVERS sont admis (un autre rendrait le lecteur orphelin).
    for (const s of servers) addProfile(key, s, 'accepted', 'Serveur déclaré dans SAIE_DEVICE_SERVERS')
    addProfile(key, 'https://serveur-non-declare.example', 'rejected', servers.length ? 'Serveur non déclaré' : 'Serveur non déclaré (aucun serveur déclaré sur ce poste)')
    addProfile(key, 'x'.repeat(201), 'rejected', 'Adresse trop longue')
  } else {
    const d = derive(rules)
    if (!d) throw new Error(`Profil : réglage ${key} non pris en charge par le générateur (${raw}).`)
    d.accepted.forEach(([v, why]) => addProfile(key, v, 'accepted', why))
    d.rejected.forEach(([v, why]) => addProfile(key, v, 'rejected', why))
  }
  addProfile(key, null, 'accepted', 'Réglage retiré (comportement par défaut)')
}
const profileFields = [
  { id: 'profil.code#minuscules', field: 'code', value: 'essai-config', expect: 'rejected', why: 'Code en minuscules (majuscules, chiffres et tirets seulement)' },
  { id: 'profil.code#long', field: 'code', value: 'A'.repeat(41), expect: 'rejected', why: 'Code trop long' },
  { id: 'profil.name#long', field: 'name', value: 'n'.repeat(121), expect: 'rejected', why: 'Nom trop long' },
  { id: 'profil.description#max', field: 'description', value: 'd'.repeat(500), expect: 'accepted', why: 'Description de longueur maximale' },
  { id: 'profil.description#long', field: 'description', value: 'd'.repeat(501), expect: 'rejected', why: 'Description trop longue' },
]

// Salles : chaque règle est essayée sur une salle d'essai dédiée, puis son effet est vérifié sur un créneau (sans réservation).
const roomRules = arrayAfter(backend('app/Http/Controllers/Api/V1/RoomController.php'), 'private function validateRoomFields(Request $request, ?Room $room, string $req): array\n    {\n        return $request->validate([')
const roomCases = []
const addRoom = (field, payload, expect, why, extra = {}) => roomCases.push({ id: `salle.${field}#${roomCases.filter((c) => c.field === field).length + 1}`, field, payload, expect, why, ...extra })
const RULED = ['capacity', 'min_minutes', 'max_minutes', 'lead_days', 'prep_minutes', 'cleanup_minutes', 'pending_hold_hours', 'auto_release_enabled']
const EFFECT = { capacity: 'room_capacity', min_minutes: 'room_min_duration', max_minutes: 'room_max_duration', lead_days: 'room_lead_days' }
for (const m of roomRules.matchAll(/'([a-z_]+)'\s*=>\s*\[([^\]]*)\]/g)) {
  const [, field, raw] = m
  if (!RULED.includes(field)) continue
  const d = derive(ruleList(raw))
  if (!d) throw new Error(`Salle : règle ${field} non prise en charge par le générateur (${raw}).`)
  const constraint = field === 'max_minutes' ? { constraint: 'max_gte_min' } : field === 'min_minutes' ? { constraint: 'min_lte_max' } : {}
  d.accepted.forEach(([v, why]) => addRoom(field, { [field]: v }, 'accepted', why, { ...constraint, ...(EFFECT[field] ? { effect: EFFECT[field] } : {}) }))
  d.rejected.forEach(([v, why]) => addRoom(field, { [field]: v }, 'rejected', why))
}
addRoom('opening', { open_from: '07:30', open_to: '19:00' }, 'accepted', 'Horaires de bureau', { effect: 'room_hours' })
addRoom('opening', { open_from: '00:00', open_to: '23:59' }, 'accepted', 'Journée entière', { effect: 'room_hours' })
addRoom('opening', { open_from: '13:00', open_to: '17:00' }, 'accepted', 'Après-midi seulement', { effect: 'room_hours' })
addRoom('opening', { open_from: '18:00', open_to: '08:00' }, 'rejected', 'Fermeture avant l’ouverture')
addRoom('opening', { open_from: '10:00', open_to: '10:00' }, 'rejected', 'Ouverture et fermeture identiques')
addRoom('opening', { open_from: '25:00', open_to: '26:00' }, 'rejected', 'Heure inexistante')
addRoom('opening', { open_to: '05:00' }, 'rejected', 'Fermeture seule, avant l’ouverture en place (07:30)')
addRoom('opening', { open_to: '20:30' }, 'accepted', 'Fermeture seule, après l’ouverture en place', { effect: 'room_hours' })
addRoom('opening', { open_from: '06:00' }, 'accepted', 'Ouverture seule, avant la fermeture en place', { effect: 'room_hours' })
addRoom('opening', { open_from: '19:30' }, 'rejected', 'Ouverture seule, après la fermeture en place (19:00)')
addRoom('status', { status: 'inactive' }, 'accepted', 'Salle mise hors service', { effect: 'room_status' })
addRoom('status', { status: 'active' }, 'accepted', 'Salle réservable', { effect: 'room_status' })
addRoom('status', { status: 'fermee' }, 'rejected', 'État inconnu')
addRoom('approval_mode', { approval_mode: 'auto' }, 'accepted', 'Confirmation automatique', { effect: 'room_approval' })
addRoom('approval_mode', { approval_mode: 'manual', manager_id: '$CURRENT_USER' }, 'accepted', 'Validation par un gestionnaire désigné', { effect: 'room_approval' })
addRoom('approval_mode', { approval_mode: 'manual', manager_id: null }, 'rejected', 'Validation manuelle sans gestionnaire')
addRoom('approval_mode', { approval_mode: 'tacite' }, 'rejected', 'Mode inconnu')
addRoom('manager_id', { manager_id: '00000000-0000-4000-8000-000000000000' }, 'rejected', 'Gestionnaire inexistant')

const extraOutput = resolve(here, '../data/room-profile-cases.json')
writeFileSync(extraOutput, JSON.stringify({
  generated_at: new Date().toISOString(),
  sources: ['backend/app/Models/ScreenProfile.php (SETTINGS)', 'backend/app/Http/Controllers/Api/V1/RoomController.php (validateRoomFields)', 'backend/.env (SAIE_DEVICE_SERVERS seulement)'],
  reason: REASON,
  allowed_servers: servers.length,
  profile: { settings: new Set(profileCases.map((c) => c.key)).size, cases: profileCases, fields: profileFields },
  room: { cases: roomCases },
  total: profileCases.length + profileFields.length + roomCases.length,
}, null, 2) + '\n')
console.log(`Profils : ${new Set(profileCases.map((c) => c.key)).size} réglages, ${profileCases.length + profileFields.length} cas ; salles : ${roomCases.length} cas → ${extraOutput}`)
