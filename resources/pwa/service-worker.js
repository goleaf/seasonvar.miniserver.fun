const CACHE_VERSION = __CACHE_VERSION__;
const PRECACHE_URLS = __PRECACHE_URLS__;
const PUSH_COPY = __PUSH_COPY__;
const SHELL_CACHE = `seasonvar-shell-${CACHE_VERSION}`;
const OFFLINE_URL = '/offline';
const PRIVATE_PATHS = [
    '/pwa/library-snapshot',
    '/pwa/session',
    '/pwa/actions',
    '/pwa/push-subscriptions',
    '/pwa/posters/',
    '/api/',
    '/settings',
    '/profile',
    '/notifications',
    '/library',
    '/my/',
    '/admin',
    '/playback/',
    '/download',
];
const MEDIA_SUFFIXES = ['.m3u8', '.m4s', '.ts', '.mpd', '.mp4', '.webm', '.mp3', '.aac'];

const requestIsForbidden = (request) => {
    if (request.method !== 'GET' || request.headers.has('Authorization')) {
        return true;
    }

    if (request.destination === 'video' || request.destination === 'audio') {
        return true;
    }

    const url = new URL(request.url);

    if (url.origin !== self.location.origin) {
        return true;
    }

    const path = url.pathname.toLowerCase();

    return PRIVATE_PATHS.some((prefix) => path.startsWith(prefix))
        || MEDIA_SUFFIXES.some((suffix) => path.endsWith(suffix));
};

const cachePublicShellAsset = async (cache, url) => {
    const response = await fetch(url, { credentials: 'same-origin', cache: 'reload' });

    if (response.ok && response.type === 'basic' && !response.headers.has('Set-Cookie')) {
        await cache.put(url, response);
    }
};

self.addEventListener('install', (event) => {
    event.waitUntil(
        caches.open(SHELL_CACHE).then((cache) => Promise.all(
            PRECACHE_URLS.map((url) => cachePublicShellAsset(cache, url)),
        )),
    );
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys()
            .then((keys) => Promise.all(
                keys
                    .filter((key) => key.startsWith('seasonvar-shell-') && key !== SHELL_CACHE)
                    .map((key) => caches.delete(key)),
            ))
            .then(() => self.clients.claim()),
    );
});

self.addEventListener('fetch', (event) => {
    const { request } = event;

    if (requestIsForbidden(request)) {
        return;
    }

    const url = new URL(request.url);

    if (request.mode === 'navigate') {
        event.respondWith(
            fetch(request).catch(() => caches.match(OFFLINE_URL)),
        );
        return;
    }

    if (PRECACHE_URLS.includes(url.pathname)) {
        event.respondWith(
            caches.match(request).then((cached) => cached || fetch(request)),
        );
    }
});

const notifyOpenClients = async (message) => {
    const clients = await self.clients.matchAll({ includeUncontrolled: true, type: 'window' });

    clients.forEach((client) => client.postMessage(message));
};

self.addEventListener('sync', (event) => {
    if (event.tag === 'seasonvar-safe-actions') {
        event.waitUntil(notifyOpenClients({ type: 'pwa-sync-requested' }));
    }
});

const readLocale = () => new Promise((resolve) => {
    const request = indexedDB.open('seasonvar-pwa', 1);

    request.onerror = () => resolve('ru');
    request.onupgradeneeded = () => {
        const database = request.result;

        if (!database.objectStoreNames.contains('meta')) {
            database.createObjectStore('meta', { keyPath: 'key' });
        }

        if (!database.objectStoreNames.contains('snapshots')) {
            database.createObjectStore('snapshots', { keyPath: 'key' });
        }

        if (!database.objectStoreNames.contains('actions')) {
            const actions = database.createObjectStore('actions', { keyPath: 'mutation_id' });
            actions.createIndex('queued_at', 'queued_at');
        }
    };
    request.onsuccess = () => {
        const database = request.result;

        if (!database.objectStoreNames.contains('meta')) {
            database.close();
            resolve('ru');
            return;
        }

        const transaction = database.transaction('meta', 'readonly');
        const localeRequest = transaction.objectStore('meta').get('locale');

        localeRequest.onerror = () => resolve('ru');
        localeRequest.onsuccess = () => resolve(localeRequest.result?.value === 'en' ? 'en' : 'ru');
        transaction.oncomplete = () => database.close();
    };
});

self.addEventListener('push', (event) => {
    event.waitUntil(
        readLocale().then((locale) => {
            const copy = PUSH_COPY[locale] || PUSH_COPY.ru;

            return self.registration.showNotification(copy.title, {
                body: copy.body,
                icon: '/icons/pwa-192.png',
                badge: '/icons/pwa-192.png',
                tag: 'seasonvar-notification',
                renotify: false,
                data: { url: '/notifications' },
            });
        }),
    );
});

self.addEventListener('notificationclick', (event) => {
    event.notification.close();
    event.waitUntil(
        self.clients.matchAll({ includeUncontrolled: true, type: 'window' }).then((clients) => {
            const existing = clients.find((client) => new URL(client.url).pathname === '/notifications');

            if (existing) {
                return existing.focus();
            }

            return self.clients.openWindow('/notifications');
        }),
    );
});
