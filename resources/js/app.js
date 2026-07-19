import '@fortawesome/fontawesome-free/css/fontawesome.min.css';
import '@fortawesome/fontawesome-free/css/solid.min.css';
import '@fortawesome/fontawesome-free/css/regular.min.css';
import '../css/app.css';
import { initializeHeaderSearchInterfaces } from './header-search.js';
import { initializeMobileRuntime } from './mobile-runtime.js';

let catalogPlayerModule = null;
let catalogPlayerModulePromise = null;
let playerNavigationModule = null;
let playerNavigationModulePromise = null;

const optionalModulePromises = new Map();

const loadOptionalModule = (key, selector, loader, initialize) => {
    if (!document.querySelector(selector)) {
        return;
    }

    const promise = optionalModulePromises.get(key) || loader().catch(() => {
        optionalModulePromises.delete(key);

        return null;
    });

    optionalModulePromises.set(key, promise);
    void promise.then((module) => {
        if (module) {
            initialize(module);
        }
    });
};

const loadCatalogPlayerModule = async () => {
    catalogPlayerModulePromise ??= import('./player.js').then((module) => {
        catalogPlayerModule = module;

        return module;
    });

    return catalogPlayerModulePromise;
};

const loadCatalogPlayers = async (root = document) => {
    if (!root.querySelector?.('video.js-catalog-player:not([data-player-ready]):not([data-player-failed])')) {
        return;
    }

    const playerModule = await loadCatalogPlayerModule();

    await playerModule.initializeCatalogPlayers(root);
};

const flushCatalogPlayersWithin = (root, reason) => {
    catalogPlayerModule?.flushCatalogPlayersWithin(root, reason);
};

const destroyCatalogPlayersWithin = (root, options = {}) => {
    catalogPlayerModule?.destroyCatalogPlayersWithin(root, options);
};

const loadPlayerNavigation = async (root = document) => {
    if (!root.querySelector?.('[data-active-player-session]')) {
        return;
    }

    playerNavigationModulePromise ??= import('./player-navigation.js').then((module) => {
        playerNavigationModule = module;

        return module;
    });

    const module = await playerNavigationModulePromise;
    module.initializePlayerNavigation(root);
};

const destroyPlayerNavigationWithin = (root) => {
    playerNavigationModule?.destroyPlayerNavigationWithin(root);
};

const reducedMotionQuery = window.matchMedia('(prefers-reduced-motion: reduce)');
const smoothScrollDuration = {
    min: 260,
    max: 420,
    base: 220,
    perPixel: 0.05,
};

const paginationScrollDuration = {
    min: 520,
    max: 820,
    base: 460,
    perPixel: 0.1,
};

let activeScrollAnimation = null;

const cancelActiveScroll = () => {
    if (activeScrollAnimation === null) {
        return;
    }

    window.cancelAnimationFrame(activeScrollAnimation);
    activeScrollAnimation = null;
};

const bounded = (min, value, max) => Math.min(max, Math.max(min, value));

const decodeHashTarget = (hash) => {
    if (!hash || hash === '#') {
        return null;
    }

    try {
        return decodeURIComponent(hash.slice(1));
    } catch {
        return hash.slice(1);
    }
};

const targetTop = (target) => {
    const scrollMarginTop = Number.parseFloat(window.getComputedStyle(target).scrollMarginTop) || 0;
    const documentHeight = Math.max(
        document.documentElement.scrollHeight,
        document.body.scrollHeight,
    );
    const maxScrollTop = Math.max(0, documentHeight - window.innerHeight);

    return bounded(0, window.scrollY + target.getBoundingClientRect().top - scrollMarginTop, maxScrollTop);
};

const anchorScrollDuration = (distance) => bounded(
    smoothScrollDuration.min,
    smoothScrollDuration.base + distance * smoothScrollDuration.perPixel,
    smoothScrollDuration.max,
);

const paginationScrollDurationFor = (distance) => bounded(
    paginationScrollDuration.min,
    paginationScrollDuration.base + distance * paginationScrollDuration.perPixel,
    paginationScrollDuration.max,
);

const easeOutCubic = (progress) => 1 - ((1 - progress) ** 3);
const easeInOutCubic = (progress) => (
    progress < 0.5
        ? 4 * progress * progress * progress
        : 1 - ((-2 * progress + 2) ** 3) / 2
);

const smoothWindowScroll = (endY, { animate = true, duration, easing = easeOutCubic } = {}) => {
    const startY = window.scrollY;
    const distance = Math.abs(endY - startY);

    cancelActiveScroll();

    if (!animate || reducedMotionQuery.matches || distance < 2) {
        window.scrollTo(0, endY);
        return;
    }

    const resolvedDuration = duration ?? anchorScrollDuration(distance);
    const startedAt = window.performance.now();

    const step = (timestamp) => {
        const progress = bounded(0, (timestamp - startedAt) / resolvedDuration, 1);
        const easedProgress = easing(progress);

        window.scrollTo(0, startY + (endY - startY) * easedProgress);

        if (progress < 1) {
            activeScrollAnimation = window.requestAnimationFrame(step);
            return;
        }

        activeScrollAnimation = null;
    };

    activeScrollAnimation = window.requestAnimationFrame(step);
};

const smoothAnchorScroll = (target, { animate = true } = {}) => {
    smoothWindowScroll(targetTop(target), { animate });
};

const rootLengthInPixels = (value, rootStyles) => {
    const numericValue = Number.parseFloat(value);

    if (!Number.isFinite(numericValue)) {
        return 0;
    }

    if (value.trim().endsWith('rem')) {
        const rootFontSize = Number.parseFloat(rootStyles.fontSize) || 16;

        return numericValue * rootFontSize;
    }

    return numericValue;
};

const paginationHeaderOffset = () => {
    const rootStyles = window.getComputedStyle(document.documentElement);
    const configuredGap = rootLengthInPixels(
        rootStyles.getPropertyValue('--pagination-scroll-gap'),
        rootStyles,
    );
    const gap = configuredGap > 0 ? configuredGap : 16;
    const header = document.querySelector('[data-site-header]');

    if (!header) {
        return gap;
    }

    const position = window.getComputedStyle(header).position;
    const overlaysViewport = position === 'sticky' || position === 'fixed';

    return (overlaysViewport ? Math.max(0, header.getBoundingClientRect().bottom) : 0) + gap;
};

const paginationTargetTop = (target) => {
    const documentHeight = Math.max(
        document.documentElement.scrollHeight,
        document.body.scrollHeight,
    );
    const maxScrollTop = Math.max(0, documentHeight - window.innerHeight);
    const targetY = window.scrollY + target.getBoundingClientRect().top - paginationHeaderOffset();

    return bounded(0, targetY, maxScrollTop);
};

const startPaginationScroll = (target, { correction = false } = {}) => {
    const endY = paginationTargetTop(target);
    const distance = Math.abs(endY - window.scrollY);

    smoothWindowScroll(endY, {
        animate: !correction || distance >= 24,
        duration: paginationScrollDurationFor(distance),
        easing: easeInOutCubic,
    });
};

const syncCatalogAnchor = (hash = window.location.hash, options = {}) => {
    const targetId = decodeHashTarget(hash);

    if (!targetId) {
        return;
    }

    const target = document.getElementById(targetId);

    if (!target) {
        return;
    }

    if (target instanceof HTMLDetailsElement && target.id.startsWith('season-')) {
        target.open = true;
    }

    window.requestAnimationFrame(() => {
        smoothAnchorScroll(target, options);
    });
};

const loadCatalogSeasonAnchors = () => {
    syncCatalogAnchor(window.location.hash, { animate: true });

    document.addEventListener('click', (event) => {
        const eventTarget = event.target instanceof Element ? event.target : event.target?.parentElement;
        const link = eventTarget?.closest('a[href*="#"]');

        if (!link) {
            return;
        }

        const url = new URL(link.href);

        if (
            url.origin !== window.location.origin
            || url.pathname !== window.location.pathname
            || url.search !== window.location.search
            || !url.hash
        ) {
            return;
        }

        const targetId = decodeHashTarget(url.hash);
        const target = targetId ? document.getElementById(targetId) : null;

        if (!target) {
            return;
        }

        event.preventDefault();
        window.history.pushState(null, '', url.hash);
        syncCatalogAnchor(url.hash, { animate: true });
    });

    window.addEventListener('hashchange', () => {
        syncCatalogAnchor(window.location.hash, { animate: true });
    });
};

const normalizeCatalogFilterText = (value) => value
    .toLocaleLowerCase('ru-RU')
    .replace(/ё/g, 'е')
    .replace(/\s+/g, ' ')
    .trim();

const initializedCatalogFilterSearch = new WeakSet();

const loadCatalogFilterSearch = () => {
    document.querySelectorAll('[data-catalog-filter-group]').forEach((group) => {
        const input = group.querySelector('[data-catalog-filter-search]');

        if (!(input instanceof HTMLInputElement) || initializedCatalogFilterSearch.has(input)) {
            return;
        }

        initializedCatalogFilterSearch.add(input);

        const options = [...group.querySelectorAll('[data-catalog-filter-option]')];
        const emptyState = group.querySelector('[data-catalog-filter-empty]');

        const updateOptions = () => {
            const search = normalizeCatalogFilterText(input.value);
            let visibleOptions = 0;

            options.forEach((option) => {
                const filterText = normalizeCatalogFilterText(
                    option.getAttribute('data-catalog-filter-text') || option.textContent || '',
                );
                const isVisible = search === '' || filterText.includes(search);

                option.classList.toggle('hidden', !isVisible);

                if (isVisible) {
                    visibleOptions += 1;
                }
            });

            emptyState?.classList.toggle('hidden', visibleOptions > 0 || search === '');
        };

        input.addEventListener('input', updateOptions);
        updateOptions();
    });
};

let paginationScrollReady = false;
let pendingPaginationScrollTo = null;

const paginationComponentRoot = (componentId) => {
    if (!componentId) {
        return document;
    }

    return document.querySelector(`[wire\\:id="${CSS.escape(componentId)}"]`) || document;
};

const resolvePaginationScrollTarget = (pending) => {
    const root = paginationComponentRoot(pending.componentId);

    if (pending.selector) {
        const selected = root.querySelector(pending.selector) || document.querySelector(pending.selector);

        if (selected) {
            return selected;
        }
    }

    if (!pending.regionName) {
        return null;
    }

    const region = root.querySelector(`[data-pagination-region="${CSS.escape(pending.regionName)}"]`);

    return region?.matches('[data-pagination-scroll-target]')
        ? region
        : region?.querySelector('[data-pagination-scroll-target]') || null;
};

const clearPaginationScroll = () => {
    const target = pendingPaginationScrollTo ? resolvePaginationScrollTarget(pendingPaginationScrollTo) : null;

    target?.closest('[data-pagination-region]')?.setAttribute('aria-busy', 'false');
    pendingPaginationScrollTo = null;
};

const clearPaginationScrollForComponent = (componentId) => {
    if (!pendingPaginationScrollTo || !componentId || pendingPaginationScrollTo.componentId !== componentId) {
        return;
    }

    clearPaginationScroll();
};

const loadPaginationScroll = () => {
    if (paginationScrollReady) {
        return;
    }

    paginationScrollReady = true;

    document.addEventListener('click', (event) => {
        if (event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) {
            return;
        }

        const eventTarget = event.target instanceof Element ? event.target : event.target?.parentElement;
        const control = eventTarget?.closest('[data-pagination-control]');

        if (!control) {
            return;
        }

        const region = control.closest('[data-pagination-region]');
        const component = control.closest('[wire\\:id]');

        pendingPaginationScrollTo = {
            componentId: component?.getAttribute('wire:id') || '',
            regionName: region?.getAttribute('data-pagination-region') || '',
            selector: control.getAttribute('data-pagination-scroll-to') || '',
        };

        region?.setAttribute('aria-busy', 'true');

        const target = resolvePaginationScrollTarget(pendingPaginationScrollTo);

        if (target) {
            window.requestAnimationFrame(() => startPaginationScroll(target));
        }
    });
};

const flushPaginationScroll = () => {
    const pending = pendingPaginationScrollTo;

    if (!pending) {
        return;
    }

    const target = resolvePaginationScrollTarget(pending);

    pendingPaginationScrollTo = null;

    if (!target) {
        return;
    }

    target.closest('[data-pagination-region]')?.setAttribute('aria-busy', 'false');

    window.requestAnimationFrame(() => {
        startPaginationScroll(target, { correction: true });
    });
};

const loadCatalogInterfaces = () => {
    loadCatalogFilterSearch();
    loadPaginationScroll();
    initializeHeaderSearchInterfaces();
    initializeMobileRuntime();
    void loadPlayerNavigation();

    loadOptionalModule('collections', '[data-collection-dialog], [data-collection-share], [data-collection-dialog-trigger]',
        () => import('./collections.js'),
        (module) => module.initializeCollectionInterfaces(document));
    loadOptionalModule('comments', '[data-comment-dialog], [data-comment-focused], [data-comment-character-counter], [data-preserve-discussion-state]',
        () => import('./comments.js'),
        (module) => module.initializeCommentInterfaces(document));
    loadOptionalModule('reviews', '[data-review-draft], [id^="review-"]',
        () => import('./reviews.js'),
        (module) => module.initializeReviews(document));
    loadOptionalModule('issues', '[data-technical-issue-form], [data-player-issue-link]',
        () => import('./issues.js'),
        (module) => module.initializeTechnicalIssueInterfaces(document));
    loadOptionalModule('release-calendar', '[data-release-countdown]',
        () => import('./release-calendar.js'),
        (module) => module.initializeReleaseCountdowns(document));
    loadOptionalModule('help-center', '[data-help-search]',
        () => import('./help-center.js'),
        (module) => module.initializeHelpCenterInterfaces(document));

    if (document.querySelector('[data-account-settings]') || document.body.dataset.accountMigrationUrl) {
        loadOptionalModule('settings', 'body',
            () => import('./settings.js'),
            (module) => {
                module.initializeAccountSettings(document);
                module.initializeAccountPreferenceMigration();
            });
    }
};

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => {
        void loadCatalogPlayers();
        loadCatalogSeasonAnchors();
        loadCatalogInterfaces();
    });
} else {
    void loadCatalogPlayers();
    loadCatalogSeasonAnchors();
    loadCatalogInterfaces();
}

document.addEventListener('livewire:init', () => {
    const reloadAfterLivewireMorph = () => {
        void loadCatalogPlayers();
        loadCatalogInterfaces();
        flushPaginationScroll();
    };

    window.Livewire.hook('morphed', reloadAfterLivewireMorph);
    window.Livewire.hook('island.morphed', reloadAfterLivewireMorph);
    if (typeof window.Livewire.interceptMessage === 'function') {
        window.Livewire.interceptMessage(({ message, onFinish, onError, onFailure }) => {
            const clearForMessage = () => clearPaginationScrollForComponent(message.component?.id);

            onError(clearForMessage);
            onFailure(clearForMessage);
            onFinish(clearForMessage);
        });
    }
    window.Livewire.hook('morph.removing', ({ el }) => {
        destroyCatalogPlayersWithin(el);
        destroyPlayerNavigationWithin(el);
    });
});

document.addEventListener('livewire:navigating', () => {
    cancelActiveScroll();
    clearPaginationScroll();
    flushCatalogPlayersWithin(document, 'navigation');
    destroyCatalogPlayersWithin(document, { flush: false });
    destroyPlayerNavigationWithin(document);
});

document.addEventListener('livewire:navigated', () => {
    void loadCatalogPlayers();
    loadCatalogInterfaces();
});

window.addEventListener('pagehide', () => {
    cancelActiveScroll();
    clearPaginationScroll();
    flushCatalogPlayersWithin(document, 'pagehide');
    destroyCatalogPlayersWithin(document, { flush: false });
    destroyPlayerNavigationWithin(document);
});

window.addEventListener('pageshow', (event) => {
    if (event.persisted) {
        if (document.body.dataset.privatePage === '1') {
            return;
        }

        void loadCatalogPlayers();
        void loadPlayerNavigation();
        loadCatalogInterfaces();
    }
});
