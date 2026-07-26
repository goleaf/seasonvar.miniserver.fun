import {
    clearAccountData,
    enqueueSafeAction,
    getMeta,
    queuedActions,
    readHelpSnapshot,
    readLibrarySnapshot,
    readSafeActionIssues,
    reconcileSafeActionResults,
    saveHelpSnapshot,
    saveLibrarySnapshot,
    setMeta,
    updateLocalRating,
    updateLocalWatchlist,
} from './pwa-storage.js';

const POSTER_CACHE_PREFIX = 'seasonvar-posters-';
const MAX_POSTER_ITEMS = 80;
const MAX_POSTER_PREFETCH = 12;
const POSTER_PREFETCH_CONCURRENCY = 3;
let registrationPromise = null;
let initialized = false;
let sessionContext = {};
let runtimeCsrfToken = '';

const bodyData = () => document.body?.dataset ?? {};
const runtimeData = () => ({ ...bodyData(), ...sessionContext });

const csrfToken = () => runtimeCsrfToken
    || document.querySelector('meta[name="csrf-token"]')?.getAttribute('content')
    || '';

const jsonRequest = async (url, options = {}) => {
    const headers = {
        Accept: 'application/json',
        ...options.headers,
    };
    const token = csrfToken();

    if (token) {
        headers['X-CSRF-TOKEN'] = token;
    }

    const response = await fetch(url, {
        credentials: 'same-origin',
        ...options,
        headers,
    });

    if (!response.ok) {
        const error = new Error(`PWA request failed with ${response.status}.`);

        error.status = response.status;
        throw error;
    }

    return response.json();
};

const registerWorker = () => {
    const data = runtimeData();

    if (
        data.pwaEnabled !== '1'
        || !window.isSecureContext
        || !('serviceWorker' in navigator)
    ) {
        return Promise.resolve(null);
    }

    registrationPromise ??= navigator.serviceWorker.register(
        data.pwaServiceWorkerUrl || '/service-worker.js',
        { scope: '/' },
    ).catch(() => null);

    return registrationPromise;
};

const accountScope = async () => runtimeData().pwaAccountScope || await getMeta('lastAccountScope');

const locale = () => (document.documentElement.lang || 'ru').split('-')[0].toLowerCase();

const posterCacheName = (scope) => `${POSTER_CACHE_PREFIX}${scope.slice(0, 24)}`;

const clearOwnerStorage = async (scope) => {
    if (!scope) {
        return;
    }

    const tasks = [clearAccountData(scope)];

    if ('caches' in window) {
        tasks.push(caches.delete(posterCacheName(scope)));
    }

    await Promise.allSettled(tasks);
};

const clearPreviousAccountScope = async (nextScope) => {
    const previousScope = await getMeta('lastAccountScope');

    if (previousScope && previousScope !== nextScope) {
        await clearOwnerStorage(previousScope);
    }

    if (nextScope) {
        await setMeta('lastAccountScope', nextScope);
    }
};

const trimPosterCache = async (cache) => {
    const keys = await cache.keys();

    await Promise.all(keys.slice(0, Math.max(0, keys.length - MAX_POSTER_ITEMS)).map((key) => cache.delete(key)));
};

const cachePosters = async (scope, items) => {
    if (!('caches' in window) || !scope || !navigator.onLine) {
        return;
    }

    const urls = items
        .map((item) => item.poster_url)
        .filter((url) => typeof url === 'string' && url.startsWith('/pwa/posters/'))
        .slice(0, MAX_POSTER_PREFETCH);
    const cache = await caches.open(posterCacheName(scope));

    const cachePoster = async (url) => {
        try {
            if (await cache.match(url)) {
                return;
            }

            const response = await fetch(url, {
                credentials: 'same-origin',
                redirect: 'error',
            });
            const contentType = response.headers.get('Content-Type') || '';

            if (response.ok && contentType.startsWith('image/')) {
                await cache.put(url, response);
            }
        } catch {
            // A missing poster must not block the remaining offline snapshot.
        }
    };

    for (let index = 0; index < urls.length; index += POSTER_PREFETCH_CONCURRENCY) {
        await Promise.all(
            urls
                .slice(index, index + POSTER_PREFETCH_CONCURRENCY)
                .map(cachePoster),
        );
    }

    await trimPosterCache(cache);
};

const refreshSnapshots = async () => {
    if (!navigator.onLine) {
        return;
    }

    const data = runtimeData();
    const currentLocale = locale();

    if (data.pwaHelpSnapshotUrl) {
        try {
            const payload = await jsonRequest(data.pwaHelpSnapshotUrl);
            await saveHelpSnapshot(currentLocale, payload.data);
        } catch {
            // The previous public help snapshot remains usable.
        }
    }

    if (data.pwaAccountScope && data.pwaLibrarySnapshotUrl) {
        try {
            const payload = await jsonRequest(data.pwaLibrarySnapshotUrl);
            await saveLibrarySnapshot(data.pwaAccountScope, payload.data);
            await cachePosters(data.pwaAccountScope, payload.data.items || []);
        } catch {
            // The previous owner-scoped library snapshot remains usable.
        }
    }
};

const flushSafeActions = async () => {
    const data = runtimeData();
    const scope = data.pwaAccountScope;

    if (!navigator.onLine || !scope || !data.pwaActionUrl) {
        return;
    }

    const operations = await queuedActions(scope);

    if (!operations.length) {
        return;
    }

    try {
        const payload = await jsonRequest(data.pwaActionUrl, {
            method: 'POST',
            body: JSON.stringify({ operations }),
            headers: { 'Content-Type': 'application/json' },
        });
        const results = payload.data?.results || [];

        await reconcileSafeActionResults(scope, results);

        if (results.some((result) => ['applied', 'duplicate'].includes(result.status))) {
            await refreshSnapshots();
        }
    } catch {
        const registration = await registerWorker();

        if (registration && 'sync' in registration) {
            await registration.sync.register('seasonvar-safe-actions').catch(() => undefined);
        }
    }
};

const uuid = () => {
    if (typeof crypto.randomUUID === 'function') {
        return crypto.randomUUID();
    }

    const bytes = crypto.getRandomValues(new Uint8Array(16));
    bytes[6] = (bytes[6] & 0x0f) | 0x40;
    bytes[8] = (bytes[8] & 0x3f) | 0x80;
    const hex = Array.from(bytes, (value) => value.toString(16).padStart(2, '0')).join('');

    return `${hex.slice(0, 8)}-${hex.slice(8, 12)}-${hex.slice(12, 16)}-${hex.slice(16, 20)}-${hex.slice(20)}`;
};

const installationId = async () => {
    const existing = await getMeta('installationId');

    if (typeof existing === 'string' && existing.length >= 32) {
        return existing;
    }

    const created = uuid();
    await setMeta('installationId', created);

    return created;
};

const applicationServerKey = (encoded) => {
    const padding = '='.repeat((4 - encoded.length % 4) % 4);
    const raw = atob((encoded + padding).replace(/-/g, '+').replace(/_/g, '/'));

    return Uint8Array.from(raw, (character) => character.charCodeAt(0));
};

const pushStatus = (state) => {
    document.querySelectorAll('[data-pwa-push-status]').forEach((element) => {
        const message = element.dataset[`pwaPush${state[0].toUpperCase()}${state.slice(1)}`];

        if (message) {
            element.textContent = message;
        }
    });
};

const enablePush = async (button) => {
    const data = runtimeData();
    const registration = await registerWorker();

    if (
        !registration
        || !data.pwaVapidPublicKey
        || !data.pwaPushSubscriptionUrl
        || !('PushManager' in window)
        || !('Notification' in window)
    ) {
        pushStatus('unsupported');
        return;
    }

    button.disabled = true;

    try {
        const permission = await Notification.requestPermission();

        if (permission !== 'granted') {
            pushStatus('denied');
            return;
        }

        let subscription = await registration.pushManager.getSubscription();
        subscription ??= await registration.pushManager.subscribe({
            userVisibleOnly: true,
            applicationServerKey: applicationServerKey(data.pwaVapidPublicKey),
        });

        await jsonRequest(data.pwaPushSubscriptionUrl, {
            method: 'POST',
            body: JSON.stringify({
                installation_id: await installationId(),
                endpoint: subscription.endpoint,
                locale: locale(),
            }),
            headers: { 'Content-Type': 'application/json' },
        });
        pushStatus('enabled');
    } catch {
        pushStatus('error');
    } finally {
        button.disabled = false;
    }
};

const disablePush = async (button) => {
    const data = runtimeData();
    const registration = await registerWorker();

    if (!registration || !data.pwaPushSubscriptionUrl || !('PushManager' in window)) {
        pushStatus('unsupported');
        return;
    }

    button.disabled = true;

    try {
        const subscription = await registration.pushManager.getSubscription();
        const currentInstallationId = await installationId();

        await jsonRequest(data.pwaPushSubscriptionUrl, {
            method: 'DELETE',
            body: JSON.stringify({ installation_id: currentInstallationId }),
            headers: { 'Content-Type': 'application/json' },
        });
        await subscription?.unsubscribe();
        pushStatus('disabled');
    } catch {
        pushStatus('error');
    } finally {
        button.disabled = false;
    }
};

const refreshPushStatus = async () => {
    if (!document.querySelector('[data-pwa-push-controls]')) {
        return;
    }

    const data = runtimeData();
    const registration = await registerWorker();

    if (!registration || !data.pwaVapidPublicKey || !('PushManager' in window) || !('Notification' in window)) {
        pushStatus('unsupported');
        return;
    }

    if (Notification.permission === 'denied') {
        pushStatus('denied');
        return;
    }

    const subscription = await registration.pushManager.getSubscription();
    pushStatus(subscription ? 'enabled' : 'disabled');
};

const renderPoster = async (scope, item, image) => {
    if (!item.poster_url || !('caches' in window)) {
        return;
    }

    const cache = await caches.open(posterCacheName(scope));
    const response = await cache.match(item.poster_url);

    if (response) {
        const blob = await response.blob();
        const objectUrl = URL.createObjectURL(blob);
        const releaseObjectUrl = () => URL.revokeObjectURL(objectUrl);

        image.addEventListener('load', releaseObjectUrl, { once: true });
        image.addEventListener('error', releaseObjectUrl, { once: true });
        image.src = objectUrl;
    }
};

const renderSavedAt = (root, selector, savedAt) => {
    const element = root.querySelector(selector);

    if (!element || !Number.isFinite(savedAt)) {
        if (element) {
            element.hidden = true;
            element.textContent = '';
        }

        return;
    }

    const savedDate = new Date(savedAt);

    if (Number.isNaN(savedDate.getTime())) {
        return;
    }

    element.dateTime = savedDate.toISOString();
    element.textContent = `${root.dataset.pwaSavedAtLabel}: ${savedDate.toLocaleString()}`;
    element.hidden = false;
};

const renderQueueIssues = async (root, scope) => {
    const status = root.querySelector('[data-pwa-offline-queue-status]');

    if (!status || !scope) {
        return;
    }

    const issues = await readSafeActionIssues(scope);

    if (issues.length > 0) {
        status.textContent = root.dataset.pwaQueueIssueLabel;
        status.classList.remove('text-emerald-800');
        status.classList.add('text-amber-800');
    }
};

const renderOfflineLibrary = async (root, scope) => {
    const list = root.querySelector('[data-pwa-offline-library-list]');
    const empty = root.querySelector('[data-pwa-offline-library-empty]');

    if (!list || !empty) {
        return;
    }

    list.replaceChildren();

    if (!scope) {
        empty?.removeAttribute('hidden');
        return;
    }

    const snapshot = await readLibrarySnapshot(scope);
    const items = snapshot?.items || [];
    empty.toggleAttribute('hidden', items.length > 0);
    renderSavedAt(root, '[data-pwa-offline-library-saved-at]', snapshot?.saved_at);

    for (const item of items) {
        const entry = document.createElement('li');
        entry.className = 'flex min-w-0 items-center gap-3 rounded-control border border-slate-200 bg-white p-3';
        const image = document.createElement('img');
        image.className = 'h-20 w-14 shrink-0 rounded-control bg-slate-100 object-cover';
        image.alt = '';
        image.width = 56;
        image.height = 80;
        const content = document.createElement('div');
        content.className = 'min-w-0 flex-1';
        const title = document.createElement('p');
        title.className = 'break-words font-black text-slate-900';
        title.textContent = item.title;
        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'mt-2 min-h-11 rounded-control bg-slate-100 px-3 py-2 text-sm font-bold text-slate-700';
        button.textContent = item.in_watchlist ? root.dataset.pwaRemoveLabel : root.dataset.pwaAddLabel;
        button.addEventListener('click', async () => {
            const nextValue = !item.in_watchlist;
            const expectedVersion = item.versions.watchlist;

            await enqueueSafeAction(scope, {
                mutation_id: uuid(),
                type: 'watchlist.set',
                title_slug: item.slug,
                value: nextValue,
                expected_version: expectedVersion,
            });
            item.in_watchlist = nextValue;
            item.versions.watchlist = expectedVersion + 1;
            await updateLocalWatchlist(scope, item.slug, nextValue, item.versions.watchlist);
            button.textContent = nextValue ? root.dataset.pwaRemoveLabel : root.dataset.pwaAddLabel;
            root.querySelector('[data-pwa-offline-queue-status]').textContent = root.dataset.pwaQueuedLabel;
        });
        const ratingLabel = document.createElement('label');
        const ratingSelect = document.createElement('select');
        const ratingId = `pwa-rating-${item.slug}`;

        ratingLabel.htmlFor = ratingId;
        ratingLabel.className = 'mt-3 block text-sm font-bold text-slate-700';
        ratingLabel.textContent = root.dataset.pwaRatingLabel;
        ratingSelect.id = ratingId;
        ratingSelect.className = 'mt-1 min-h-11 rounded-control border border-slate-300 bg-white px-3 py-2 text-sm font-bold text-slate-800';

        const clearRating = document.createElement('option');
        clearRating.value = '';
        clearRating.textContent = root.dataset.pwaRatingClearLabel;
        ratingSelect.append(clearRating);

        for (let rating = 1; rating <= 10; rating += 1) {
            const option = document.createElement('option');
            option.value = String(rating);
            option.textContent = String(rating);
            ratingSelect.append(option);
        }

        ratingSelect.value = item.rating === null ? '' : String(item.rating);
        ratingSelect.addEventListener('change', async () => {
            const nextValue = ratingSelect.value === '' ? null : Number(ratingSelect.value);
            const expectedVersion = item.versions.rating;

            await enqueueSafeAction(scope, {
                mutation_id: uuid(),
                type: 'rating.set',
                title_slug: item.slug,
                value: nextValue,
                expected_version: expectedVersion,
            });
            item.rating = nextValue;
            item.versions.rating = expectedVersion + 1;
            await updateLocalRating(scope, item.slug, nextValue, item.versions.rating);
            root.querySelector('[data-pwa-offline-queue-status]').textContent = root.dataset.pwaQueuedLabel;
        });
        content.append(title, button, ratingLabel, ratingSelect);
        entry.append(image, content);
        list.append(entry);
        void renderPoster(scope, item, image);
    }
};

const renderOfflineHelp = async (root) => {
    const list = root.querySelector('[data-pwa-offline-help-list]');
    const empty = root.querySelector('[data-pwa-offline-help-empty]');

    if (!list || !empty) {
        return;
    }

    list.replaceChildren();
    const snapshot = await readHelpSnapshot(locale());
    const items = snapshot?.items || [];
    empty.toggleAttribute('hidden', items.length > 0);
    renderSavedAt(root, '[data-pwa-offline-help-saved-at]', snapshot?.saved_at);

    items.forEach((item) => {
        const entry = document.createElement('li');
        entry.className = 'rounded-control border border-slate-200 bg-white p-4';
        const title = document.createElement('h3');
        title.className = 'font-black text-slate-900';
        title.textContent = item.title;
        const summary = document.createElement('p');
        summary.className = 'mt-2 text-sm leading-6 text-slate-600';
        summary.textContent = item.summary || item.body;
        entry.append(title, summary);
        list.append(entry);
    });
};

const renderOfflineShell = async () => {
    const root = document.querySelector('[data-pwa-offline-shell]');

    if (!root) {
        return;
    }

    const scope = await accountScope();
    await Promise.all([
        renderOfflineLibrary(root, scope),
        renderOfflineHelp(root),
    ]);
    await renderQueueIssues(root, scope);
};

const bindControls = () => {
    document.addEventListener('click', (event) => {
        const enable = event.target.closest('[data-pwa-push-enable]');
        const disable = event.target.closest('[data-pwa-push-disable]');

        if (enable) {
            void enablePush(enable);
        } else if (disable) {
            void disablePush(disable);
        }
    });

};

const bindLogoutCleanup = () => {
    document.querySelectorAll('[data-pwa-logout]').forEach((button) => {
        button.addEventListener('click', async (event) => {
            if (button.dataset.pwaLogoutCleanupComplete === '1') {
                return;
            }

            event.preventDefault();
            event.stopImmediatePropagation();
            const scope = runtimeData().pwaAccountScope;

            if (scope) {
                await clearOwnerStorage(scope);
            }

            button.dataset.pwaLogoutCleanupComplete = '1';
            button.click();
        }, { capture: true });
    });
};

const refreshOnlineSession = async () => {
    const data = bodyData();

    if (
        !navigator.onLine
        || data.pwaAccountScope
        || !document.querySelector('[data-pwa-offline-shell]')
        || !data.pwaSessionUrl
    ) {
        return;
    }

    try {
        const payload = await jsonRequest(data.pwaSessionUrl);
        const session = payload.data || {};

        runtimeCsrfToken = typeof session.csrf_token === 'string' ? session.csrf_token : '';
        sessionContext = {
            pwaAccountScope: session.account_scope || '',
            pwaLibrarySnapshotUrl: session.library_snapshot_url || '',
            pwaActionUrl: session.action_url || '',
            pwaPushSubscriptionUrl: session.push_subscription_url || '',
            pwaVapidPublicKey: session.vapid_public_key || '',
        };

        if (sessionContext.pwaAccountScope) {
            await clearPreviousAccountScope(sessionContext.pwaAccountScope);
        }
    } catch (error) {
        if (error.status === 401 || error.status === 403) {
            await clearPreviousAccountScope('');
        }

        sessionContext = {};
    }
};

const synchronize = async () => {
    await refreshOnlineSession();
    const currentScope = runtimeData().pwaAccountScope;

    if (currentScope) {
        await clearPreviousAccountScope(currentScope);
    }

    await refreshSnapshots();
    await flushSafeActions();
    await renderOfflineShell();
    await refreshPushStatus();
};

export const initializePwa = () => {
    if (initialized) {
        return;
    }

    initialized = true;
    bindLogoutCleanup();

    if (bodyData().pwaEnabled !== '1') {
        return;
    }

    void setMeta('locale', locale());
    void registerWorker();
    void synchronize();
    bindControls();

    window.addEventListener('online', () => {
        void synchronize();
    });

    navigator.serviceWorker?.addEventListener('message', (event) => {
        if (event.data?.type === 'pwa-sync-requested') {
            void flushSafeActions();
        }
    });
};

export { enqueueSafeAction };
