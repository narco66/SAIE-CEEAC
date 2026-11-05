/*
 * Service worker du lecteur : conserve la coquille applicative (HTML, JS, CSS, polices, logo) pour redémarrer hors ligne
 * après une coupure électrique. Les médias sont gérés par le moteur dans un cache dédié et ne transitent pas ici.
 * Les appels à l'API ne sont jamais mis en cache par ce service worker.
 */
const SHELL = 'saie-shell-v1'

self.addEventListener('install', (event) => {
  event.waitUntil(caches.open(SHELL).then((cache) => cache.addAll([self.registration.scope])).then(() => self.skipWaiting()))
})

self.addEventListener('activate', (event) => {
  event.waitUntil(caches.keys().then((keys) => Promise.all(keys.filter((k) => k.startsWith('saie-shell-') && k !== SHELL).map((k) => caches.delete(k)))).then(() => self.clients.claim()))
})

self.addEventListener('fetch', (event) => {
  const url = new URL(event.request.url)
  if (event.request.method !== 'GET' || url.origin !== location.origin || url.pathname.startsWith('/api/') || url.pathname.startsWith('/__saie_asset__/')) return
  event.respondWith(
    fetch(event.request)
      .then((response) => {
        if (response.ok) {
          const copy = response.clone()
          caches.open(SHELL).then((cache) => cache.put(event.request, copy))
        }
        return response
      })
      .catch(() => caches.match(event.request).then((hit) => hit || caches.match(self.registration.scope))),
  )
})
