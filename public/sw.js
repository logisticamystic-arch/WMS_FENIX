const CACHE_NAME = 'prowms-v16';
const ASSETS = [
    '/Prooriente/public/',
    '/Prooriente/public/index.html',
    '/Prooriente/public/manifest.json',
    '/Prooriente/public/assets/css/app.css',
    '/Prooriente/public/assets/js/api.js',
    '/Prooriente/public/assets/js/auth.js',
    '/Prooriente/public/assets/js/app.js',
];

// Instalar SW y cachear assets estáticos
self.addEventListener('install', event => {
    event.waitUntil(
        caches.open(CACHE_NAME).then(cache => {
            console.log('Precaching App Shell');
            return cache.addAll(ASSETS);
        })
    );
    self.skipWaiting();
});

// Limpiar caches antiguos
self.addEventListener('activate', event => {
    event.waitUntil(
        caches.keys().then(keys => {
            return Promise.all(
                keys.map(key => {
                    if (key !== CACHE_NAME) {
                        return caches.delete(key);
                    }
                })
            );
        })
    );
});

// Estrategia Network First para API, Cache First para estáticos
self.addEventListener('fetch', event => {
    const url = new URL(event.request.url);

    // Bypass API requests cache
    if (url.pathname.includes('/api/')) {
        event.respondWith(fetch(event.request));
        return;
    }

    event.respondWith(
        caches.match(event.request).then(response => {
            return response || fetch(event.request).then(fetchRes => {
                return caches.open(CACHE_NAME).then(cache => {
                    cache.put(event.request, fetchRes.clone());
                    return fetchRes;
                });
            });
        }).catch(() => {
            // Check offline page logic if necessary
        })
    );
});
