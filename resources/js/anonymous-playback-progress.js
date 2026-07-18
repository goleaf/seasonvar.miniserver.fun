const STORAGE_KEY = 'seasonvar.playback-progress.v1';
const RETENTION_MS = 30 * 24 * 60 * 60 * 1_000;
const ENTRY_LIMIT = 50;
const MAX_DURATION_SECONDS = 86_400;

const storage = () => {
    try {
        const candidate = window.localStorage;
        const probe = '__seasonvar_anonymous_progress_probe__';

        candidate.setItem(probe, '1');
        candidate.removeItem(probe);

        return candidate;
    } catch {
        return null;
    }
};

const boundedInteger = (value, minimum = 0, maximum = MAX_DURATION_SECONDS) => {
    const number = Number.parseInt(String(value), 10);

    return Number.isInteger(number) && number >= minimum && number <= maximum ? number : null;
};

const storedEntries = () => {
    const target = storage();

    if (!target) {
        return { storage: null, entries: {} };
    }

    try {
        const parsed = JSON.parse(target.getItem(STORAGE_KEY) || '{}');
        const entries = parsed?.version === 1 && parsed.entries && typeof parsed.entries === 'object'
            ? parsed.entries
            : {};

        return { storage: target, entries };
    } catch {
        return { storage: target, entries: {} };
    }
};

const normalizedEntries = () => {
    const now = Date.now();
    const { entries } = storedEntries();

    return Object.entries(entries)
        .map(([episodeId, entry]) => {
            const normalizedEpisodeId = boundedInteger(episodeId, 1, Number.MAX_SAFE_INTEGER);
            const updatedAt = boundedInteger(entry?.updated_at, 1, Number.MAX_SAFE_INTEGER);
            const position = boundedInteger(entry?.position);
            const duration = boundedInteger(entry?.duration);

            if (
                normalizedEpisodeId === null
                || updatedAt === null
                || updatedAt > now + 5 * 60 * 1_000
                || now - updatedAt > RETENTION_MS
                || position === null
                || duration === null
                || (duration > 0 && position > duration + 5)
            ) {
                return null;
            }

            return {
                episode_id: normalizedEpisodeId,
                position,
                duration,
                completed: entry?.completed === true,
                updated_at: updatedAt,
            };
        })
        .filter((entry) => entry !== null)
        .sort((left, right) => right.updated_at - left.updated_at)
        .slice(0, ENTRY_LIMIT);
};

export const anonymousResumePosition = (episodeId) => {
    if (!Number.isInteger(episodeId) || episodeId < 1) {
        return 0;
    }

    const entry = normalizedEntries().find((candidate) => candidate.episode_id === episodeId);

    if (!entry || entry.completed || (entry.duration > 0 && entry.position >= entry.duration - 5)) {
        return 0;
    }

    return entry.position;
};

export const persistAnonymousProgress = (episodeId, position, duration, completed) => {
    const target = storage();

    if (!target || !Number.isInteger(episodeId) || episodeId < 1) {
        return;
    }

    const nextEntries = Object.fromEntries(normalizedEntries()
        .filter((entry) => entry.episode_id !== episodeId)
        .slice(0, ENTRY_LIMIT - 1)
        .map((entry) => [String(entry.episode_id), {
            position: entry.position,
            duration: entry.duration,
            completed: entry.completed,
            updated_at: entry.updated_at,
        }]));

    nextEntries[String(episodeId)] = {
        position,
        duration,
        completed,
        updated_at: Date.now(),
    };

    try {
        target.setItem(STORAGE_KEY, JSON.stringify({ version: 1, entries: nextEntries }));
    } catch {
        // Playback never depends on optional anonymous device storage.
    }
};

export const removeAnonymousProgress = (episodeId) => {
    const { storage: target, entries } = storedEntries();

    if (!target || !Object.hasOwn(entries, String(episodeId))) {
        return;
    }

    delete entries[String(episodeId)];

    try {
        target.setItem(STORAGE_KEY, JSON.stringify({ version: 1, entries }));
    } catch {
        // Playback never depends on optional anonymous device storage.
    }
};

export const anonymousProgressMigrationPayload = () => normalizedEntries();

export const clearMigratedAnonymousProgress = (migratedEntries) => {
    const { storage: target, entries } = storedEntries();

    if (!target || !Array.isArray(migratedEntries) || migratedEntries.length === 0) {
        return;
    }

    migratedEntries.forEach((migrated) => {
        const current = entries[String(migrated.episode_id)];

        if (Number(current?.updated_at) === migrated.updated_at) {
            delete entries[String(migrated.episode_id)];
        }
    });

    try {
        target.setItem(STORAGE_KEY, JSON.stringify({ version: 1, entries }));
    } catch {
        // A successful server migration remains authoritative if optional cleanup fails.
    }
};
