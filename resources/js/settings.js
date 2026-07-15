const SETTINGS_VERSION = 1;
const DEFAULT_STORAGE_KEY = 'seasonvar.account-preferences.v1';
let migrationStarted = false;

const safeStorage = (storage, operation) => {
    try {
        return operation(storage);
    } catch {
        return null;
    }
};

const parsedObject = (value) => {
    if (typeof value !== 'string' || value === '') {
        return null;
    }

    try {
        const parsed = JSON.parse(value);

        return parsed && typeof parsed === 'object' && !Array.isArray(parsed) ? parsed : null;
    } catch {
        return null;
    }
};

const booleanOrUndefined = (value) => typeof value === 'boolean' ? value : undefined;

const volumeOrUndefined = (value) => {
    const volume = Number(value);

    if (!Number.isFinite(volume)) {
        return undefined;
    }

    const percentage = volume <= 1 ? Math.round(volume * 100) : Math.round(volume);

    return percentage >= 0 && percentage <= 100 ? percentage : undefined;
};

const speedOrUndefined = (value) => {
    const speed = Number(value);
    const allowed = [0.5, 0.75, 1, 1.25, 1.5, 1.75, 2];

    return allowed.includes(speed) ? speed.toFixed(2) : undefined;
};

const normalizedPreferences = (value) => {
    if (!value || typeof value !== 'object' || Array.isArray(value)) {
        return null;
    }

    const preferences = {
        version: SETTINGS_VERSION,
        owner_scope: typeof value.owner_scope === 'string' && /^[a-f0-9]{64}$/.test(value.owner_scope)
            ? value.owner_scope
            : undefined,
        account_version: Number.isInteger(Number(value.account_version ?? value.settings_version))
            ? Math.max(1, Number(value.account_version ?? value.settings_version))
            : undefined,
        locale: typeof value.locale === 'string' ? value.locale : undefined,
        timezone: typeof value.timezone === 'string' ? value.timezone : undefined,
        autoplay: booleanOrUndefined(value.autoplay),
        remember_volume: booleanOrUndefined(value.remember_volume),
        volume: volumeOrUndefined(value.volume),
        muted: booleanOrUndefined(value.muted),
        playback_speed: speedOrUndefined(value.playback_speed),
        preferred_quality: typeof value.preferred_quality === 'string' ? value.preferred_quality : undefined,
        preferred_variant: typeof value.preferred_variant === 'string' ? value.preferred_variant : undefined,
        subtitles_enabled: booleanOrUndefined(value.subtitles_enabled),
        keyboard_shortcuts_enabled: booleanOrUndefined(value.keyboard_shortcuts_enabled),
        reduced_motion: booleanOrUndefined(value.reduced_motion),
    };

    return Object.fromEntries(Object.entries(preferences).filter(([, item]) => item !== undefined));
};

const legacyPlyrPreferences = () => {
    const legacy = safeStorage(window.localStorage, (storage) => parsedObject(storage.getItem('plyr')));

    if (!legacy) {
        return null;
    }

    return normalizedPreferences({
        volume: legacy.volume,
        muted: legacy.muted,
        playback_speed: legacy.speed,
        subtitles_enabled: legacy.captions,
    });
};

export const accountDevicePreferences = (
    storageKey = DEFAULT_STORAGE_KEY,
    ownerScope = document.body?.dataset.accountMigrationScope || '',
) => {
    const stored = safeStorage(window.localStorage, (storage) => parsedObject(storage.getItem(storageKey)));
    const normalized = normalizedPreferences(stored);

    if (normalized?.owner_scope && ownerScope && normalized.owner_scope !== ownerScope) {
        return normalizedPreferences({
            version: SETTINGS_VERSION,
            remember_volume: normalized.remember_volume,
            volume: normalized.volume,
            muted: normalized.muted,
        });
    }

    return normalized || legacyPlyrPreferences() || { version: SETTINGS_VERSION };
};

const storePreferences = (storageKey, preferences) => {
    const normalized = normalizedPreferences(preferences);

    if (!normalized) {
        return;
    }

    safeStorage(window.localStorage, (storage) => storage.setItem(storageKey, JSON.stringify(normalized)));
};

export const persistAccountDevicePreferences = (preferences, storageKey = DEFAULT_STORAGE_KEY) => {
    const current = accountDevicePreferences(storageKey);

    storePreferences(storageKey, {
        ...current,
        ...preferences,
        version: SETTINGS_VERSION,
        account_version: Number.parseInt(document.body.dataset.accountSettingsVersion || '1', 10) || 1,
        owner_scope: document.body.dataset.accountMigrationScope || undefined,
    });
};

const initializeVolumePreview = (root) => {
    const input = root.querySelector('[data-settings-volume-input]');
    const output = root.querySelector('[data-settings-volume-output]');

    if (!(input instanceof HTMLInputElement) || !(output instanceof HTMLOutputElement)) {
        return;
    }

    if (input.dataset.settingsVolumeInitialized === '1') {
        return;
    }

    input.dataset.settingsVolumeInitialized = '1';

    const update = () => {
        output.value = `${input.value}%`;
        output.textContent = output.value;
    };

    input.addEventListener('input', update);
    update();
};

const initializeBrowserTimezoneSuggestion = (root) => {
    const button = root.querySelector('[data-settings-use-browser-timezone]');
    const input = root.querySelector('#settings-timezone');
    const status = root.querySelector('[data-settings-browser-timezone-status]');

    if (!(button instanceof HTMLButtonElement) || !(input instanceof HTMLInputElement)) {
        return;
    }

    if (button.dataset.settingsTimezoneInitialized === '1') {
        return;
    }

    button.dataset.settingsTimezoneInitialized = '1';

    button.addEventListener('click', () => {
        let timezone = '';

        try {
            timezone = Intl.DateTimeFormat().resolvedOptions().timeZone || '';
        } catch {
            timezone = '';
        }

        if (timezone === '') {
            if (status) {
                status.textContent = button.dataset.settingsTimezoneUnavailable || '';
            }

            return;
        }

        input.value = timezone;
        input.dispatchEvent(new Event('input', { bubbles: true }));

        if (status) {
            status.textContent = (button.dataset.settingsTimezoneDetected || '').replace(':timezone', timezone);
        }
    });
};

const initializeDirtyState = (root) => {
    if (root.dataset.settingsDirtyInitialized === '1') {
        return;
    }

    root.dataset.settingsDirtyInitialized = '1';

    const markDirty = (event) => {
        const target = event.target instanceof Element ? event.target : null;

        if (target?.closest('form')) {
            root.dataset.settingsDirty = '1';
        }
    };

    root.addEventListener('input', markDirty);
    root.addEventListener('change', markDirty);

    root.addEventListener('click', (event) => {
        const target = event.target instanceof Element ? event.target : null;
        const link = target?.closest('a[href]');

        if (!link || root.dataset.settingsDirty !== '1') {
            return;
        }

        if (!window.confirm(root.dataset.settingsUnsavedConfirm || '')) {
            event.preventDefault();
            event.stopImmediatePropagation();
            return;
        }

        root.dataset.settingsDirty = '0';
    }, true);
};

export const initializeAccountPreferenceMigration = () => {
    if (migrationStarted) {
        return;
    }

    const endpoint = document.body.dataset.accountMigrationUrl || '';
    const storageKey = document.body.dataset.accountStorageKey || DEFAULT_STORAGE_KEY;
    const migrationScope = document.body.dataset.accountMigrationScope || 'guest';
    const migrationMarker = `${storageKey}.merged.${migrationScope}`;
    const alreadyMerged = safeStorage(window.sessionStorage, (storage) => storage.getItem(migrationMarker)) === '1';

    if (endpoint === '' || alreadyMerged) {
        return;
    }

    const preferences = accountDevicePreferences(storageKey);
    const meaningfulPreferences = Object.keys(preferences).filter((key) => !['version', 'account_version'].includes(key));

    if (meaningfulPreferences.length === 0) {
        safeStorage(window.sessionStorage, (storage) => storage.setItem(migrationMarker, '1'));
        return;
    }

    migrationStarted = true;
    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';

    fetch(endpoint, {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': csrfToken,
        },
        body: JSON.stringify(preferences),
    }).then((response) => {
        if (response.ok) {
            storePreferences(storageKey, {
                version: SETTINGS_VERSION,
                owner_scope: migrationScope,
                remember_volume: preferences.remember_volume,
                volume: preferences.volume,
                muted: preferences.muted,
            });
            safeStorage(window.sessionStorage, (storage) => storage.setItem(migrationMarker, '1'));
        }
    }).catch(() => {}).finally(() => {
        migrationStarted = false;
    });
};

export const initializeAccountSettings = (root = document) => {
    const settingsRoots = root instanceof Element && root.matches('[data-account-settings]')
        ? [root]
        : [...root.querySelectorAll?.('[data-account-settings]') || []];

    settingsRoots.forEach((settingsRoot) => {
        initializeVolumePreview(settingsRoot);
        initializeBrowserTimezoneSuggestion(settingsRoot);
        initializeDirtyState(settingsRoot);
    });
};

document.addEventListener('account-settings-saved', (event) => {
    const preferences = event.detail?.preferences;
    const storageKey = document.querySelector('[data-account-settings]')?.dataset.accountStorageKey || DEFAULT_STORAGE_KEY;

    if (preferences) {
        storePreferences(storageKey, {
            ...preferences,
            owner_scope: document.body.dataset.accountMigrationScope || undefined,
        });
        document.body.classList.toggle('account-reduced-motion', preferences.reduced_motion === true);
    }
});

document.addEventListener('account-settings-persisted', () => {
    document.querySelectorAll('[data-account-settings]').forEach((root) => {
        root.dataset.settingsDirty = '0';
    });
});

document.addEventListener('account-settings-save-failed', () => {
    document.querySelectorAll('[data-account-settings]').forEach((root) => {
        root.dataset.settingsDirty = '1';
    });
});

window.addEventListener('beforeunload', (event) => {
    if (!document.querySelector('[data-account-settings][data-settings-dirty="1"]')) {
        return;
    }

    event.preventDefault();
    event.returnValue = '';
});
