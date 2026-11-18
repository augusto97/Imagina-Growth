/*
 * Service worker de la bandeja (PWA de M11). Solo notificaciones push: no guarda
 * páginas ni respuestas de la API en caché (los mensajes son datos personales).
 * El aviso no trae el texto del mensaje: solo el título y el enlace a la conversación.
 */
self.addEventListener('install', () => self.skipWaiting());
self.addEventListener('activate', (event) => event.waitUntil(self.clients.claim()));
// Necesario para que el navegador ofrezca instalar la app; responde siempre desde la red.
self.addEventListener('fetch', () => undefined);

self.addEventListener('push', (event) => {
    let data = {};
    try {
        data = event.data ? event.data.json() : {};
    } catch (e) {
        data = {};
    }
    const title = typeof data.title === 'string' ? data.title : 'Nuevo mensaje en la bandeja';
    event.waitUntil(
        self.registration.showNotification(title, {
            icon: '/inbox-icon-192.png',
            badge: '/inbox-icon-192.png',
            tag: typeof data.tag === 'string' ? data.tag : 'igr-inbox',
            data: { url: typeof data.url === 'string' && data.url.startsWith('/') ? data.url : '/' },
        }),
    );
});

self.addEventListener('notificationclick', (event) => {
    event.notification.close();
    const url = event.notification.data && event.notification.data.url ? event.notification.data.url : '/';
    event.waitUntil(
        self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then((list) => {
            for (const client of list) {
                if ('focus' in client) {
                    client.navigate(url);
                    return client.focus();
                }
            }
            return self.clients.openWindow(url);
        }),
    );
});
