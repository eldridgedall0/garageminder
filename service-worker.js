/**
 * TrackMyWrench Service Worker
 * Provides offline caching and PWA functionality
 * Multi-user compatible
 *
 * Version management:
 *   APP_VERSION is received from the page via postMessage on every init.
 *   Cache names include the version so any APP_VERSION bump automatically
 *   invalidates old caches and triggers the update flow for all users.
 *   Bump APP_VERSION in config.php whenever you deploy changes to cached files.
 */

// Default version — overwritten immediately by APP_VERSION message from page
let APP_VERSION = '2.5.0';

// Cache names are version-scoped — old versions are cleaned up on activate
function getStaticCacheName()  { return `tmw-static-${APP_VERSION}`; }
function getDynCacheName()     { return `tmw-dyn-${APP_VERSION}`; }

// Static assets to cache on install (app shell)
// Add gm.features.offline.js and gm.26-offline.css to the list
const STATIC_ASSETS = [
  './',
  './index.php',
  './assets/css/gm.00-tokens.css',
  './assets/css/gm.01-base.css',
  './assets/css/gm.02-shell.css',
  './assets/css/gm.03-dashboard.css',
  './assets/css/gm.04-components.css',
  './assets/css/gm.05-entries.css',
  './assets/css/gm.06-attachments.css',
  './assets/css/gm.07-reminders.css',
  './assets/css/gm.08-settings.css',
  './assets/css/gm.09-recalls.css',
  './assets/css/gm.10-toast.css',
  './assets/css/gm.11-responsive.css',
  './assets/css/gm.12-scrollbar.css',
  './assets/css/gm.13-enhancements.css',
  './assets/css/gm.14-service-filter.css',
  './assets/css/gm.15-branding.css',
  './assets/css/gm.16-overview.css',
  './assets/css/gm.17-service-costs.css',
  './assets/css/gm.18-user.css',
  './assets/css/gm.19-templates.css',
  './assets/css/gm.20-service-selector.css',
  './assets/css/gm.21-vehicle-details.css',
  './assets/css/gm.22-mobile-nav.css',
  './assets/css/gm.23-pwa.css',
  './assets/css/gm.24-theme-indicator.css',
  './assets/css/gm.25-gdrive.css',
  './assets/css/gm.26-offline.css',
  './assets/js/gm.core.js',
  './assets/js/gm.toast.js',
  './assets/js/gm.api.js',
  './assets/js/gm.state.js',
  './assets/js/gm.utils.js',
  './assets/js/gm.ui.js',
  './assets/js/gm.render.dashboard.js',
  './assets/js/gm.render.reminders.js',
  './assets/js/gm.render.settings.js',
  './assets/js/gm.features.attachments.js',
  './assets/js/gm.features.templates.js',
  './assets/js/gm.features.recalls.js',
  './assets/js/gm.features.export.js',
  './assets/js/gm.features.offline.js',
  './assets/js/gm.user.js',
  './assets/js/gm.handlers.js',
  './assets/js/gm.mobile-nav.js',
  './assets/js/gm.theme-indicator.js',
  './assets/js/gm.pwa.js',
  './assets/images/icon-32.png',
  './assets/images/icon-64.png',
  './assets/images/icon-192.png',
  './assets/images/icon-512.png',
  './manifest.json'
];

// External CDN assets to cache
const CDN_ASSETS = [
  'https://code.jquery.com/jquery-3.7.1.min.js',
  'https://code.jquery.com/ui/1.13.3/jquery-ui.min.js',
  'https://code.jquery.com/ui/1.13.3/themes/base/jquery-ui.css',
  'https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js',
  'https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.8.1/jspdf.plugin.autotable.min.js'
];

// ── Install ────────────────────────────────────────────────────────────────

self.addEventListener('install', (event) => {
  console.log(`[SW] Install — version ${APP_VERSION}`);

  event.waitUntil(
    Promise.all([
      // Cache static app shell
      caches.open(getStaticCacheName()).then((cache) => {
        console.log('[SW] Caching static assets');
        return cache.addAll(STATIC_ASSETS.map(url =>
          new Request(url, { cache: 'reload' })
        )).catch(err => {
          console.warn('[SW] Some static assets failed to cache:', err);
        });
      }),
      // Cache CDN assets
      caches.open(getDynCacheName()).then((cache) => {
        console.log('[SW] Caching CDN assets');
        return Promise.all(
          CDN_ASSETS.map(url =>
            fetch(url, { mode: 'cors' })
              .then(response => { if (response.ok) return cache.put(url, response); })
              .catch(err => console.warn('[SW] Failed to cache CDN:', url, err))
          )
        );
      })
    ]).then(() => {
      // Take over immediately — don't wait for old SW tabs to close
      return self.skipWaiting();
    })
  );
});

// ── Activate ──────────────────────────────────────────────────────────────

self.addEventListener('activate', (event) => {
  console.log(`[SW] Activate — version ${APP_VERSION}`);

  event.waitUntil(
    caches.keys().then((cacheNames) => {
      const currentCaches = [getStaticCacheName(), getDynCacheName()];
      return Promise.all(
        cacheNames.map((cacheName) => {
          // Delete any cache that doesn't belong to the current version
          if (!currentCaches.includes(cacheName)) {
            console.log('[SW] Deleting old cache:', cacheName);
            return caches.delete(cacheName);
          }
        })
      );
    }).then(() => {
      // Notify all open tabs that a new version is active
      return self.clients.matchAll({ type: 'window' }).then(clients => {
        clients.forEach(client => {
          client.postMessage({ type: 'SW_UPDATED', version: APP_VERSION });
        });
        return self.clients.claim();
      });
    })
  );
});

// ── Fetch ─────────────────────────────────────────────────────────────────

self.addEventListener('fetch', (event) => {
  const url = new URL(event.request.url);

  // Skip non-GET and all dynamic PHP endpoints
  // These must always hit the network (auth state, user data, uploads)
  if (
    event.request.method !== 'GET' ||
    url.pathname.includes('api.php') ||
    url.pathname.includes('upload.php') ||
    url.pathname.includes('download.php') ||
    url.pathname.includes('check-recalls.php') ||
    url.pathname.includes('vin-decode.php') ||
    url.pathname.includes('vehicle-photo.php') ||
    url.pathname.includes('backup-create.php') ||
    url.pathname.includes('restore-full.php') ||
    url.pathname.includes('delete-attachment.php') ||
    url.pathname.includes('google-drive') ||
    url.search.includes('action=') ||
    url.search.includes('_=')
  ) {
    return; // Browser handles normally
  }

  // Navigation requests: network-first, fallback to cached shell
  if (event.request.mode === 'navigate') {
    event.respondWith(
      fetch(event.request)
        .then((response) => {
          const clone = response.clone();
          caches.open(getStaticCacheName()).then(cache => cache.put(event.request, clone));
          return response;
        })
        .catch(() =>
          caches.match(event.request)
            .then(r => r || caches.match('./index.php'))
        )
    );
    return;
  }

  // Static assets: cache-first, fallback to network
  event.respondWith(
    caches.match(event.request).then((cached) => {
      if (cached) return cached;

      return fetch(event.request).then((response) => {
        if (!response || response.status !== 200 || response.type !== 'basic') {
          return response;
        }
        const clone = response.clone();
        const ct = response.headers.get('content-type') || '';
        if (
          ct.includes('text/css') ||
          ct.includes('application/javascript') ||
          ct.includes('image/') ||
          ct.includes('font/')
        ) {
          caches.open(getStaticCacheName()).then(cache => cache.put(event.request, clone));
        }
        return response;
      }).catch(() => {
        console.warn('[SW] Fetch failed:', event.request.url);
        return new Response('Offline', { status: 503, statusText: 'Service Unavailable' });
      });
    })
  );
});

// ── Messages ──────────────────────────────────────────────────────────────

self.addEventListener('message', (event) => {
  if (!event.data) return;

  // Page sends its APP_VERSION on load so the SW uses version-scoped caches
  if (event.data.type === 'SET_VERSION' && event.data.version) {
    const prev = APP_VERSION;
    APP_VERSION = event.data.version;
    if (prev !== APP_VERSION) {
      console.log(`[SW] Version updated: ${prev} -> ${APP_VERSION}`);
    }
  }

  if (event.data.type === 'SKIP_WAITING') {
    self.skipWaiting();
  }

  if (event.data.type === 'CLEAR_CACHE') {
    caches.keys().then(names =>
      Promise.all(names.map(n => caches.delete(n)))
    ).then(() => {
      if (event.ports && event.ports[0]) {
        event.ports[0].postMessage({ success: true });
      }
    });
  }
});

// ── Background sync (for offline queue flush) ─────────────────────────────

self.addEventListener('sync', (event) => {
  if (event.tag === 'gm-sync-queue') {
    console.log('[SW] Background sync triggered');
    // Notify the page to run syncPendingQueue()
    event.waitUntil(
      self.clients.matchAll({ type: 'window' }).then(clients => {
        clients.forEach(client => {
          client.postMessage({ type: 'TRIGGER_SYNC' });
        });
      })
    );
  }
});

// ── Push notifications ────────────────────────────────────────────────────

self.addEventListener('push', (event) => {
  if (event.data) {
    const d = event.data.json();
    event.waitUntil(
      self.registration.showNotification(d.title || 'TrackMyWrench', {
        body:    d.body    || 'Maintenance reminder',
        icon:    './assets/images/icon-192.png',
        badge:   './assets/images/icon-192.png',
        vibrate: [100, 50, 100],
        data:    { url: d.url || './' }
      })
    );
  }
});

self.addEventListener('notificationclick', (event) => {
  event.notification.close();
  event.waitUntil(
    clients.matchAll({ type: 'window' }).then(list => {
      for (const c of list) {
        if (c.url.includes('index.php') && 'focus' in c) return c.focus();
      }
      if (clients.openWindow) {
        return clients.openWindow(event.notification.data.url || './');
      }
    })
  );
});
