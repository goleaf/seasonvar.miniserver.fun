const initializedHeaderSearches = new WeakSet();
const suggestionCache = new Map();
const MAX_CACHED_RESPONSES = 120;

const interfaceLocale = () => document.documentElement.lang || 'ru';

const normalizedQuery = (value) => value
    .toLocaleLowerCase(interfaceLocale())
    .replace(/ё/g, 'е')
    .replace(/\s+/g, ' ')
    .trim();

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
        'flex min-h-16 min-w-0 items-center gap-3 rounded-control px-2 py-2 text-left text-emerald-950 transition hover:bg-emerald-50 focus-visible:bg-emerald-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-emerald-300',
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
    const title = element('span', 'block break-words text-sm font-black leading-5', String(item.label || ''));
    const originalTitle = typeof item.original_title === 'string' ? item.original_title.trim() : '';
    const metaValue = typeof item.meta === 'string' ? item.meta.trim() : '';

    copy.append(title);

    if (originalTitle !== '' && originalTitle !== String(item.label || '').trim()) {
        copy.append(element('span', 'mt-0.5 block break-words text-xs font-semibold leading-4 text-slate-500', originalTitle));
    }

    if (metaValue !== '') {
        copy.append(element('span', 'mt-1 block text-xs font-semibold leading-4 text-emerald-800', metaValue));
    }

    link.append(poster, copy);

    return link;
};

const portalLink = (item) => {
    const link = optionLink(
        item,
        'flex min-h-11 min-w-0 items-center justify-between gap-3 rounded-control px-3 py-2 text-left text-sm text-emerald-950 transition hover:bg-emerald-50 focus-visible:bg-emerald-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-emerald-300',
    );
    const label = element('span', 'min-w-0 break-words font-bold leading-5', String(item.label || ''));
    const meta = element('span', 'shrink-0 text-xs font-semibold text-emerald-800', String(item.meta || ''));

    link.append(label, meta);

    return link;
};

const initializeHeaderSearch = (root) => {
    if (initializedHeaderSearches.has(root)) {
        return;
    }

    const input = root.querySelector('[data-header-search-input]');
    const form = root.querySelector('[data-header-search-form]');
    const clearButton = root.querySelector('[data-header-search-clear]');
    const closeButton = root.querySelector('[data-header-search-close]');
    const dropdown = root.querySelector('[data-header-search-dropdown]');
    const titleSection = root.querySelector('[data-header-search-title-section]');
    const titleResults = root.querySelector('[data-header-search-title-results]');
    const portalSection = root.querySelector('[data-header-search-portal-section]');
    const portalResults = root.querySelector('[data-header-search-portal-results]');
    const status = root.querySelector('[data-header-search-status]');
    const spinner = root.querySelector('[data-header-search-spinner]');
    const allResults = root.querySelector('[data-header-search-all-results]');
    const endpoint = root.dataset.suggestionsEndpoint || '';
    const searchUrl = root.dataset.searchUrl || '';

    if (
        !(input instanceof HTMLInputElement)
        || !(form instanceof HTMLFormElement)
        || !(clearButton instanceof HTMLButtonElement)
        || !(closeButton instanceof HTMLButtonElement)
        || !(dropdown instanceof HTMLElement)
        || !(titleSection instanceof HTMLElement)
        || !(titleResults instanceof HTMLElement)
        || !(portalSection instanceof HTMLElement)
        || !(portalResults instanceof HTMLElement)
        || !(status instanceof HTMLElement)
        || !(spinner instanceof HTMLElement)
        || !(allResults instanceof HTMLAnchorElement)
        || endpoint === ''
        || searchUrl === ''
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
            const heading = element('span', 'block px-2 pb-1 text-[0.6875rem] font-black uppercase tracking-[0.12em] text-slate-500', groupLabels[group]);
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

    const render = (query, titles, portal, message = '') => {
        const limits = presentationLimits();
        const visibleTitles = titles.slice(0, limits.titles);
        const visiblePortal = portal.slice(0, limits.portal);

        titleResults.replaceChildren(...visibleTitles.map((item) => titleCard(item)));
        titleSection.classList.toggle('hidden', visibleTitles.length === 0);
        renderPortalGroups(visiblePortal);

        const hasSuggestions = visibleTitles.length > 0 || visiblePortal.length > 0;
        const allResultsUrl = new URL(searchUrl, window.location.origin);

        allResultsUrl.searchParams.set('q', query);
        allResults.href = allResultsUrl.toString();
        allResults.classList.toggle('hidden', !hasSuggestions);
        allResults.classList.toggle('flex', hasSuggestions);
        setStatus(hasSuggestions ? '' : message);
        prepareOptions();
        setExpanded(true);
        renderedQuery = normalizedQuery(query);
        renderedTitles = titles;
        renderedPortal = portal;
        renderedStatus = message;
    };

    const abortRequests = () => {
        requestControllers.forEach((controller) => controller.abort());
        requestControllers = [];
    };

    const lookup = () => {
        const query = input.value.trim();
        const normalized = normalizedQuery(query);

        abortRequests();

        if (normalized.length === 0) {
            titleResults.replaceChildren();
            portalResults.replaceChildren();
            renderedQuery = '';
            setLoading(false);
            setStatus('');
            setExpanded(false);

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

            render(query, titles, portal, message);
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
        window.clearTimeout(debounceTimer);
        debounceTimer = window.setTimeout(lookup, 160);
    });

    clearButton.addEventListener('click', () => {
        window.clearTimeout(debounceTimer);
        requestSequence += 1;
        abortRequests();
        input.value = '';
        titleResults.replaceChildren();
        portalResults.replaceChildren();
        renderedQuery = '';
        renderedTitles = [];
        renderedPortal = [];
        renderedStatus = '';
        setStatus('');
        setLoading(false);
        setClearAvailable(false);
        setExpanded(false);
        input.focus();
    });

    closeButton.addEventListener('click', () => {
        setExpanded(false);
        input.focus();
    });

    input.addEventListener('focus', () => {
        const normalized = normalizedQuery(input.value);

        if (normalized.length >= 1 && normalized === renderedQuery) {
            setExpanded(true);
        }
    });

    input.addEventListener('keydown', (event) => {
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
                if (!dropdown.classList.contains('hidden')) {
                    event.preventDefault();
                    event.stopPropagation();
                    setExpanded(false);
                }
                break;
            default:
                break;
        }
    });

    form.addEventListener('submit', () => {
        abortRequests();
        setExpanded(false);
    });

    window.addEventListener('resize', () => {
        if (renderedQuery !== '' && !dropdown.classList.contains('hidden')) {
            render(input.value, renderedTitles, renderedPortal, renderedStatus);
        }
    });

    document.addEventListener('pointerdown', (event) => {
        if (event.target instanceof Node && !root.contains(event.target)) {
            setExpanded(false);
        }
    });
};

export const initializeHeaderSearchInterfaces = (root = document) => {
    root.querySelectorAll?.('[data-header-search-autocomplete]').forEach(initializeHeaderSearch);
};
