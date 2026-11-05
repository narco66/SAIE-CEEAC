import { defineConfig } from '@playwright/test'

/**
 * Parcours de bout en bout (QUAL-01). Prérequis : API (:8000), worker, frontend (:5173) et lecteur (:5174) démarrés,
 * base de démonstration chargée (cd backend; php artisan migrate --seed). Les identifiants sont lus dans backend/storage/app/private/demo-accounts.txt.
 */
export default defineConfig({
  testDir: './e2e',
  // Sondes de diagnostic (zz-*.spec.ts) : exclues des parcours et de la CI, lancées à la demande avec E2E_PROBES=1.
  testIgnore: process.env.E2E_PROBES ? [] : ['**/zz-*.spec.ts'],
  timeout: 240_000,
  expect: { timeout: 20_000 },
  workers: 1,
  reporter: [['list'], ['json', { outputFile: 'test-results/e2e-report.json' }]],
  use: {
    baseURL: process.env.E2E_BASE_URL ?? 'http://localhost:5173',
    channel: process.env.E2E_CHANNEL ?? 'chrome',
    locale: 'fr-FR',
    timezoneId: 'Africa/Libreville',
    screenshot: 'only-on-failure',
    trace: 'retain-on-failure',
  },
})
