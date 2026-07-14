import '@fortawesome/fontawesome-free/css/fontawesome.min.css';
import '@fortawesome/fontawesome-free/css/solid.min.css';
import '@fortawesome/fontawesome-free/css/regular.min.css';
import '../css/app.css';

let catalogPlayerModule = null;
let catalogPlayerModulePromise = null;

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

const loadCatalogFilterSearch = () => {
    document.querySelectorAll('[data-catalog-filter-group]').forEach((group) => {
        const input = group.querySelector('[data-catalog-filter-search]');

        if (!(input instanceof HTMLInputElement)) {
            return;
        }

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

const initializedPeopleComboboxes = new WeakSet();

const peopleFilterUrl = (type, slug) => {
    const url = new URL(window.location.href);
    const indexedParameter = new RegExp(`^${type}\\[(\\d+)]$`);
    const selected = [];
    const indices = [];

    url.searchParams.delete('page');
    url.hash = '';

    url.searchParams.forEach((value, key) => {
        const match = key.match(indexedParameter);

        if (key === type || match) {
            selected.push(value);
        }

        if (match) {
            indices.push(Number.parseInt(match[1], 10));
        }
    });

    if (!selected.includes(slug)) {
        const nextIndex = indices.length === 0 ? 0 : Math.max(...indices) + 1;

        url.searchParams.append(`${type}[${nextIndex}]`, slug);
    }

    return url.toString();
};

const loadCatalogPeopleComboboxes = () => {
    document.querySelectorAll('[data-catalog-people-combobox]').forEach((combobox) => {
        if (initializedPeopleComboboxes.has(combobox)) {
            return;
        }

        const input = combobox.querySelector('[data-catalog-people-input]');
        const options = combobox.querySelector('[data-catalog-people-options]');
        const status = combobox.querySelector('[data-catalog-people-status]');
        const loading = combobox.querySelector('[data-catalog-people-loading]');
        const type = combobox.getAttribute('data-people-type');
        const endpoint = combobox.getAttribute('data-people-endpoint');

        if (!(input instanceof HTMLInputElement)
            || !(options instanceof HTMLElement)
            || !['actor', 'director'].includes(type)
            || !endpoint) {
            return;
        }

        initializedPeopleComboboxes.add(combobox);

        let debounceTimer = null;
        let requestController = null;
        let optionLinks = [];
        let activeIndex = -1;

        const setExpanded = (expanded) => {
            input.setAttribute('aria-expanded', expanded ? 'true' : 'false');
            options.classList.toggle('hidden', !expanded);
        };

        const setActive = (index) => {
            if (optionLinks.length === 0) {
                activeIndex = -1;
                input.removeAttribute('aria-activedescendant');
                return;
            }

            activeIndex = (index + optionLinks.length) % optionLinks.length;

            optionLinks.forEach((link, optionIndex) => {
                const active = optionIndex === activeIndex;
                link.setAttribute('aria-selected', active ? 'true' : 'false');
                link.classList.toggle('bg-emerald-50', active);
            });

            const activeOption = optionLinks[activeIndex];
            input.setAttribute('aria-activedescendant', activeOption.id);
            activeOption.scrollIntoView({ block: 'nearest' });
        };

        const closeOptions = () => {
            setExpanded(false);
            setActive(-1);
        };

        const renderOptions = (people) => {
            options.replaceChildren();
            optionLinks = people.map((person, index) => {
                const link = document.createElement('a');
                const name = document.createElement('span');
                const count = document.createElement('span');

                link.id = `${input.id}-option-${index}`;
                link.href = peopleFilterUrl(type, person.slug);
                link.setAttribute('role', 'option');
                link.setAttribute('aria-selected', 'false');
                link.className = 'flex min-h-11 items-center justify-between gap-3 rounded-lg px-3 py-2 text-sm font-semibold text-emerald-950 hover:bg-emerald-50 hover:text-emerald-700';
                name.className = 'min-w-0 break-words';
                name.textContent = person.name;
                count.className = 'shrink-0 text-xs font-bold tabular-nums text-emerald-700';
                count.textContent = String(person.count);
                link.append(name, count);
                options.append(link);

                return link;
            });

            activeIndex = -1;
            status.textContent = people.length === 0
                ? 'Совпадений не найдено.'
                : `Найдено вариантов: ${people.length}.`;
            setExpanded(true);
        };

        const lookup = async () => {
            const query = normalizeCatalogFilterText(input.value);

            requestController?.abort();
            requestController = null;

            if (query.length < 2) {
                status.textContent = 'Введите не менее двух символов.';
                options.replaceChildren();
                optionLinks = [];
                closeOptions();
                return;
            }

            const controller = new AbortController();
            const url = new URL(endpoint, window.location.origin);

            requestController = controller;
            url.searchParams.set('type', type);
            url.searchParams.set('q', query);
            loading?.classList.remove('hidden');
            status.textContent = 'Ищем варианты…';

            try {
                const response = await fetch(url, {
                    headers: { Accept: 'application/json' },
                    signal: controller.signal,
                });

                if (!response.ok) {
                    throw new Error(`People lookup failed with ${response.status}`);
                }

                const payload = await response.json();

                if (requestController !== controller) {
                    return;
                }

                renderOptions(Array.isArray(payload.data) ? payload.data : []);
            } catch (error) {
                if (error instanceof DOMException && error.name === 'AbortError') {
                    return;
                }

                options.replaceChildren();
                optionLinks = [];
                status.textContent = 'Не удалось загрузить варианты. Повторите поиск.';
                setExpanded(true);
            } finally {
                if (requestController === controller) {
                    requestController = null;
                    loading?.classList.add('hidden');
                }
            }
        };

        input.addEventListener('input', () => {
            window.clearTimeout(debounceTimer);
            debounceTimer = window.setTimeout(() => void lookup(), 300);
        });

        input.addEventListener('keydown', (event) => {
            switch (event.key) {
                case 'ArrowDown':
                    if (optionLinks.length > 0) {
                        event.preventDefault();
                        setExpanded(true);
                        setActive(activeIndex + 1);
                    }
                    break;
                case 'ArrowUp':
                    if (optionLinks.length > 0) {
                        event.preventDefault();
                        setExpanded(true);
                        setActive(activeIndex - 1);
                    }
                    break;
                case 'Enter':
                    if (activeIndex >= 0) {
                        event.preventDefault();
                        optionLinks[activeIndex].click();
                    }
                    break;
                case 'Escape':
                    if (!options.classList.contains('hidden')) {
                        event.preventDefault();
                        event.stopPropagation();
                        closeOptions();
                    }
                    break;
                default:
                    break;
            }
        });

        input.addEventListener('blur', () => {
            window.setTimeout(closeOptions, 150);
        });
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
    loadCatalogPeopleComboboxes();
    loadPaginationScroll();
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
    });
});

document.addEventListener('livewire:navigating', () => {
    pendingPaginationScrollTo = null;
    flushCatalogPlayersWithin(document, 'navigation');
    destroyCatalogPlayersWithin(document, { flush: false });
});

document.addEventListener('livewire:navigated', () => {
    void loadCatalogPlayers();
    loadCatalogInterfaces();
});

window.addEventListener('pagehide', () => {
    flushCatalogPlayersWithin(document, 'pagehide');
    destroyCatalogPlayersWithin(document, { flush: false });
});

window.addEventListener('pageshow', (event) => {
    if (event.persisted) {
        void loadCatalogPlayers();
    }
});

window.addEventListener('beforeunload', () => {
    flushCatalogPlayersWithin(document, 'beforeunload');
});
