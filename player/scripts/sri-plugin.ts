import { createHash } from 'node:crypto'
import { existsSync, readFileSync, writeFileSync } from 'node:fs'
import { join } from 'node:path'
import type { Plugin } from 'vite'

/**
 * PLY-12 : intégrité de toute la version publiée. Chaque script et feuille de style référencé par index.html reçoit un
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
      const html = readFileSync(indexPath, 'utf8').replace(/<(script|link)\b([^>]*?)\b(src|href)="([^"]+)"([^>]*)>/g, (tag, element: string, before: string, attr: string, url: string, after: string) => {
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
