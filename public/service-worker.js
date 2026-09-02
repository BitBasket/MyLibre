const CACHE = 'mylibre-static-v3';
const ASSETS = [
    '/',
    '/index.html',
    '/app.css',
    '/app.js',
    '/manifest.webmanifest',
    '/icons/icon.svg',
    '/icons/icon-192.png',
    '/icons/icon-512.png',
    '/vendor/chart.umd.min.js',
];

self.addEventListener('install', (event) => {
    event.waitUntil(
        caches.open(CACHE).then((cache) => cache.addAll(ASSETS)).then(() => self.skipWaiting())
    );
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys().then((keys) => Promise.all(
            keys.filter((key) => key !== CACHE).map((key) => caches.delete(key))
        )).then(() => self.clients.claim())
    );
});

self.addEventListener('fetch', (event) => {
    const url = new URL(event.request.url);
    if (url.origin !== location.origin) {
        return;
    }

    if (url.pathname === '/current.json'
        || url.pathname === '/status.json'
        || /^\/history-\d{8}\.json$/.test(url.pathname)) {
        event.respondWith(
            fetch(event.request).catch(() => new Response(JSON.stringify({
                error: 'offline',
            }), {
                status: 503,
                headers: { 'Content-Type': 'application/json' },
            }))
        );
        return;
    }

    event.respondWith(
        caches.match(event.request).then((cached) => cached || fetch(event.request))
    );
});
