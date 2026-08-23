/**
 * Kashery AI — minimal service worker for PWA installability.
 */
const CACHE_NAME = 'kashery-pwa-v5';
const SHELL_ASSETS = [
    './assets/css/style.css',
    './assets/css/dashboard/dashboard-dark.css',
    './assets/js/pwa-install.js',
    './assets/images/pwa/icon-192.png',
    './assets/images/pwa/icon-512.png',
    './assets/images/pwa/apple-touch-icon.png'
];

self.addEventListener('install', (event) => {
    event.waitUntil(
        caches.open(CACHE_NAME).then((cache) => cache.addAll(SHELL_ASSETS)).catch(() => undefined)
    );
    self.skipWaiting();
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys().then((keys) =>
            Promise.all(
                keys.filter((key) => key !== CACHE_NAME).map((key) => caches.delete(key))
            )
        )
    );
    self.clients.claim();
});

self.addEventListener('fetch', (event) => {
    const { request } = event;
    if (request.method !== 'GET') {
        return;
    }

    const url = new URL(request.url);

    // Page navigations (incl. directory URLs like /user/website/ served by index.php),
    // PHP pages, and API: network-first so dynamic HTML is never served stale.
    if (
        request.mode === 'navigate' ||
        request.destination === 'document' ||
        url.pathname.endsWith('.php') ||
        url.pathname.endsWith('/') ||
        url.pathname.includes('/api/') ||
        url.pathname.includes('/ajax/')
    ) {
        // 'no-store' بۆ ئەوەی fetch کاشی HTTP-ی وێبگەڕ پشتگوێ بخات و
        // هەمیشە وەشانی نوێ بهێنێت (وەک Ctrl+Shift+R). ئەمە دڵنیایی دەدات کە
        // گۆڕانکاریەکانی وەک ئاگادارکردنەوەی فرۆشگا یەکسەر دەردەکەون.
        event.respondWith(
            fetch(request, { cache: 'no-store' }).catch(() => caches.match(request))
        );
        return;
    }

    // Static assets: cache-first, then network
    event.respondWith(
        caches.match(request).then((cached) => {
            if (cached) {
                return cached;
            }
            return fetch(request).then((response) => {
                if (!response || response.status !== 200 || response.type === 'opaque') {
                    return response;
                }
                const clone = response.clone();
                caches.open(CACHE_NAME).then((cache) => cache.put(request, clone));
                return response;
            });
        })
    );
});
