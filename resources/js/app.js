import '@fortawesome/fontawesome-free/css/fontawesome.min.css';
import '@fortawesome/fontawesome-free/css/solid.min.css';
import '@fortawesome/fontawesome-free/css/regular.min.css';
import '../css/app.css';

const loadCatalogPlayers = async () => {
    if (!document.querySelector('video.js-catalog-player:not([data-player-ready])')) {
        return;
    }

    const { initializeCatalogPlayers } = await import('./player.js');

    await initializeCatalogPlayers();
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

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => {
        void loadCatalogPlayers();
        loadCatalogSeasonAnchors();
    });
} else {
    void loadCatalogPlayers();
    loadCatalogSeasonAnchors();
}
