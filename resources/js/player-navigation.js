const boundRoots = new Map();
const playerSelectionKeys = [
    'season',
    'episode',
    'media',
    'variant',
    'quality',
    'format',
    'marker',
];

const playerRootsWithin = (root) => {
    if (root instanceof Element && root.matches('[data-active-player-session]')) {
        return [root];
    }

    if (!(root instanceof Document || root instanceof Element)) {
        return [];
    }

    return [...root.querySelectorAll('[data-active-player-session]')];
};

const wireFor = (root) => {
    const component = root.closest('[wire\\:id]');
    const componentId = component?.getAttribute('wire:id');

    return componentId ? window.Livewire?.find(componentId) : null;
};

const callWire = async (root, method, args, island = null) => {
    const wire = wireFor(root);
    const target = island === null ? wire : wire?.$island?.(island);

    if (!target || typeof target[method] !== 'function') {
        throw new Error('Player component is unavailable.');
    }

    return target[method](...args);
};

const playerUrlForQuery = (query) => {
    const url = new URL(window.location.href);

    playerSelectionKeys.forEach((key) => url.searchParams.delete(key));
    Object.entries(query || {}).forEach(([key, value]) => {
        if (
            playerSelectionKeys.includes(key)
            && typeof value === 'string'
            && value.trim() !== ''
        ) {
            url.searchParams.set(key, value);
        }
    });
    url.hash = url.hash || '#player';

    return url;
};

const pushPlayerHistory = (query) => {
    const url = playerUrlForQuery(query);

    if (url.href !== window.location.href) {
        window.history.pushState({ catalogPlayer: true }, '', url);
    }
};

const runPlayerRequest = async (root, detail, method, args, onReady = null, island = null) => {
    if (
        !detail
        || detail.sessionKey !== root.dataset.activePlayerSession
        || typeof detail.resolve !== 'function'
        || typeof detail.reject !== 'function'
        || typeof detail.accepted !== 'function'
        || !detail.accepted()
    ) {
        return;
    }

    try {
        const payload = await callWire(root, method, args, island);

        if (
            !root.isConnected
            || detail.sessionKey !== root.dataset.activePlayerSession
            || !detail.accepted()
            || !['ready', 'unavailable'].includes(payload?.status)
        ) {
            return;
        }

        if (payload.status === 'ready' && typeof onReady === 'function') {
            onReady(payload);
        }

        detail.resolve(payload);
    } catch {
        if (root.isConnected && detail.accepted()) {
            detail.reject(new Error('Player request failed.'));
        }
    }
};

const restoreSelectionFromLocation = async (root) => {
    const wire = wireFor(root);

    if (!wire) {
        return;
    }

    const targetUrl = window.location.href;
    const query = new URLSearchParams(window.location.search);

    await Promise.all([
        wire.$set('season', query.get('season') ?? '', false),
        wire.$set('episode', query.get('episode') ?? '', false),
        wire.$set('media', query.get('media') ?? '', false),
        wire.$set('variant', query.get('variant') ?? '', false),
        wire.$set('quality', query.get('quality') ?? '', false),
        wire.$set('format', query.get('format') ?? '', false),
        wire.$set('marker', query.get('marker') ?? '', false),
    ]);
    await wire.$refresh();
    window.history.replaceState({}, '', targetUrl);
};

const theatreTriggerFor = (root) => root.querySelector('[data-player-theatre-toggle]');

const syncTheatreUi = (root, active) => {
    const theatreTrigger = theatreTriggerFor(root);
    const theatreLabel = theatreTrigger?.querySelector('[data-player-theatre-label]');
    const titleWorkspace = root.closest('[data-title-detail-workspace]');

    document.body.classList.toggle('player-theatre-active', active);
    root.toggleAttribute('data-player-theatre-active', active);
    titleWorkspace?.toggleAttribute('data-player-theatre-active', active);
    theatreTrigger?.setAttribute('aria-pressed', active ? 'true' : 'false');

    if (theatreLabel instanceof HTMLElement && theatreTrigger instanceof HTMLElement) {
        theatreLabel.textContent = active
            ? theatreTrigger.dataset.labelCollapse || ''
            : theatreTrigger.dataset.labelExpand || '';
    }
};

const bindRoot = (root) => {
    if (!(root instanceof HTMLElement)) {
        return;
    }

    if (boundRoots.has(root)) {
        syncTheatreUi(
            root,
            document.body.classList.contains('player-theatre-active')
                || root.hasAttribute('data-player-theatre-active'),
        );

        return;
    }

    const controller = new AbortController();
    const { signal } = controller;
    let theatreActive = document.body.classList.contains('player-theatre-active')
        || root.hasAttribute('data-player-theatre-active');

    const setTheatre = (active) => {
        theatreActive = active === true;
        syncTheatreUi(root, theatreActive);
    };
    const cleanupTheatre = () => setTheatre(false);

    boundRoots.set(root, controller);
    setTheatre(theatreActive);
    root.addEventListener('click', (event) => {
        const target = event.target instanceof Element ? event.target : null;

        if (target?.closest('[data-player-theatre-toggle]')) {
            setTheatre(!theatreActive);
        }
    }, { signal });
    document.addEventListener('keydown', (event) => {
        if (event.key !== 'Escape' || document.fullscreenElement || root.querySelector('dialog[open]')) {
            return;
        }

        const openContextControl = root.querySelector('[data-player-context-control][open]');

        if (openContextControl instanceof HTMLDetailsElement) {
            event.preventDefault();
            openContextControl.open = false;
            openContextControl.querySelector('summary')?.focus();

            return;
        }

        if (!theatreActive) {
            return;
        }

        event.preventDefault();
        cleanupTheatre();
        theatreTriggerFor(root)?.focus();
    }, { signal });
    signal.addEventListener('abort', cleanupTheatre, { once: true });
    root.addEventListener('catalog-player-menu-page-request', (event) => {
        const detail = event.detail;

        void runPlayerRequest(
            root,
            detail,
            'playerEpisodePage',
            [detail?.seasonId, detail?.page],
        );
    }, { signal });
    root.addEventListener('catalog-player-transition-request', (event) => {
        const detail = event.detail;

        void runPlayerRequest(
            root,
            detail,
            'preparePlayerTransition',
            [detail?.episodeId, detail?.mediaId],
        );
    }, { signal });
    root.addEventListener('catalog-player-transition-commit', (event) => {
        const detail = event.detail;

        void runPlayerRequest(
            root,
            detail,
            'commitPlayerTransition',
            [detail?.episodeId, detail?.mediaId],
            (payload) => pushPlayerHistory(payload.query),
            'catalog-player-navigation',
        );
    }, { signal });
    root.addEventListener('catalog-progress', (event) => {
        const detail = event.detail;

        if (!detail || detail.sessionKey !== root.dataset.activePlayerSession) {
            return;
        }

        const wire = wireFor(root);

        void wire?.recordProgress(
            detail.episodeId,
            detail.playbackSessionToken,
            detail.eventSequence,
            detail.positionSeconds,
            detail.durationSeconds,
            detail.completed,
        );
    }, { signal });
    root.addEventListener('catalog-source-fallback', (event) => {
        const detail = event.detail;

        if (!detail || detail.sessionKey !== root.dataset.activePlayerSession) {
            return;
        }

        const fallback = async () => {
            try {
                const wire = wireFor(root);

                if (!wire) {
                    throw new Error('Player component is unavailable.');
                }

                await wire.selectFallbackMedia(detail.failedMediaId);
            } catch {
                detail.fail?.();
            }
        };

        void fallback();
    }, { signal });
    root.addEventListener('catalog-source-refresh', (event) => {
        const detail = event.detail;

        if (!detail || detail.sessionKey !== root.dataset.activePlayerSession) {
            return;
        }

        const refresh = async () => {
            try {
                const wire = wireFor(root);

                if (!wire) {
                    throw new Error('Player component is unavailable.');
                }

                await wire.refreshPlaybackAuthorization();
            } catch {
                detail.fail?.();
            }
        };

        void refresh();
    }, { signal });
    root.addEventListener('catalog-autoplay-preference', (event) => {
        const detail = event.detail;

        if (!detail || detail.sessionKey !== root.dataset.activePlayerSession) {
            return;
        }

        const save = async () => {
            try {
                const wire = wireFor(root);

                if (!wire) {
                    throw new Error('Player component is unavailable.');
                }

                await wire.setAutoplay(detail.enabled);
            } catch {
                detail.reject?.();
            }
        };

        void save();
    }, { signal });
    root.addEventListener('catalog-player-preferences', (event) => {
        const detail = event.detail;

        if (!detail || detail.sessionKey !== root.dataset.activePlayerSession) {
            return;
        }

        void wireFor(root)?.persistPlayerPreferences(detail.volume, detail.muted, detail.speed);
    }, { signal });
    root.addEventListener('catalog-restart-progress', (event) => {
        const detail = event.detail;

        if (!detail || detail.sessionKey !== root.dataset.activePlayerSession) {
            return;
        }

        const restart = async () => {
            try {
                const wire = wireFor(root);

                if (!wire) {
                    throw new Error('Player component is unavailable.');
                }

                await wire.restartEpisodeProgress(
                    detail.episodeId,
                    detail.playbackSessionToken,
                );
                detail.complete?.();
            } catch {
                detail.fail?.();
            }
        };

        void restart();
    }, { signal });
    root.addEventListener('catalog-save-playback-marker', (event) => {
        const detail = event.detail;

        if (!detail || detail.sessionKey !== root.dataset.activePlayerSession) {
            return;
        }

        void wireFor(root)?.savePlaybackMarker(detail.episodeId, detail.positionSeconds);
    }, { signal });
    root.addEventListener('click', (event) => {
        const target = event.target instanceof Element ? event.target : null;

        if (target?.closest('[data-catalog-history]')) {
            window.history.pushState({}, '', window.location.href);
        }
    }, { capture: true, signal });
    window.addEventListener('popstate', () => {
        if (root.isConnected) {
            root.dispatchEvent(new CustomEvent('catalog-player-popstate'));
            void restoreSelectionFromLocation(root);
        }
    }, { signal });
};

export const initializePlayerNavigation = (root = document) => {
    playerRootsWithin(root).forEach(bindRoot);
};

export const destroyPlayerNavigationWithin = (root = document) => {
    playerRootsWithin(root).forEach((playerRoot) => {
        boundRoots.get(playerRoot)?.abort();
        boundRoots.delete(playerRoot);
    });
};
