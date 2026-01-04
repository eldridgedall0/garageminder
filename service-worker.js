/**
 * TrackMyWrench Service Worker
 * Provides offline caching and PWA functionality
 * Multi-user compatible - caches static assets only
 */

const CACHE_NAME = 'trackmywrench-v1';
const STATIC_CACHE_NAME = 'trackmywrench-static-v1';

// Static assets to cache (shared across all users)
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
  './assets/css/gm.22-pwa.css',
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
  './assets/js/gm.user.js',
  './assets/js/gm.handlers.js',
  './assets/js/gm.pwa.js',
  './assets/images/icon-16.png',
  './assets/images/icon-32.png',
  './assets/images/icon-64.png',
  './assets/images/icon-180.png',
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

// Install event - cache static assets
self.addEventListener('install', (event) => {
  console.log('[ServiceWorker] Install');
  
  event.waitUntil(
    Promise.all([
      // Cache static assets
      caches.open(STATIC_CACHE_NAME).then((cache) => {
        console.log('[ServiceWorker] Caching static assets');
        return cache.addAll(STATIC_ASSETS.map(url => {
          return new Request(url, { cache: 'reload' });
        })).catch(err => {
          console.warn('[ServiceWorker] Some static assets failed to cache:', err);
        });
      }),
      // Cache CDN assets
      caches.open(CACHE_NAME).then((cache) => {
        console.log('[ServiceWorker] Caching CDN assets');
        return Promise.all(
          CDN_ASSETS.map(url => {
            return fetch(url, { mode: 'cors' })
              .then(response => {
                if (response.ok) {
                  return cache.put(url, response);
                }
              })
              .catch(err => {
                console.warn('[ServiceWorker] Failed to cache CDN asset:', url, err);
              });
          })
        );
      })
    ]).then(() => {
      // Force the waiting service worker to become active
      return self.skipWaiting();
    })
  );
});

// Activate event - clean up old caches
self.addEventListener('activate', (event) => {
  console.log('[ServiceWorker] Activate');
  
  event.waitUntil(
    caches.keys().then((cacheNames) => {
      return Promise.all(
        cacheNames.map((cacheName) => {
          if (cacheName !== CACHE_NAME && cacheName !== STATIC_CACHE_NAME) {
            console.log('[ServiceWorker] Removing old cache:', cacheName);
            return caches.delete(cacheName);
          }
        })
      );
    }).then(() => {
      // Take control of all clients immediately
      return self.clients.claim();
    })
  );
});

// Fetch event - serve from cache, fallback to network
self.addEventListener('fetch', (event) => {
  const url = new URL(event.request.url);
  
  // Skip API calls and POST requests - these must always go to network
  // This ensures multi-user data is never cached
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
    url.search.includes('action=') ||
    url.search.includes('_=') // jQuery cache buster
  ) {
    return; // Let the browser handle this request normally
  }
  
  // For navigation requests (HTML pages), use network-first strategy
  if (event.request.mode === 'navigate') {
    event.respondWith(
      fetch(event.request)
        .then((response) => {
          // Clone and cache the response
          const responseClone = response.clone();
          caches.open(STATIC_CACHE_NAME).then((cache) => {
            cache.put(event.request, responseClone);
          });
          return response;
        })
        .catch(() => {
          // Fallback to cache if network fails
          return caches.match(event.request).then((response) => {
            if (response) {
              return response;
            }
            // Return offline page if available
            return caches.match('./index.php');
          });
        })
    );
    return;
  }
  
  // For static assets, use cache-first strategy
  event.respondWith(
    caches.match(event.request).then((cachedResponse) => {
      if (cachedResponse) {
        // Return cached version
        return cachedResponse;
      }
      
      // Not in cache, fetch from network
      return fetch(event.request).then((response) => {
        // Don't cache non-successful responses
        if (!response || response.status !== 200 || response.type !== 'basic') {
          return response;
        }
        
        // Clone and cache successful responses for static assets
        const responseClone = response.clone();
        
        // Only cache CSS, JS, images, and fonts
        const contentType = response.headers.get('content-type') || '';
        if (
          contentType.includes('text/css') ||
          contentType.includes('application/javascript') ||
          contentType.includes('image/') ||
          contentType.includes('font/')
        ) {
          caches.open(STATIC_CACHE_NAME).then((cache) => {
            cache.put(event.request, responseClone);
          });
        }
        
        return response;
      }).catch(() => {
        // Network failed and not in cache
        console.warn('[ServiceWorker] Fetch failed for:', event.request.url);
        return new Response('Offline', { status: 503, statusText: 'Service Unavailable' });
      });
    })
  );
});

// Handle messages from the main app
self.addEventListener('message', (event) => {
  if (event.data && event.data.type === 'SKIP_WAITING') {
    self.skipWaiting();
  }
  
  if (event.data && event.data.type === 'CLEAR_CACHE') {
    caches.keys().then((cacheNames) => {
      return Promise.all(
        cacheNames.map((cacheName) => caches.delete(cacheName))
      );
    }).then(() => {
      event.ports[0].postMessage({ success: true });
    });
  }
});

// Background sync for offline entries (future enhancement)
self.addEventListener('sync', (event) => {
  if (event.tag === 'sync-entries') {
    console.log('[ServiceWorker] Background sync triggered');
    // Could implement offline entry sync here
  }
});

// Push notifications (future enhancement)
self.addEventListener('push', (event) => {
  if (event.data) {
    const data = event.data.json();
    const options = {
      body: data.body || 'Maintenance reminder',
      icon: './assets/images/icon-192.png',
      badge: './assets/images/icon-192.png',
      vibrate: [100, 50, 100],
      data: {
        url: data.url || './'
      }
    };
    
    event.waitUntil(
      self.registration.showNotification(data.title || 'TrackMyWrench', options)
    );
  }
});

// Notification click handler
self.addEventListener('notificationclick', (event) => {
  event.notification.close();
  
  event.waitUntil(
    clients.matchAll({ type: 'window' }).then((clientList) => {
      // If app is already open, focus it
      for (const client of clientList) {
        if (client.url.includes('index.php') && 'focus' in client) {
          return client.focus();
        }
      }
      // Otherwise open new window
      if (clients.openWindow) {
        return clients.openWindow(event.notification.data.url || './');
      }
    })
  );
});