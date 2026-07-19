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

let activeScrollAnimation = null;

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

const smoothAnchorScroll = (target, { animate = true } = {}) => {
    const startY = window.scrollY;
    const endY = targetTop(target);
    const distance = Math.abs(endY - startY);

    if (activeScrollAnimation !== null) {
        window.cancelAnimationFrame(activeScrollAnimation);
        activeScrollAnimation = null;
    }

    if (!animate || reducedMotionQuery.matches || distance < 2) {
        window.scrollTo(0, endY);
        return;
    }

    const duration = anchorScrollDuration(distance);
    const startedAt = window.performance.now();
    const easeOutCubic = (progress) => 1 - ((1 - progress) ** 3);

    const step = (timestamp) => {
        const progress = bounded(0, (timestamp - startedAt) / duration, 1);
        const easedProgress = easeOutCubic(progress);

        window.scrollTo(0, startY + (endY - startY) * easedProgress);

        if (progress < 1) {
            activeScrollAnimation = window.requestAnimationFrame(step);
            return;
        }

        activeScrollAnimation = null;
    };

    activeScrollAnimation = window.requestAnimationFrame(step);
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

const loadPaginationScroll = () => {
    if (paginationScrollReady) {
        return;
    }

    paginationScrollReady = true;

    document.addEventListener('click', (event) => {
        const eventTarget = event.target instanceof Element ? event.target : event.target?.parentElement;
        const control = eventTarget?.closest('[data-pagination-control]');
        const selector = control?.getAttribute('data-pagination-scroll-to') || '';

        pendingPaginationScrollTo = selector === '' ? null : selector;
    });
};

const flushPaginationScroll = () => {
    const selector = pendingPaginationScrollTo;

    pendingPaginationScrollTo = null;

    if (!selector) {
        return;
    }

    const target = document.querySelector(selector);

    if (!target) {
        return;
    }

    window.requestAnimationFrame(() => {
        smoothAnchorScroll(target, { animate: true });
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
    window.Livewire.hook('morph.removing', ({ el }) => {
        destroyCatalogPlayersWithin(el);
        destroyPlayerNavigationWithin(el);
    });
});

document.addEventListener('livewire:navigating', () => {
    pendingPaginationScrollTo = null;
    flushCatalogPlayersWithin(document, 'navigation');
    destroyCatalogPlayersWithin(document, { flush: false });
    destroyPlayerNavigationWithin(document);
});

document.addEventListener('livewire:navigated', () => {
    void loadCatalogPlayers();
    loadCatalogInterfaces();
});

window.addEventListener('pagehide', () => {
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
