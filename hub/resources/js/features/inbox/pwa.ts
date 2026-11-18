/* PWA de la bandeja: manifiesto y service worker (notificaciones push). Sin tocar el HTML compartido: se agregan al abrir la bandeja. */

export function installPwa(): void {
    if (typeof document === 'undefined') return;
    if (!document.querySelector('link[rel="manifest"]')) {
        const link = document.createElement('link');
        link.rel = 'manifest';
        link.href = '/inbox.webmanifest';
        document.head.appendChild(link);
    }
    if (!document.querySelector('meta[name="theme-color"]')) {
        const meta = document.createElement('meta');
        meta.name = 'theme-color';
        meta.content = '#4f46e5';
        document.head.appendChild(meta);
    }
    if ('serviceWorker' in navigator && window.isSecureContext) {
        void navigator.serviceWorker.register('/inbox-sw.js', { scope: '/' }).catch(() => undefined);
    }
}

/** Pide permiso y suscribe este navegador a Web Push con la llave pública VAPID del hub. */
export async function subscribePush(publicKey: Uint8Array): Promise<PushSubscriptionJSON | null> {
    if (!('serviceWorker' in navigator) || !('PushManager' in window)) return null;
    const permission = await Notification.requestPermission();
    if (permission !== 'granted') return null;
    const registration = await navigator.serviceWorker.ready;
    const sub = await registration.pushManager.subscribe({
        userVisibleOnly: true,
        applicationServerKey: publicKey as BufferSource,
    });
    return sub.toJSON();
}
