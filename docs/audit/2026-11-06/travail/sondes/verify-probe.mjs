import { createRequire } from 'node:module'
import { readFileSync } from 'node:fs'
const require = createRequire(process.argv[2] + '/package.json')
const nacl = require('tweetnacl')
const e = JSON.parse(readFileSync(process.argv[3], 'utf8'))
const b = (s) => Uint8Array.from(Buffer.from(s, 'base64'))
const enc = (s) => new TextEncoder().encode(s)
console.log('signature intacte acceptée :', nacl.sign.detached.verify(enc(e.payload), b(e.sig), b(e.pub)))
console.log('charge altérée (version 7 -> 8) acceptée :', nacl.sign.detached.verify(enc(e.payload.replace('"version":7', '"version":8')), b(e.sig), b(e.pub)))
const sig = b(e.sig); sig[0] ^= 1
console.log('signature altérée acceptée :', nacl.sign.detached.verify(enc(e.payload), sig, b(e.pub)))
