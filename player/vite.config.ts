import { defineConfig } from 'vite'
import react from '@vitejs/plugin-react'
import { readFileSync } from 'node:fs'
import { subresourceIntegrity } from './scripts/sri-plugin.ts'

const api = process.env.SAIE_API_URL ?? 'http://127.0.0.1:8000'
const version = process.env.SAIE_PLAYER_VERSION ?? JSON.parse(readFileSync(new URL('./package.json', import.meta.url), 'utf8')).version

// Le lecteur est servi sous /player/ en production, derrière le même domaine que l'API (TLS, SEC-01).
// Une version publiée par canal (PLY-12) est construite sous /player/releases/<version>/ par scripts/release.mjs.
export default defineConfig({
  base: process.env.NODE_ENV === 'production' ? '/player/' : '/',
  plugins: [react(), subresourceIntegrity()],
  define: { __PLAYER_VERSION__: JSON.stringify(version) },
  server: { port: 5174, strictPort: true, proxy: { '/api': { target: api, changeOrigin: false } } },
  build: { outDir: 'dist', chunkSizeWarningLimit: 2000 },
})
