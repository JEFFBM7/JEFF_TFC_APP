// Handlers Web Push, chargés dans le service worker Workbox via importScripts().
// API natives uniquement (pas de dépendance) -> showNotification + clic.

self.addEventListener('push', function (event) {
  var data = {}
  try {
    data = event.data ? event.data.json() : {}
  } catch (e) {
    data = { body: event.data ? event.data.text() : '' }
  }

  var title = data.title || 'EduConnect'
  var options = {
    body: data.body || '',
    icon: '/pwa/icon-192.png',
    badge: '/pwa/icon-192.png',
    tag: data.tag || undefined,
    renotify: !!data.tag,
    data: { url: data.url || '/' },
  }

  event.waitUntil(self.registration.showNotification(title, options))
})

self.addEventListener('notificationclick', function (event) {
  event.notification.close()
  var targetUrl = (event.notification.data && event.notification.data.url) || '/'

  event.waitUntil(
    self.clients
      .matchAll({ type: 'window', includeUncontrolled: true })
      .then(function (clientList) {
        for (var i = 0; i < clientList.length; i++) {
          var client = clientList[i]
          if ('focus' in client) {
            if ('navigate' in client) {
              try {
                client.navigate(targetUrl)
              } catch (e) {
                /* ignore */
              }
            }
            return client.focus()
          }
        }
        return self.clients.openWindow(targetUrl)
      }),
  )
})
