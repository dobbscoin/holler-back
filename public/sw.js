/**
 * Holler Back service worker.
 *
 * Purpose:
 *   1. Receive Web Push events, show a system notification, deep-link to the
 *      right conversation when tapped.
 *   2. Cache the admin shell for offline start (so opening the PWA when the
 *      network is flaky still renders — the WS will reconnect once online).
 *
 * We intentionally do NOT precache admin dependencies at install-time — that
 * complicates upgrades. Runtime cache-first for the shell URLs is enough.
 */

const CACHE = 'hb-shell-v1';
const SHELL = ['/admin/', '/admin.js', '/manifest.json'];

self.addEventListener('install', (ev) => {
  ev.waitUntil(caches.open(CACHE).then((c) => c.addAll(SHELL).catch(() => null)));
  self.skipWaiting();
});

self.addEventListener('activate', (ev) => {
  ev.waitUntil(
    caches.keys().then((keys) => Promise.all(keys.filter((k) => k !== CACHE).map((k) => caches.delete(k))))
  );
  self.clients.claim();
});

self.addEventListener('fetch', (ev) => {
  const url = new URL(ev.request.url);
  // Only handle GETs to our own origin, and only the shell paths
  if (ev.request.method !== 'GET') return;
  if (url.origin !== self.location.origin) return;
  if (!SHELL.includes(url.pathname)) return;
  ev.respondWith(
    caches.match(ev.request).then((hit) => hit || fetch(ev.request).then((r) => {
      const clone = r.clone();
      caches.open(CACHE).then((c) => c.put(ev.request, clone));
      return r;
    }))
  );
});

self.addEventListener('push', (ev) => {
  let data = {};
  if (ev.data) { try { data = ev.data.json(); } catch (e) { data = { title: 'New message', body: ev.data.text() }; } }
  const title = data.title || 'New chat message';
  const opts = {
    body: data.body || '',
    icon: '/icon-192.png',
    badge: '/icon-192.png',
    tag: data.conv_id ? 'conv-' + data.conv_id : 'holler',
    data: { url: data.url || '/admin/' },
    renotify: true,
  };
  ev.waitUntil(self.registration.showNotification(title, opts));
});

self.addEventListener('notificationclick', (ev) => {
  ev.notification.close();
  const url = (ev.notification.data && ev.notification.data.url) || '/admin/';
  ev.waitUntil(
    self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then((wins) => {
      for (const w of wins) {
        if (w.url.indexOf('/admin/') !== -1) { w.focus(); w.navigate(url); return; }
      }
      return self.clients.openWindow(url);
    })
  );
});
