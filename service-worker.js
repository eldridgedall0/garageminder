/**
 * TrackMyWrench Service Worker v2.5.0
 *
 * VERSION STRATEGY:
 *   SW_VERSION is hardcoded — update alongside APP_VERSION in config.php on every
 *   deploy that changes cached files. SET_VERSION postMessage is kept for runtime
 *   update-banner awareness only; it no longer controls cache naming, so a cold
 *   offline launch always finds caches under the correct name.
 *
 * INSTALL STRATEGY:
 *   CORE assets are cached individually in parallel. Any failure is tracked and
 *   reported via INSTALL_INCOMPLETE so the page can warn the user to stay online.
 *   OPTIONAL assets (CDN, images) are tolerated silently.
 *
 * HEALTH CHECK:
 *   Page sends VERIFY_CACHE after every successful online data load.
 *   SW checks all CORE assets, re-fetches missing ones, reports result back.
 *   Self-heals a partial install with no user action required.
 */

// ── Version — keep in sync with APP_VERSION in config.php ──────────────────
const SW_VERSION = '2.5.0';

// Page version (runtime awareness / update banners — NOT used for cache naming)
let PAGE_VERSION = SW_VERSION;

const STATIC_CACHE = `tmw-static-${SW_VERSION}`;
const DYN_CACHE    = `tmw-dyn-${SW_VERSION}`;

// ── Asset lists ─────────────────────────────────────────────────────────────

// CORE: must all be cached for the app shell to work offline
const CORE_ASSETS = [
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
  './manifest.json',
];

// OPTIONAL: cached opportunistically — failures are silent
const OPTIONAL_ASSETS = [
  './',
  './assets/images/icon-32.png',
  './assets/images/icon-64.png',
  './assets/images/icon-192.png',
  './assets/images/icon-512.png',
  'https://code.jquery.com/jquery-3.7.1.min.js',
  'https://code.jquery.com/ui/1.13.3/jquery-ui.min.js',
  'https://code.jquery.com/ui/1.13.3/themes/base/jquery-ui.css',
  'https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js',
  'https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.8.1/jspdf.plugin.autotable.min.js',
];

// PHP endpoints — never intercept these
const BYPASS_PATHS = [
  'api.php', 'upload.php', 'download.php', 'check-recalls.php',
  'vin-decode.php', 'vehicle-photo.php', 'backup-create.php',
  'restore-full.php', 'delete-attachment.php', 'google-drive',
];

// ── Helpers ─────────────────────────────────────────────────────────────────

async function cacheOne(cache, url) {
  try {
    const res = await fetch(new Request(url, { cache: 'reload' }));
    if (!res.ok) { console.warn(`[SW] ${res.status}: ${url}`); return false; }
    await cache.put(new Request(url), res);
    return true;
  } catch (e) {
    console.warn(`[SW] fetch failed: ${url}`, e.message);
    return false;
  }
}

async function broadcast(msg) {
  const list = await self.clients.matchAll({ type: 'window' });
  list.forEach(c => c.postMessage(msg));
}

// ── Install ──────────────────────────────────────────────────────────────────

self.addEventListener('install', (event) => {
  console.log(`[SW] Install v${SW_VERSION}`);
  event.waitUntil((async () => {
    const [sc, dc] = await Promise.all([caches.open(STATIC_CACHE), caches.open(DYN_CACHE)]);

    // Core: parallel, track failures
    const coreOk = await Promise.all(CORE_ASSETS.map(u => cacheOne(sc, u)));
    const failed = CORE_ASSETS.filter((_, i) => !coreOk[i]);
    self._installFailures = failed;
    if (failed.length) console.error('[SW] Core cache failures:', failed);
    else console.log('[SW] All core assets cached');

    // Optional: parallel, silent failures
    await Promise.all(OPTIONAL_ASSETS.map(u => cacheOne(u.startsWith('http') ? dc : sc, u).catch(() => {})));

    await self.skipWaiting();
  })());
});

// ── Activate ─────────────────────────────────────────────────────────────────

self.addEventListener('activate', (event) => {
  console.log(`[SW] Activate v${SW_VERSION}`);
  event.waitUntil((async () => {
    // Prune stale version caches
    const names = await caches.keys();
    await Promise.all(names.map(n => (n === STATIC_CACHE || n === DYN_CACHE) ? null : caches.delete(n)));
    await self.clients.claim();

    const failures = self._installFailures || [];
    await broadcast(failures.length
      ? { type: 'INSTALL_INCOMPLETE', missing: failures, version: SW_VERSION }
      : { type: 'SW_UPDATED', version: SW_VERSION }
    );
  })());
});

// ── Fetch ─────────────────────────────────────────────────────────────────────

self.addEventListener('fetch', (event) => {
  const { request } = event;
  if (request.method !== 'GET') return;
  const url = new URL(request.url);
  if (BYPASS_PATHS.some(p => url.pathname.includes(p)) ||
      url.search.includes('action=') || url.search.includes('_=')) return;

  // Navigation: network-first → cached shell → offline page
  if (request.mode === 'navigate') {
    event.respondWith((async () => {
      try {
        const res = await fetch(request);
        (await caches.open(STATIC_CACHE)).put(request, res.clone());
        return res;
      } catch {
        return (await caches.match(request)) ||
               (await caches.match('./index.php')) ||
               new Response(
                 '<!doctype html><html><head><meta charset="utf-8"><title>Offline</title></head>' +
                 '<body style="font-family:sans-serif;padding:2rem;text-align:center">' +
                 '<h2>You\'re Offline</h2>' +
                 '<p>Open the app while online at least once to enable offline access.</p>' +
                 '</body></html>',
                 { headers: { 'Content-Type': 'text/html' } }
               );
      }
    })());
    return;
  }

  // Static assets: cache-first → network → store on hit
  event.respondWith((async () => {
    const cached = await caches.match(request);
    if (cached) return cached;
    try {
      const res = await fetch(request);
      if (res.ok) {
        const ct = res.headers.get('content-type') || '';
        if (ct.includes('css') || ct.includes('javascript') ||
            ct.includes('image') || ct.includes('font')) {
          const c = await caches.open(url.origin === self.location.origin ? STATIC_CACHE : DYN_CACHE);
          c.put(request, res.clone());
        }
      }
      return res;
    } catch {
      return new Response('', { status: 503, statusText: 'Offline' });
    }
  })());
});

// ── Messages ──────────────────────────────────────────────────────────────────

self.addEventListener('message', (event) => {
  if (!event.data) return;
  const { type, version } = event.data;

  if (type === 'SET_VERSION' && version && PAGE_VERSION !== version) {
    console.log(`[SW] Page v${PAGE_VERSION} → ${version}`);
    PAGE_VERSION = version;
  }

  if (type === 'SKIP_WAITING') self.skipWaiting();

  // Health check: verify CORE assets are cached, re-cache any missing
  if (type === 'VERIFY_CACHE') {
    event.waitUntil((async () => {
      const cache   = await caches.open(STATIC_CACHE);
      const checks  = await Promise.all(CORE_ASSETS.map(u => caches.match(new Request(u))));
      const missing = CORE_ASSETS.filter((_, i) => !checks[i]);

      if (missing.length === 0) {
        event.ports?.[0]?.postMessage({ type: 'CACHE_HEALTHY', version: SW_VERSION });
        return;
      }

      console.log(`[SW] Healing ${missing.length} missing asset(s)`);
      const healed = await Promise.all(missing.map(u => cacheOne(cache, u)));
      const stillMissing = missing.filter((_, i) => !healed[i]);

      event.ports?.[0]?.postMessage({
        type:    stillMissing.length === 0 ? 'CACHE_HEALED' : 'CACHE_INCOMPLETE',
        healed:  missing.filter((_, i) =>  healed[i]),
        missing: stillMissing,
        version: SW_VERSION,
      });
    })());
  }

  if (type === 'CLEAR_CACHE') {
    event.waitUntil(
      caches.keys()
        .then(n => Promise.all(n.map(name => caches.delete(name))))
        .then(() => event.ports?.[0]?.postMessage({ success: true }))
    );
  }
});

// ── Background sync ───────────────────────────────────────────────────────────

self.addEventListener('sync', (event) => {
  if (event.tag === 'gm-sync-queue') {
    event.waitUntil(broadcast({ type: 'TRIGGER_SYNC' }));
  }
});

// ── Push notifications ────────────────────────────────────────────────────────

self.addEventListener('push', (event) => {
  if (!event.data) return;
  const d = event.data.json();
  event.waitUntil(
    self.registration.showNotification(d.title || 'TrackMyWrench', {
      body:    d.body || 'Maintenance reminder',
      icon:    './assets/images/icon-192.png',
      badge:   './assets/images/icon-192.png',
      vibrate: [100, 50, 100],
      data:    { url: d.url || './' },
    })
  );
});

self.addEventListener('notificationclick', (event) => {
  event.notification.close();
  event.waitUntil(
    clients.matchAll({ type: 'window' }).then(list => {
      for (const c of list) {
        if (c.url.includes('index.php') && 'focus' in c) return c.focus();
      }
      return clients.openWindow?.(event.notification.data?.url || './');
    })
  );
});
