/*
 * Service worker des notifications push de l'administration SAIE-CEEAC (NOT-03).
 * Il n'intercepte aucune requête et ne met rien en cache : il affiche la notification reçue
 * ({ title, body, link, id }, contenu déjà filtré par le serveur) et ouvre son lien au clic.
 */
self.addEventListener('install', () => self.skipWaiting())
self.addEventListener('activate', (event) => event.waitUntil(self.clients.claim()))

self.addEventListener('push', (event) => {
  let data = {}
  try {
    data = event.data ? event.data.json() : {}
  } catch {
    data = { title: 'SAIE-CEEAC', body: event.data ? event.data.text() : '' }
  }
  event.waitUntil(self.registration.showNotification(data.title || 'SAIE-CEEAC', {
    body: data.body || '',
    tag: data.id || undefined,
    icon: '/ceeac-logo.jpg',
    lang: 'fr',
    data: { link: typeof data.link === 'string' && data.link.startsWith('/') ? data.link : '/' },
  }))
})

self.addEventListener('notificationclick', (event) => {
  event.notification.close()
  const target = new URL(event.notification.data?.link || '/', self.location.origin).href
  event.waitUntil(self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then((windows) => {
    const open = windows.find((w) => new URL(w.url).origin === self.location.origin)
    if (open) {
      return open.focus().then(() => open.navigate(target))
    }
    return self.clients.openWindow(target)
  }))
})
