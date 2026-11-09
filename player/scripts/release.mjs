// Construit une version du lecteur servie sous /player/releases/<version>/ et affiche l'empreinte à déclarer (PLY-12).
// Usage : node scripts/release.mjs 1.1.0   puis copier dist-release/1.1.0 vers <webroot>/player/releases/1.1.0
// et publier la version via POST /api/v1/player-releases (canal « pilot » d'abord, puis « stable »).
import { execSync } from 'node:child_process'
import { createHash } from 'node:crypto'
import { readFileSync } from 'node:fs'

const version = process.argv[2]
if (!/^[0-9A-Za-z.-]+$/.test(version ?? '')) {
  console.error('Version invalide. Exemple : node scripts/release.mjs 1.1.0')
  process.exit(1)
}
const base = `/player/releases/${version}/`
execSync(`npx vite build --base=${base} --outDir=dist-release/${version}`, { stdio: 'inherit', env: { ...process.env, NODE_ENV: 'production', SAIE_PLAYER_VERSION: version } })
const sha256 = createHash('sha256').update(readFileSync(`dist-release/${version}/index.html`)).digest('hex')
console.log(JSON.stringify({ version, base_url: base, sha256 }, null, 2))
