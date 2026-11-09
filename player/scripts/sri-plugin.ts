import { createHash } from 'node:crypto'
import { existsSync, readdirSync, readFileSync, writeFileSync } from 'node:fs'
import { join } from 'node:path'
import type { Plugin } from 'vite'

/**
 * PLY-12 : intégrité de toute la version publiée. Chaque script (y compris les modules chargés à la demande, déclarés en
 * préchargement) et chaque feuille de style référencé par index.html reçoit un
 * attribut `integrity` (SHA-384) calculé sur le fichier écrit sur le disque. Le lecteur vérifie l'empreinte d'index.html avant
 * de basculer ; le navigateur refuse ensuite tout fichier dont le contenu ne correspond pas.
 */
export function subresourceIntegrity(): Plugin {
  return {
    name: 'saie-subresource-integrity',
    apply: 'build',
    enforce: 'post',
    writeBundle(options) {
      const dir = options.dir ?? 'dist'
      const indexPath = join(dir, 'index.html')
      if (!existsSync(indexPath)) return
      // Modules chargés à la demande (pdf.js) : déclarés en préchargement pour recevoir eux aussi une empreinte vérifiée
      // par le navigateur, et être mis en cache dès le démarrage pour un fonctionnement hors ligne.
      let source = readFileSync(indexPath, 'utf8')
      const base = source.match(/src="([^"]*?)assets\//)?.[1] ?? '/'
      const lazy = (existsSync(join(dir, 'assets')) ? readdirSync(join(dir, 'assets')) : [])
        .filter((f) => f.endsWith('.js') && !source.includes(`assets/${f}`))
      if (lazy.length) {
        source = source.replace('</head>', lazy.map((f) => `    <link rel="modulepreload" crossorigin href="${base}assets/${f}">
`).join('') + '  </head>')
      }
      const html = source.replace(/<(script|link)\b([^>]*?)\b(src|href)="([^"]+)"([^>]*)>/g, (tag, element: string, before: string, attr: string, url: string, after: string) => {
        if (element === 'link' && !/rel="(stylesheet|modulepreload)"/.test(tag)) return tag
        if (/\bintegrity=/.test(tag)) return tag
        const match = url.match(/(assets\/[^?#"]+)$/)
        const file = match ? join(dir, match[1]) : null
        if (!file || !existsSync(file)) return tag
        const integrity = 'sha384-' + createHash('sha384').update(readFileSync(file)).digest('base64')
        const crossorigin = /\bcrossorigin\b/.test(tag) ? '' : ' crossorigin="anonymous"'
        return `<${element}${before}${attr}="${url}"${after} integrity="${integrity}"${crossorigin}>`
      })
      writeFileSync(indexPath, html)
    },
  }
}
