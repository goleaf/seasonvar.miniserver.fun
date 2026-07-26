export const MAX_LIBRARY_ITEMS = 300;
export const MAX_HELP_ITEMS = 60;
export const MAX_QUEUE_ITEMS = 100;
export const MAX_QUEUE_BATCH = 50;
export const QUEUE_RETENTION_DAYS = 30;

const DATABASE_NAME = 'seasonvar-pwa';
const DATABASE_VERSION = 1;
const META_STORE = 'meta';
const SNAPSHOT_STORE = 'snapshots';
const ACTION_STORE = 'actions';

const requestResult = (request) => new Promise((resolve, reject) => {
    request.onsuccess = () => resolve(request.result);
    request.onerror = () => reject(request.error);
});

const transactionComplete = (transaction) => new Promise((resolve, reject) => {
    transaction.oncomplete = () => resolve();
    transaction.onerror = () => reject(transaction.error);
    transaction.onabort = () => reject(transaction.error);
});

const openDatabase = () => new Promise((resolve, reject) => {
    const request = indexedDB.open(DATABASE_NAME, DATABASE_VERSION);

    request.onupgradeneeded = () => {
        const database = request.result;

        if (!database.objectStoreNames.contains(META_STORE)) {
            database.createObjectStore(META_STORE, { keyPath: 'key' });
        }

        if (!database.objectStoreNames.contains(SNAPSHOT_STORE)) {
            database.createObjectStore(SNAPSHOT_STORE, { keyPath: 'key' });
        }

        if (!database.objectStoreNames.contains(ACTION_STORE)) {
            const actions = database.createObjectStore(ACTION_STORE, { keyPath: 'mutation_id' });
            actions.createIndex('queued_at', 'queued_at');
        }
    };
    request.onsuccess = () => resolve(request.result);
    request.onerror = () => reject(request.error);
});

const withStore = async (storeName, mode, callback) => {
    const database = await openDatabase();
    const transaction = database.transaction(storeName, mode);
    const store = transaction.objectStore(storeName);

    try {
        const result = await callback(store);
        await transactionComplete(transaction);

        return result;
    } finally {
        database.close();
    }
};

const boundedString = (value, maximum) => (
    typeof value === 'string' ? value.trim().slice(0, maximum) : ''
);

const normalizedLibraryItem = (item) => {
    if (!item || typeof item !== 'object') {
        return null;
    }

    const slug = boundedString(item.slug, 191);
    const title = boundedString(item.title, 255);

    if (!slug || !title) {
        return null;
    }

    return {
        slug,
        title,
        poster_url: typeof item.poster_url === 'string' && item.poster_url.startsWith('/pwa/posters/')
            ? item.poster_url.slice(0, 255)
            : null,
        in_watchlist: item.in_watchlist === true,
        rating: Number.isInteger(item.rating) && item.rating >= 1 && item.rating <= 10
            ? item.rating
            : null,
        watch_status: ['planned', 'watching', 'paused', 'completed', 'dropped'].includes(item.watch_status)
            ? item.watch_status
            : null,
        versions: {
            watchlist: Number.isInteger(item.versions?.watchlist) && item.versions.watchlist >= 0
                ? item.versions.watchlist
                : 0,
            rating: Number.isInteger(item.versions?.rating) && item.versions.rating >= 0
                ? item.versions.rating
                : 0,
            watch_status: Number.isInteger(item.versions?.watch_status) && item.versions.watch_status >= 0
                ? item.versions.watch_status
                : 0,
        },
        updated_at: boundedString(item.updated_at, 64) || null,
    };
};

const normalizedHelpItem = (item) => {
    if (!item || typeof item !== 'object') {
        return null;
    }

    const slug = boundedString(item.slug, 191);
    const title = boundedString(item.title, 220);

    if (!slug || !title) {
        return null;
    }

    return {
        slug,
        title,
        summary: boundedString(item.summary, 500),
        body: boundedString(item.body, 12000),
        updated_at: boundedString(item.updated_at, 64) || null,
    };
};

export const setMeta = (key, value) => withStore(
    META_STORE,
    'readwrite',
    (store) => requestResult(store.put({ key, value })),
);

export const getMeta = (key) => withStore(
    META_STORE,
    'readonly',
    async (store) => (await requestResult(store.get(key)))?.value ?? null,
);

export const saveLibrarySnapshot = async (accountScope, payload) => {
    const scope = boundedString(accountScope, 128);

    if (!scope) {
        return;
    }

    const items = Array.isArray(payload?.items)
        ? payload.items.map(normalizedLibraryItem).filter(Boolean).slice(0, MAX_LIBRARY_ITEMS)
        : [];

    await withStore(SNAPSHOT_STORE, 'readwrite', (store) => requestResult(store.put({
        key: `library:${scope}`,
        accountScope: scope,
        saved_at: Date.now(),
        items,
    })));
    await setMeta('lastAccountScope', scope);
};

export const readLibrarySnapshot = async (accountScope) => {
    const scope = boundedString(accountScope, 128);

    if (!scope) {
        return null;
    }

    return withStore(
        SNAPSHOT_STORE,
        'readonly',
        (store) => requestResult(store.get(`library:${scope}`)),
    );
};

export const saveHelpSnapshot = async (locale, payload) => {
    const normalizedLocale = boundedString(locale, 12) || 'ru';
    const items = Array.isArray(payload?.items)
        ? payload.items.map(normalizedHelpItem).filter(Boolean).slice(0, MAX_HELP_ITEMS)
        : [];

    await withStore(SNAPSHOT_STORE, 'readwrite', (store) => requestResult(store.put({
        key: `help:${normalizedLocale}`,
        locale: normalizedLocale,
        saved_at: Date.now(),
        items,
    })));
};

export const readHelpSnapshot = (locale) => withStore(
    SNAPSHOT_STORE,
    'readonly',
    (store) => requestResult(store.get(`help:${boundedString(locale, 12) || 'ru'}`)),
);

const operationIsSafe = (operation) => {
    if (!operation || typeof operation !== 'object') {
        return false;
    }

    const keys = Object.keys(operation).sort();
    const expected = ['expected_version', 'mutation_id', 'title_slug', 'type', 'value'];

    if (keys.length !== expected.length || keys.some((key, index) => key !== expected[index])) {
        return false;
    }

    const baseValid = typeof operation.mutation_id === 'string'
        && operation.mutation_id.length <= 64
        && typeof operation.title_slug === 'string'
        && /^[a-z0-9]+(?:-[a-z0-9]+)*$/.test(operation.title_slug)
        && Number.isInteger(operation.expected_version)
        && operation.expected_version >= 0;

    return baseValid && (
        (operation.type === 'watchlist.set' && typeof operation.value === 'boolean')
        || (operation.type === 'rating.set' && (
            operation.value === null
            || (Number.isInteger(operation.value) && operation.value >= 1 && operation.value <= 10)
        ))
    );
};

export const enqueueSafeAction = async (accountScope, operation) => {
    const scope = boundedString(accountScope, 128);

    if (!scope || !operationIsSafe(operation)) {
        throw new TypeError('Unsupported offline action.');
    }

    const now = Date.now();

    await withStore(ACTION_STORE, 'readwrite', async (store) => {
        const records = await requestResult(store.getAll());
        const retained = records
            .filter((record) => record.accountScope === scope)
            .sort((left, right) => left.queued_at - right.queued_at);

        for (const record of retained.slice(0, Math.max(0, retained.length - MAX_QUEUE_ITEMS + 1))) {
            store.delete(record.mutation_id);
        }

        await requestResult(store.put({
            ...operation,
            accountScope: scope,
            queued_at: now,
            sync_status: 'pending',
        }));
    });
};

export const queuedActions = async (accountScope) => {
    const scope = boundedString(accountScope, 128);
    const cutoff = Date.now() - QUEUE_RETENTION_DAYS * 24 * 60 * 60 * 1000;

    return withStore(ACTION_STORE, 'readwrite', async (store) => {
        const records = await requestResult(store.getAll());
        const active = [];

        for (const record of records) {
            if (record.queued_at < cutoff) {
                store.delete(record.mutation_id);
                continue;
            }

            if (record.accountScope === scope && (record.sync_status || 'pending') === 'pending') {
                const {
                    accountScope: ignoredScope,
                    queued_at: ignoredQueuedAt,
                    sync_status: ignoredStatus,
                    ...operation
                } = record;
                active.push({ operation, queued_at: record.queued_at });
            }
        }

        return active
            .sort((left, right) => left.queued_at - right.queued_at)
            .slice(0, MAX_QUEUE_BATCH)
            .map((entry) => entry.operation);
    });
};

export const reconcileSafeActionResults = async (accountScope, results) => {
    const scope = boundedString(accountScope, 128);

    if (!scope || !Array.isArray(results)) {
        return;
    }

    const normalizedResults = results.filter((result) => (
        result
        && typeof result.mutation_id === 'string'
        && ['applied', 'duplicate', 'conflict', 'rejected', 'not_found'].includes(result.status)
    ));

    await withStore(ACTION_STORE, 'readwrite', async (store) => {
        for (const result of normalizedResults) {
            const record = await requestResult(store.get(result.mutation_id));

            if (!record || record.accountScope !== scope) {
                continue;
            }

            if (['applied', 'duplicate'].includes(result.status)) {
                await requestResult(store.delete(result.mutation_id));
                continue;
            }

            await requestResult(store.put({
                ...record,
                sync_status: result.status,
            }));
        }
    });
};

export const readSafeActionIssues = async (accountScope) => {
    const scope = boundedString(accountScope, 128);
    const cutoff = Date.now() - QUEUE_RETENTION_DAYS * 24 * 60 * 60 * 1000;

    if (!scope) {
        return [];
    }

    return withStore(ACTION_STORE, 'readwrite', async (store) => {
        const records = await requestResult(store.getAll());
        const issues = [];

        for (const record of records) {
            if (record.queued_at < cutoff) {
                store.delete(record.mutation_id);
                continue;
            }

            if (
                record.accountScope === scope
                && ['conflict', 'rejected', 'not_found'].includes(record.sync_status)
            ) {
                issues.push({
                mutation_id: record.mutation_id,
                status: record.sync_status,
                });
            }
        }

        return issues;
    });
};

export const updateLocalWatchlist = async (accountScope, slug, value, version) => {
    const snapshot = await readLibrarySnapshot(accountScope);

    if (!snapshot || !Array.isArray(snapshot.items)) {
        return;
    }

    const item = snapshot.items.find((candidate) => candidate.slug === slug);

    if (!item) {
        return;
    }

    item.in_watchlist = value;
    item.versions.watchlist = version;
    snapshot.saved_at = Date.now();

    await withStore(
        SNAPSHOT_STORE,
        'readwrite',
        (store) => requestResult(store.put(snapshot)),
    );
};

export const updateLocalRating = async (accountScope, slug, value, version) => {
    const snapshot = await readLibrarySnapshot(accountScope);

    if (!snapshot || !Array.isArray(snapshot.items)) {
        return;
    }

    const item = snapshot.items.find((candidate) => candidate.slug === slug);

    if (!item) {
        return;
    }

    item.rating = value;
    item.versions.rating = version;
    snapshot.saved_at = Date.now();

    await withStore(
        SNAPSHOT_STORE,
        'readwrite',
        (store) => requestResult(store.put(snapshot)),
    );
};

export const clearAccountData = async (accountScope) => {
    const scope = boundedString(accountScope, 128);

    if (!scope) {
        return;
    }

    await withStore(SNAPSHOT_STORE, 'readwrite', async (store) => {
        const records = await requestResult(store.getAll());
        records
            .filter((record) => record.accountScope === scope || record.key === `library:${scope}`)
            .forEach((record) => store.delete(record.key));
    });
    await withStore(ACTION_STORE, 'readwrite', async (store) => {
        const records = await requestResult(store.getAll());
        records
            .filter((record) => record.accountScope === scope)
            .forEach((record) => store.delete(record.mutation_id));
    });

    if (await getMeta('lastAccountScope') === scope) {
        await setMeta('lastAccountScope', null);
    }
};
