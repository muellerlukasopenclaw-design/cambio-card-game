const CACHE_NAME = 'cambio-v0.1.20';
const STATIC_ASSETS = [
    '/',
    '/index.html',
    '/styles.css',
    '/app.js',
    '/manifest.webmanifest',
    '/assets/icon-192.svg',
    '/assets/icon-512.svg'
];

self.addEventListener('install', (event) => {
    event.waitUntil(
        caches.open(CACHE_NAME).then((cache) => {
            return cache.addAll(STATIC_ASSETS);
        }).then(() => {
            return self.skipWaiting();
        })
    );
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys().then((cacheNames) => {
            return Promise.all(
                cacheNames
                    .filter((name) => name !== CACHE_NAME)
                    .map((name) => caches.delete(name))
            );
        }).then(() => {
            return self.clients.claim();
        })
    );
});

self.addEventListener('fetch', (event) => {
    const { request } = event;
    const url = new URL(request.url);

    // Network-first for HTML (always fresh)
    if (request.mode === 'navigate' || request.destination === 'document') {
        event.respondWith(
            fetch(request)
                .then((response) => {
                    const cacheCopy = response.clone();
                    caches.open(CACHE_NAME).then((cache) => cache.put(request, cacheCopy));
                    return response;
                })
                .catch(() => caches.match(request))
        );
        return;
    }

    // API requests: always network
    if (url.pathname.startsWith('/api/')) {
        event.respondWith(fetch(request));
        return;
    }

    // Static assets: cache-first
    event.respondWith(
        caches.match(request).then((cached) => {
            if (cached) return cached;
            return fetch(request).then((response) => {
                const cacheCopy = response.clone();
                caches.open(CACHE_NAME).then((cache) => cache.put(request, cacheCopy));
                return response;
            });
        })
    );
});
