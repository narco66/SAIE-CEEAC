/**
 * §18.5 : signature HMAC-SHA256 des requêtes du lecteur (méthode, chemin, horodatage, nonce, empreinte du corps). La clé
 * est l'empreinte SHA-256 de la partie secrète du jeton, que le serveur conserve ; le jeton lui-même ne sert pas de clé.
 * L'horodatage suit l'horloge corrigée par le serveur, pour qu'un lecteur à l'heure dérivée reste accepté.
 */
let clockOffsetMs = 0

export function setSigningClockOffset(offset: number): void {
  clockOffsetMs = Number.isFinite(offset) ? offset : 0
}

const hex = (buffer: ArrayBuffer) => [...new Uint8Array(buffer)].map((b) => b.toString(16).padStart(2, '0')).join('')

export async function signRequest(token: string, method: string, path: string, body: BodyInit | null | undefined): Promise<Record<string, string>> {
  if (!globalThis.crypto?.subtle) return {}
  const secret = token.includes('|') ? token.slice(token.indexOf('|') + 1) : token
  const keyHex = hex(await crypto.subtle.digest('SHA-256', new TextEncoder().encode(secret)))
  const bodyHash = typeof body === 'string' ? hex(await crypto.subtle.digest('SHA-256', new TextEncoder().encode(body))) : body == null ? hex(await crypto.subtle.digest('SHA-256', new Uint8Array())) : 'UNSIGNED-BODY'
  const timestamp = String(Math.floor((Date.now() + clockOffsetMs) / 1000))
  const nonce = crypto.randomUUID()
  const key = await crypto.subtle.importKey('raw', new TextEncoder().encode(keyHex), { name: 'HMAC', hash: 'SHA-256' }, false, ['sign'])
  const signature = hex(await crypto.subtle.sign('HMAC', key, new TextEncoder().encode([method, path, timestamp, nonce, bodyHash].join('\n'))))
  return { 'X-SAIE-Timestamp': timestamp, 'X-SAIE-Nonce': nonce, 'X-SAIE-Body-Hash': bodyHash, 'X-SAIE-Signature': signature }
}
