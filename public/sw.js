/*
 * App-shell service worker for the clock-in PWA.
 *
 * Scope is deliberately narrow: it caches the shell (HTML, JS, CSS, icons) and
 * NOTHING from /api. A cached punch response would be worse than no response — a
 * clock-in that silently did not reach the server looks successful to the employee
 * and is missing from payroll.
 *
 * What this buys: in a shop with poor signal, staff see the app and a clear
 * "you're offline" message instead of the browser's error page. Queueing punches
 * for later sync is a separate, larger piece of work.
 */

const CACHE = 'dars-punch-v1';

const SHELL = [
    '/punch',
    '/manifest.webmanifest',
    '/icons/icon-192.png',
    '/icons/icon-512.png',
];

self.addEventListener('install', (event) => {
    event.waitUntil(
        caches.open(CACHE).then((cache) => cache.addAll(SHELL)).then(() => self.skipWaiting()),
    );
});

self.addEventListener('activate', (event) => {
    // Old caches are dropped on activate, so a deploy cannot leave a phone serving
    // last month's JavaScript against this month's API.
    event.waitUntil(
        caches.keys()
            .then((keys) => Promise.all(keys.filter((key) => key !== CACHE).map((key) => caches.delete(key))))
            .then(() => self.clients.claim()),
    );
});

self.addEventListener('fetch', (event) => {
    const { request } = event;

    if (request.method !== 'GET') {
        return;
    }

    const url = new URL(request.url);

    // Never cached: API calls, and the photos behind signed URLs, which are private
    // and expire.
    if (url.origin !== self.location.origin
        || url.pathname.startsWith('/api/')
        || url.pathname.startsWith('/storage/')
        || url.pathname.startsWith('/console')) {
        return;
    }

    // Vite fingerprints asset filenames, so a cached asset is immutable and can be
    // served without revalidation.
    if (url.pathname.startsWith('/build/')) {
        event.respondWith(
            caches.match(request).then((cached) => cached ?? fetch(request).then((response) => {
                const copy = response.clone();
                caches.open(CACHE).then((cache) => cache.put(request, copy));

                return response;
            })),
        );

        return;
    }

    // Everything else, including the shell document: network first so a deploy is
    // picked up immediately, falling back to the cache when offline.
    event.respondWith(
        fetch(request)
            .then((response) => {
                if (response.ok) {
                    const copy = response.clone();
                    caches.open(CACHE).then((cache) => cache.put(request, copy));
                }

                return response;
            })
            .catch(() => caches.match(request).then((cached) => cached ?? caches.match('/punch'))),
    );
});
