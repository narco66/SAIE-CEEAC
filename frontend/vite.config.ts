import { defineConfig } from 'vite'
import react from '@vitejs/plugin-react'

// En développement, l'API Laravel est servie derrière le même origine via le proxy (API-05 : cookies SameSite, CSRF).
const api = process.env.SAIE_API_URL ?? 'http://127.0.0.1:8000'
// §10.22 : le flux temps réel (SSE) occupe un processus PHP pendant sa durée ; le serveur PHP intégré étant mono-processus
// sous Windows, il est servi en développement par un second `php artisan serve --port=8001`. En production, PHP-FPM.
const realtime = process.env.SAIE_REALTIME_URL ?? 'http://127.0.0.1:8001'

export default defineConfig({
  plugins: [react()],
  server: {
    port: 5173,
    strictPort: true,
    proxy: {
      '/api/v1/realtime': { target: realtime, changeOrigin: false },
      '/api': { target: api, changeOrigin: false },
      '/sanctum': { target: api, changeOrigin: false },
    },
  },
  preview: { port: 4173 },
  build: { outDir: 'dist', sourcemap: false, chunkSizeWarningLimit: 1200 },
})
