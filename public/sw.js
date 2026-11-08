'use strict';

const CACHE_NAME = 'farast-static-v2';
const LEGACY_CACHES = ['farast-shell-v1'];
const CORE_ASSETS = [
  '/offline.html',
  '/manifest.webmanifest',
  '/icons/farast-icon.svg',
  '/icons/farast-maskable.svg'
];

self.addEventListener('install', event => {
  event.waitUntil(
    caches.open(CACHE_NAME)
      .then(cache => cache.addAll(CORE_ASSETS))
      .then(() => self.skipWaiting())
  );
});

self.addEventListener('activate', event => {
  event.waitUntil(
    caches.keys()
      .then(keys => Promise.all(keys
        .filter(key => (key.startsWith('farast-static-') && key !== CACHE_NAME) || LEGACY_CACHES.includes(key))
        .map(key => caches.delete(key))))
      .then(() => self.clients.claim())
  );
});

self.addEventListener('fetch', event => {
  const request = event.request;
  if (request.method !== 'GET') return;

  const url = new URL(request.url);
  if (url.origin !== self.location.origin) return;

  // Never cache route HTML: authenticated pages, CSRF-bearing responses and
  // user-specific data must always come from the live server.
  if (request.mode === 'navigate') {
    event.respondWith(
      fetch(request).catch(() => caches.match('/offline.html'))
    );
    return;
  }

  // Cache only versionable public assets. APIs, uploads, previews, downloads,
  // account routes and all other dynamic responses remain network-only.
  const isPublicAsset =
    /^\/(?:css|js|fonts|icons)\//.test(url.pathname) ||
    url.pathname === '/manifest.webmanifest' ||
    url.pathname === '/offline.html';

  if (!isPublicAsset) return;

  event.respondWith((async () => {
    const cache = await caches.open(CACHE_NAME);
    const cached = await cache.match(request);
    const network = fetch(request).then(response => {
      if (response.ok && response.type === 'basic') {
        cache.put(request, response.clone()).catch(() => {});
      }
      return response;
    }).catch(() => null);

    if (cached) {
      event.waitUntil(network.then(() => undefined));
      return cached;
    }

    const response = await network;
    return response || new Response('', { status: 503, statusText: 'Offline' });
  })());
});
