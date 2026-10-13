/**
 * Service Worker — WMS Fénix
 * v22: Network-First for HTML/JS to prevent stale cache.
 * v24 (2026-10-12): CSS también Network-First — mobile.css (sin ?v) quedaba
 *      congelado en cada celular y el tema bee nunca llegaba. Cache-First solo imágenes/fuentes.
 */

const CACHE_NAME = 'prowms-v24';
const BASE = '/WMS_FENIX/public';

// ── Instalación: precachear solo assets estáticos ───────────────────────────
self.addEventListener('install', event => {
    // Skip precaching — let assets be cached on first access
    self.skipWaiting();
});

// ── Activación: limpiar cachés anteriores ───────────────────────────────────
self.addEventListener('activate', event => {
    event.waitUntil(
        caches.keys().then(keys =>
            Promise.all(keys.map(key => key !== CACHE_NAME ? caches.delete(key) : null))
        ).then(() => self.clients.claim())
    );
});

// ── Mensaje para forzar skip waiting ────────────────────────────────────────
self.addEventListener('message', event => {
    if (event.data && event.data.type === 'SKIP_WAITING') {
        self.skipWaiting();
    }
});

// ── Fetch strategy ──────────────────────────────────────────────────────────
self.addEventListener('fetch', event => {
    const url = new URL(event.request.url);

    // Skip: non-GET, API calls, chrome-extension, external origins
    if (event.request.method !== 'GET') return;
    if (url.pathname.includes('/api/')) return;
    if (url.origin !== self.location.origin) return;

    // HTML, JS y CSS → Network First (always get latest, cache as backup)
    if (event.request.destination === 'document' ||
        event.request.destination === 'script' ||
        event.request.destination === 'style' ||
        url.pathname.endsWith('.html') ||
        url.pathname.endsWith('.css') ||
        url.pathname.endsWith('.js')) {
        event.respondWith(networkFirst(event.request));
        return;
    }

    // Images, fonts → Cache First (immutable assets)
    event.respondWith(cacheFirst(event.request));
});

// ── Network First: try network, fallback to cache ───────────────────────────
async function networkFirst(request) {
    try {
        const networkRes = await fetch(request);
        if (networkRes && networkRes.status === 200) {
            const cache = await caches.open(CACHE_NAME);
            cache.put(request, networkRes.clone());
        }
        return networkRes;
    } catch (e) {
        const cached = await caches.match(request);
        if (cached) return cached;
        // Fallback for navigation
        if (request.destination === 'document') {
            const fallback = await caches.match(BASE + '/index.html');
            if (fallback) return fallback;
        }
        return new Response('Offline', { status: 503 });
    }
}

// ── Cache First: try cache, fallback to network ─────────────────────────────
async function cacheFirst(request) {
    const cached = await caches.match(request);
    if (cached) return cached;

    try {
        const networkRes = await fetch(request);
        if (networkRes && networkRes.status === 200) {
            const cache = await caches.open(CACHE_NAME);
            cache.put(request, networkRes.clone());
        }
        return networkRes;
    } catch (e) {
        return new Response('', { status: 503, statusText: 'Offline' });
    }
}
