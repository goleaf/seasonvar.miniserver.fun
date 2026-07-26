const initializedHeaderSearches = new WeakSet();
const suggestionCache = new Map();
const recentMemory = new Map();
const MAX_CACHED_RESPONSES = 120;
const MAX_RECENT_QUERIES = 5;
const RECENT_STORAGE_VERSION = 1;

let globalSearchRuntimeReady = false;
let mobileSearchInvoker = null;

const compactSearch = window.matchMedia('(max-width: 63.999rem)');
const interfaceLocale = () => document.documentElement.lang || 'ru';
const recentStorageKey = () => `seasonvar:header-search:recent:v${RECENT_STORAGE_VERSION}:${interfaceLocale()}`;

const normalizedQuery = (value) => value
    .toLocaleLowerCase(interfaceLocale())
    .replace(/ё/g, 'е')
    .replace(/\s+/g, ' ')
    .trim();

const displayQuery = (value) => String(value || '')
    .normalize('NFKC')
    .replace(/\s+/gu, ' ')
    .trim()
    .slice(0, 80);

const element = (tagName, className = '', text = '') => {
    const node = document.createElement(tagName);

    node.className = className;
    node.textContent = text;

    return node;
};

const sameOriginUrl = (value, fallback = '#') => {
    if (typeof value !== 'string' || value === '') {
        return fallback;
    }

    try {
        const url = new URL(value, window.location.origin);

        return url.origin === window.location.origin ? url.toString() : fallback;
    } catch {
        return fallback;
    }
};

const readRecentQueries = () => {
    const key = recentStorageKey();

    try {
        const stored = JSON.parse(window.sessionStorage.getItem(key) || '[]');

        if (Array.isArray(stored)) {
            const recent = stored
                .filter((query) => typeof query === 'string')
                .map(displayQuery)
                .filter((query) => query !== '')
                .slice(0, MAX_RECENT_QUERIES);

            recentMemory.set(key, recent);

            return recent;
        }
    } catch {
        // Storage can be unavailable in private or constrained browser contexts.
    }

    return recentMemory.get(key) || [];
};

const writeRecentQueries = (queries) => {
    const key = recentStorageKey();
    const bounded = queries
        .map(displayQuery)
        .filter((query) => query !== '')
        .filter((query, index, values) => values.findIndex(
            (candidate) => normalizedQuery(candidate) === normalizedQuery(query),
        ) === index)
        .slice(0, MAX_RECENT_QUERIES);

    recentMemory.set(key, bounded);

    try {
        window.sessionStorage.setItem(key, JSON.stringify(bounded));
    } catch {
        // The in-memory copy keeps the feature usable without persistent storage.
    }

    return bounded;
};

const rememberQuery = (query) => {
    const display = displayQuery(query);

    if (display === '') {
        return readRecentQueries();
    }

    return writeRecentQueries([
        display,
        ...readRecentQueries().filter(
            (candidate) => normalizedQuery(candidate) !== normalizedQuery(display),
        ),
    ]);
};

const clearRecentQueries = () => {
    const key = recentStorageKey();

    recentMemory.delete(key);

    try {
        window.sessionStorage.removeItem(key);
    } catch {
        // Nothing else is required when browser storage is unavailable.
    }
};

const cachedSuggestions = async (endpoint, query, scope, signal) => {
    const locale = interfaceLocale();
    const key = `${endpoint}|${scope}|${locale}|${normalizedQuery(query)}`;

    if (suggestionCache.has(key)) {
        return suggestionCache.get(key);
    }

    const url = new URL(endpoint, window.location.origin);

    if (url.origin !== window.location.origin) {
        throw new Error('Header search endpoint must be same-origin');
    }

    url.searchParams.set('q', query);
    url.searchParams.set('scope', scope);

    const response = await fetch(url, {
        headers: {
            Accept: 'application/json',
            'Accept-Language': locale,
        },
        signal,
    });

    if (!response.ok) {
        throw new Error(`Header search request failed with ${response.status}`);
    }

    const payload = await response.json();
    const suggestions = Array.isArray(payload.data) ? payload.data : [];

    suggestionCache.set(key, suggestions);

    while (suggestionCache.size > MAX_CACHED_RESPONSES) {
        suggestionCache.delete(suggestionCache.keys().next().value);
    }

    return suggestions;
};

const presentationLimits = () => {
    if (window.innerHeight < 720) {
        return { titles: 2, portal: 3 };
    }

    if (window.innerWidth < 640) {
        return { titles: 3, portal: 4 };
    }

    if (window.innerWidth < 1024) {
        return { titles: 4, portal: 6 };
    }

    return { titles: 5, portal: 8 };
};

const optionLink = (item, className) => {
    const link = element('a', className);

    link.href = sameOriginUrl(item.url);
    link.setAttribute('role', 'option');
    link.dataset.searchOption = '';

    return link;
};

const titleCard = (item) => {
    const link = optionLink(
        item,
        'flex min-h-16 min-w-0 items-center gap-3 rounded-control px-2 py-2 text-left text-slate-900 transition hover:bg-slate-100 focus-visible:bg-slate-100 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-emerald-300',
    );
    const poster = element('span', 'grid h-[4.125rem] w-11 shrink-0 place-items-center overflow-hidden rounded-control bg-slate-100 text-slate-600');

    if (typeof item.poster_url === 'string' && item.poster_url !== '') {
        const image = element('img', 'h-full w-full object-contain');

        image.src = item.poster_url;
        image.alt = '';
        image.loading = 'eager';
        image.decoding = 'async';
        image.referrerPolicy = 'no-referrer';
        image.addEventListener('error', () => {
            image.remove();
            poster.textContent = '▶';
            poster.setAttribute('aria-hidden', 'true');
        }, { once: true });
        poster.append(image);
    } else {
        poster.textContent = '▶';
        poster.setAttribute('aria-hidden', 'true');
    }

    const copy = element('span', 'min-w-0');
    const title = element('span', 'block break-words text-sm font-semibold leading-5', String(item.label || ''));
    const originalTitle = typeof item.original_title === 'string' ? item.original_title.trim() : '';
    const metaValue = typeof item.meta === 'string' ? item.meta.trim() : '';

    copy.append(title);

    if (originalTitle !== '' && originalTitle !== String(item.label || '').trim()) {
        copy.append(element('span', 'mt-0.5 block break-words text-xs font-medium leading-4 text-slate-600', originalTitle));
    }

    if (metaValue !== '') {
        copy.append(element('span', 'mt-1 block break-words text-xs font-medium leading-4 text-slate-600', metaValue));
    }

    link.append(poster, copy);

    return link;
};

const portalLink = (item) => {
    const link = optionLink(
        item,
        'flex min-h-11 min-w-0 items-center justify-between gap-3 rounded-control px-3 py-2 text-left text-sm text-slate-900 transition hover:bg-slate-100 focus-visible:bg-slate-100 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-emerald-300',
    );
    const label = element('span', 'min-w-0 break-words font-semibold leading-5', String(item.label || ''));
    const meta = element('span', 'shrink-0 text-xs font-medium text-slate-600', String(item.meta || ''));

    link.append(label, meta);

    return link;
};

const isEditableTarget = (target) => target instanceof Element
    && target.closest('input, textarea, select, [contenteditable="true"], [role="textbox"]') !== null;

const visibleFocusable = (root) => [...root.querySelectorAll(
    'a[href], button:not([disabled]), input:not([disabled]), [tabindex]:not([tabindex="-1"])',
)].filter((candidate) => candidate instanceof HTMLElement && candidate.getClientRects().length > 0);

const closeMobileSearch = (root, returnFocus = true) => {
    if (!(root instanceof HTMLElement) || root.dataset.mobileOpen !== 'true') {
        return;
    }

    root.dataset.mobileOpen = 'false';
    root.removeAttribute('role');
    root.removeAttribute('aria-modal');
    document.documentElement.classList.remove('app-search-open');
    root.dispatchEvent(new CustomEvent('header-search:close'));

    if (returnFocus && mobileSearchInvoker instanceof HTMLElement && mobileSearchInvoker.isConnected) {
        mobileSearchInvoker.focus({ preventScroll: true });
    }

    mobileSearchInvoker = null;
};

const openHeaderSearch = (root, invoker = null) => {
    if (!(root instanceof HTMLElement)) {
        return;
    }

    if (compactSearch.matches) {
        mobileSearchInvoker = invoker instanceof HTMLElement ? invoker : document.activeElement;
        root.dataset.mobileOpen = 'true';
        root.setAttribute('role', 'dialog');
        root.setAttribute('aria-modal', 'true');
        root.setAttribute('aria-label', root.dataset.shortcutLabel || '');
        document.documentElement.classList.add('app-search-open');
    }

    root.dispatchEvent(new CustomEvent('header-search:open'));
};

const initializeGlobalSearchRuntime = () => {
    if (globalSearchRuntimeReady) {
        return;
    }

    globalSearchRuntimeReady = true;

    document.addEventListener('click', (event) => {
        const trigger = event.target instanceof Element
            ? event.target.closest('[data-header-search-open]')
            : null;

        if (trigger instanceof HTMLElement) {
            event.preventDefault();
            openHeaderSearch(document.querySelector('[data-header-search-autocomplete]'), trigger);
        }
    });

    document.addEventListener('keydown', (event) => {
        const commandK = (event.ctrlKey || event.metaKey)
            && event.key.toLocaleLowerCase() === 'k';
        const slash = event.key === '/' && !event.ctrlKey && !event.metaKey && !event.altKey;

        if ((!commandK && !slash) || isEditableTarget(event.target)) {
            return;
        }

        const root = document.querySelector('[data-header-search-autocomplete]');

        if (!(root instanceof HTMLElement)) {
            return;
        }

        event.preventDefault();
        openHeaderSearch(root, event.target);
    });

    document.addEventListener('livewire:navigating', () => {
        document.querySelectorAll('[data-header-search-autocomplete][data-mobile-open="true"]')
            .forEach((root) => closeMobileSearch(root, false));
    });
};

const initializeHeaderSearch = (root) => {
    if (initializedHeaderSearches.has(root)) {
        return;
    }

    const input = root.querySelector('[data-header-search-input]');
    const form = root.querySelector('[data-header-search-form]');
    const clearButton = root.querySelector('[data-header-search-clear]');
    const closeButton = root.querySelector('[data-header-search-close]');
    const mobileCloseButton = root.querySelector('[data-header-search-mobile-close]');
    const dropdown = root.querySelector('[data-header-search-dropdown]');
    const recentSection = root.querySelector('[data-header-search-recent]');
    const recentResults = root.querySelector('[data-header-search-recent-results]');
    const recentClear = root.querySelector('[data-header-search-recent-clear]');
    const titleSection = root.querySelector('[data-header-search-title-section]');
    const titleResults = root.querySelector('[data-header-search-title-results]');
    const portalSection = root.querySelector('[data-header-search-portal-section]');
    const portalResults = root.querySelector('[data-header-search-portal-results]');
    const status = root.querySelector('[data-header-search-status]');
    const spinner = root.querySelector('[data-header-search-spinner]');
    const allResults = root.querySelector('[data-header-search-all-results]');
    const requestAction = root.querySelector('[data-header-search-request]');
    const endpoint = root.dataset.suggestionsEndpoint || '';
    const searchUrl = root.dataset.searchUrl || '';
    const catalogSearchUrl = root.dataset.catalogSearchUrl || '';
    const requestCreateUrl = root.dataset.requestCreateUrl || '';

    if (
        !(input instanceof HTMLInputElement)
        || !(form instanceof HTMLFormElement)
        || !(clearButton instanceof HTMLButtonElement)
        || !(closeButton instanceof HTMLButtonElement)
        || !(mobileCloseButton instanceof HTMLButtonElement)
        || !(dropdown instanceof HTMLElement)
        || !(recentSection instanceof HTMLElement)
        || !(recentResults instanceof HTMLElement)
        || !(recentClear instanceof HTMLButtonElement)
        || !(titleSection instanceof HTMLElement)
        || !(titleResults instanceof HTMLElement)
        || !(portalSection instanceof HTMLElement)
        || !(portalResults instanceof HTMLElement)
        || !(status instanceof HTMLElement)
        || !(spinner instanceof HTMLElement)
        || !(allResults instanceof HTMLAnchorElement)
        || endpoint === ''
        || searchUrl === ''
        || catalogSearchUrl === ''
    ) {
        return;
    }

    initializedHeaderSearches.add(root);

    const groupLabels = {
        people: root.dataset.groupPeople || '',
        directories: root.dataset.groupDirectories || '',
        community: root.dataset.groupCommunity || '',
        sections: root.dataset.groupSections || '',
    };
    let activeIndex = -1;
    let debounceTimer = null;
    let requestControllers = [];
    let requestSequence = 0;
    let renderedQuery = '';
    let renderedTitles = [];
    let renderedPortal = [];
    let renderedStatus = '';
    let renderedPending = 0;
    let renderedFailures = 0;

    const options = () => [...root.querySelectorAll('[data-search-option]:not(.hidden)')];

    const setExpanded = (expanded) => {
        dropdown.classList.toggle('hidden', !expanded);
        input.setAttribute('aria-expanded', expanded ? 'true' : 'false');

        if (!expanded) {
            activeIndex = -1;
            input.setAttribute('aria-activedescendant', '');
        }
    };

    const setLoading = (loading) => {
        spinner.classList.toggle('hidden', !loading);
        spinner.classList.toggle('grid', loading);
        input.setAttribute('aria-busy', loading ? 'true' : 'false');
    };

    const setClearAvailable = (available) => {
        clearButton.classList.toggle('hidden', !available);
        clearButton.classList.toggle('grid', available);
    };

    const setStatus = (message = '') => {
        status.textContent = message;
        status.classList.toggle('hidden', message === '');
    };

    const setActionVisible = (action, visible) => {
        if (!(action instanceof HTMLElement)) {
            return;
        }

        action.classList.toggle('hidden', !visible);
        action.classList.toggle('flex', visible);
    };

    const updateActionUrls = (query) => {
        const catalogUrl = new URL(catalogSearchUrl, window.location.origin);

        catalogUrl.searchParams.set('q', query);
        allResults.href = catalogUrl.toString();

        if (requestAction instanceof HTMLAnchorElement && requestCreateUrl !== '') {
            requestAction.href = sameOriginUrl(requestCreateUrl);
        }
    };

    const renderRecent = () => {
        const queries = readRecentQueries();

        recentResults.replaceChildren(...queries.map((query) => {
            const link = element(
                'a',
                'flex min-h-11 items-center gap-3 rounded-control px-2 py-2 text-sm font-medium text-slate-700 hover:bg-slate-100 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-emerald-300',
            );
            const url = new URL(searchUrl, window.location.origin);

            url.searchParams.set('q', query);
            link.href = url.toString();
            link.dataset.headerSearchRecentItem = '';
            link.append(
                element('span', 'text-slate-600', '↺'),
                element('span', 'min-w-0 break-words', query),
            );

            return link;
        }));
        recentSection.classList.toggle('hidden', queries.length === 0 || input.value.trim() !== '');

        return queries.length > 0;
    };

    const setActive = (index) => {
        const available = options();

        if (available.length === 0) {
            activeIndex = -1;
            input.setAttribute('aria-activedescendant', '');
            return;
        }

        activeIndex = (index + available.length) % available.length;

        available.forEach((option, optionIndex) => {
            const active = optionIndex === activeIndex;

            option.id = `site-search-option-${optionIndex}`;
            option.setAttribute('aria-selected', active ? 'true' : 'false');
            option.classList.toggle('bg-emerald-50', active);
            option.classList.toggle('text-emerald-900', active);
        });

        const selected = available[activeIndex];

        input.setAttribute('aria-activedescendant', selected.id);
        selected.scrollIntoView({ block: 'nearest' });
    };

    const prepareOptions = () => {
        options().forEach((option, index) => {
            option.id = `site-search-option-${index}`;
            option.setAttribute('aria-selected', 'false');
            option.onmouseenter = () => setActive(index);
        });
        activeIndex = -1;
        input.setAttribute('aria-activedescendant', '');
    };

    const renderPortalGroups = (items) => {
        const groups = new Map();

        items.forEach((item) => {
            const group = Object.hasOwn(groupLabels, item.group) ? item.group : 'directories';

            if (!groups.has(group)) {
                groups.set(group, []);
            }

            groups.get(group).push(item);
        });

        portalResults.replaceChildren();

        Object.keys(groupLabels).forEach((group) => {
            const groupItems = groups.get(group) || [];

            if (groupItems.length === 0) {
                return;
            }

            const section = element('section', 'min-w-0');
            const heading = element('span', 'block px-2 pb-1 text-xs font-semibold text-slate-600', groupLabels[group]);
            const list = element('div', 'space-y-0.5');

            section.setAttribute('role', 'group');
            section.setAttribute('aria-label', groupLabels[group]);
            heading.setAttribute('aria-hidden', 'true');
            groupItems.forEach((item) => list.append(portalLink(item)));
            section.append(heading, list);
            portalResults.append(section);
        });

        portalSection.classList.toggle('hidden', portalResults.childElementCount === 0);
    };

    const render = (query, titles, portal, message = '', pending = 0, failures = 0) => {
        const limits = presentationLimits();
        const visibleTitles = titles.slice(0, limits.titles);
        const visiblePortal = portal.slice(0, limits.portal);
        const normalized = normalizedQuery(query);

        recentSection.classList.add('hidden');
        titleResults.replaceChildren(...visibleTitles.map((item) => titleCard(item)));
        titleSection.classList.toggle('hidden', visibleTitles.length === 0);
        renderPortalGroups(visiblePortal);

        const hasSuggestions = visibleTitles.length > 0 || visiblePortal.length > 0;
        const completeEmpty = normalized.length >= 2
            && pending === 0
            && failures === 0
            && !hasSuggestions;

        updateActionUrls(query);
        setActionVisible(allResults, normalized !== '');
        setActionVisible(requestAction, completeEmpty);
        setStatus(hasSuggestions ? '' : message);
        prepareOptions();
        setExpanded(true);
        renderedQuery = normalized;
        renderedTitles = titles;
        renderedPortal = portal;
        renderedStatus = message;
        renderedPending = pending;
        renderedFailures = failures;
    };

    const abortRequests = () => {
        requestControllers.forEach((controller) => controller.abort());
        requestControllers = [];
    };

    const resetResults = () => {
        titleResults.replaceChildren();
        portalResults.replaceChildren();
        titleSection.classList.add('hidden');
        portalSection.classList.add('hidden');
        setActionVisible(allResults, false);
        setActionVisible(requestAction, false);
        renderedQuery = '';
        renderedTitles = [];
        renderedPortal = [];
        renderedStatus = '';
        renderedPending = 0;
        renderedFailures = 0;
    };

    const lookup = () => {
        const query = displayQuery(input.value);
        const normalized = normalizedQuery(query);

        abortRequests();

        if (normalized.length === 0) {
            resetResults();
            setLoading(false);
            setStatus('');
            setExpanded(renderRecent());

            return;
        }

        const sequence = ++requestSequence;
        const titleController = new AbortController();
        const portalController = normalized.length >= 2 ? new AbortController() : null;
        let titles = [];
        let portal = [];
        let pending = portalController === null ? 1 : 2;
        let failures = 0;

        requestControllers = portalController === null ? [titleController] : [titleController, portalController];
        recentSection.classList.add('hidden');
        updateActionUrls(query);
        setActionVisible(allResults, true);
        setActionVisible(requestAction, false);
        setLoading(true);
        setStatus(root.dataset.loadingLabel || '');
        setExpanded(true);

        const settle = (scope, items, failed = false) => {
            if (sequence !== requestSequence || normalizedQuery(input.value) !== normalized) {
                return;
            }

            if (scope === 'titles') {
                titles = items;
            } else {
                portal = items;
            }

            failures += failed ? 1 : 0;
            pending -= 1;

            const message = pending > 0
                ? (root.dataset.loadingLabel || '')
                : (failures > 0
                    ? (root.dataset.errorLabel || '')
                    : (normalized.length === 1 ? (root.dataset.minimumLabel || '') : (root.dataset.emptyLabel || '')));

            render(query, titles, portal, message, pending, failures);
            setLoading(pending > 0);

            if (pending === 0) {
                requestControllers = [];
            }
        };

        const request = (scope, cacheScope, controller) => {
            cachedSuggestions(endpoint, query, cacheScope, controller.signal)
                .then((items) => settle(scope, items))
                .catch((error) => {
                    if (!(error instanceof DOMException && error.name === 'AbortError')) {
                        settle(scope, [], true);
                    }
                });
        };

        request('titles', 'header_titles', titleController);
        if (portalController !== null) {
            request('portal', 'header_portal', portalController);
        }
    };

    input.addEventListener('input', () => {
        setClearAvailable(input.value !== '');
        recentSection.classList.add('hidden');
        window.clearTimeout(debounceTimer);
        debounceTimer = window.setTimeout(lookup, 160);
    });

    clearButton.addEventListener('click', () => {
        window.clearTimeout(debounceTimer);
        requestSequence += 1;
        abortRequests();
        input.value = '';
        resetResults();
        setStatus('');
        setLoading(false);
        setClearAvailable(false);
        setExpanded(renderRecent());
        input.focus();
    });

    recentClear.addEventListener('click', () => {
        clearRecentQueries();
        recentResults.replaceChildren();
        recentSection.classList.add('hidden');
        setExpanded(input.value.trim() !== '');
        input.focus();
    });

    closeButton.addEventListener('click', () => {
        setExpanded(false);
        input.focus();
    });

    mobileCloseButton.addEventListener('click', () => {
        closeMobileSearch(root);
    });

    root.addEventListener('header-search:open', () => {
        if (input.value.trim() === '') {
            setExpanded(renderRecent());
        } else if (renderedQuery === normalizedQuery(input.value)) {
            setExpanded(true);
        }

        window.requestAnimationFrame(() => input.focus({ preventScroll: true }));
    });

    root.addEventListener('header-search:close', () => {
        setExpanded(false);
    });

    input.addEventListener('focus', () => {
        const normalized = normalizedQuery(input.value);

        if (normalized === '') {
            setExpanded(renderRecent());
        } else if (normalized === renderedQuery) {
            setExpanded(true);
        }
    });

    root.addEventListener('click', (event) => {
        const target = event.target instanceof Element
            ? event.target.closest('[data-search-option], [data-header-search-recent-item]')
            : null;

        if (target instanceof HTMLAnchorElement && target.hasAttribute('data-search-option')) {
            rememberQuery(input.value);
        }
    });

    root.addEventListener('keydown', (event) => {
        if (event.key === 'Tab' && root.dataset.mobileOpen === 'true') {
            const focusable = visibleFocusable(root);
            const first = focusable[0];
            const last = focusable.at(-1);

            if (event.shiftKey && document.activeElement === first) {
                event.preventDefault();
                last?.focus();
            } else if (!event.shiftKey && document.activeElement === last) {
                event.preventDefault();
                first?.focus();
            }

            return;
        }

        if (event.target !== input) {
            if (event.key === 'Escape' && root.dataset.mobileOpen === 'true') {
                event.preventDefault();
                closeMobileSearch(root);
            }

            return;
        }

        switch (event.key) {
            case 'ArrowDown':
                if (options().length > 0) {
                    event.preventDefault();
                    setExpanded(true);
                    setActive(activeIndex + 1);
                }
                break;
            case 'ArrowUp':
                if (options().length > 0) {
                    event.preventDefault();
                    setExpanded(true);
                    setActive(activeIndex - 1);
                }
                break;
            case 'Home':
                if (options().length > 0 && !dropdown.classList.contains('hidden')) {
                    event.preventDefault();
                    setActive(0);
                }
                break;
            case 'End':
                if (options().length > 0 && !dropdown.classList.contains('hidden')) {
                    event.preventDefault();
                    setActive(options().length - 1);
                }
                break;
            case 'Enter':
                if (activeIndex >= 0) {
                    event.preventDefault();
                    options()[activeIndex]?.click();
                }
                break;
            case 'Escape':
                event.preventDefault();
                event.stopPropagation();
                if (root.dataset.mobileOpen === 'true') {
                    closeMobileSearch(root);
                } else {
                    setExpanded(false);
                }
                break;
            default:
                break;
        }
    });

    form.addEventListener('submit', () => {
        rememberQuery(input.value);
        abortRequests();
        setExpanded(false);
    });

    window.addEventListener('resize', () => {
        if (root.dataset.mobileOpen === 'true' && !compactSearch.matches) {
            closeMobileSearch(root, false);
        }

        if (renderedQuery !== '' && !dropdown.classList.contains('hidden')) {
            render(
                input.value,
                renderedTitles,
                renderedPortal,
                renderedStatus,
                renderedPending,
                renderedFailures,
            );
        }
    });

    document.addEventListener('pointerdown', (event) => {
        if (
            root.dataset.mobileOpen !== 'true'
            && event.target instanceof Node
            && !root.contains(event.target)
        ) {
            setExpanded(false);
        }
    });
};

export const initializeHeaderSearchInterfaces = (root = document) => {
    initializeGlobalSearchRuntime();
    root.querySelectorAll?.('[data-header-search-autocomplete]').forEach(initializeHeaderSearch);
};
