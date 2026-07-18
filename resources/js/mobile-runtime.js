const initializedNavigations = new WeakSet();
const initializedShareButtons = new WeakSet();
const initializedResponsiveFilters = new WeakSet();
const initializedPasswordToggles = new WeakSet();
const filterDraftSnapshots = new WeakMap();

let runtimeReady = false;
let connectionWasOffline = false;
let connectionStatusTimer = null;
let viewportFrame = null;
let filterAwaitingApply = null;

const compactLayout = window.matchMedia('(max-width: 63.999rem)');

const safeShareUrl = (value) => {
    try {
        const url = new URL(value || window.location.href, window.location.href);

        return ['http:', 'https:'].includes(url.protocol) && url.origin === window.location.origin
            ? url.toString()
            : window.location.href;
    } catch {
        return window.location.href;
    }
};

const copyText = async (value) => {
    if (navigator.clipboard?.writeText) {
        await navigator.clipboard.writeText(value);

        return;
    }

    const field = document.createElement('textarea');
    field.value = value;
    field.readOnly = true;
    field.className = 'fixed -left-[9999px] top-0';
    document.body.append(field);
    field.select();
    const copied = document.execCommand('copy');
    field.remove();

    if (!copied) {
        throw new Error('Clipboard write failed');
    }
};

const initializeNavigation = (navigation) => {
    if (!(navigation instanceof HTMLDetailsElement) || initializedNavigations.has(navigation)) {
        return;
    }

    initializedNavigations.add(navigation);
    navigation.addEventListener('click', (event) => {
        const target = event.target instanceof Element ? event.target : null;

        if (target?.closest('a[href]')) {
            navigation.open = false;
        }
    });
    navigation.addEventListener('keydown', (event) => {
        if (event.key !== 'Escape' || !navigation.open) {
            return;
        }

        event.preventDefault();
        navigation.open = false;
        navigation.querySelector('summary')?.focus({ preventScroll: true });
    });
};

const initializeShareButton = (button) => {
    if (!(button instanceof HTMLButtonElement) || initializedShareButtons.has(button)) {
        return;
    }

    initializedShareButtons.add(button);
    button.addEventListener('click', async () => {
        const url = safeShareUrl(button.dataset.shareUrl);
        const title = button.dataset.shareTitle || document.title;
        const statusId = button.getAttribute('aria-describedby');
        const status = statusId ? document.getElementById(statusId) : null;

        button.disabled = true;

        try {
            if (navigator.share) {
                await navigator.share({ title, url });
            } else {
                await copyText(url);
            }

            if (status) {
                status.textContent = button.dataset.shareSuccess || '';
            }
        } catch (error) {
            if (error instanceof DOMException && error.name === 'AbortError') {
                return;
            }

            if (status) {
                status.textContent = button.dataset.shareError || '';
            }
        } finally {
            button.disabled = false;
        }
    });
};

const initializePasswordToggle = (button) => {
    if (!(button instanceof HTMLButtonElement) || initializedPasswordToggles.has(button)) {
        return;
    }

    const input = document.getElementById(button.getAttribute('aria-controls') || '');

    if (!(input instanceof HTMLInputElement) || input.type !== 'password') {
        return;
    }

    initializedPasswordToggles.add(button);
    button.addEventListener('click', () => {
        const visible = input.type === 'password';

        input.type = visible ? 'text' : 'password';
        button.setAttribute('aria-pressed', visible ? 'true' : 'false');
        button.setAttribute('aria-label', visible ? button.dataset.hideLabel || '' : button.dataset.showLabel || '');
        button.querySelector('[data-password-show-icon]')?.classList.toggle('hidden', visible);
        button.querySelector('[data-password-hide-icon]')?.classList.toggle('hidden', !visible);
        input.focus({ preventScroll: true });
    });
};

const resetFilterDraft = (details) => {
    const form = details.querySelector('form');
    const snapshot = filterDraftSnapshots.get(details);

    if (!(form instanceof HTMLFormElement) || !snapshot) {
        return;
    }

    form.querySelectorAll('input:not([type="hidden"]), select, textarea').forEach((field, index) => {
        const initial = snapshot[index];

        if (!initial) {
            return;
        }

        if (field instanceof HTMLInputElement && ['checkbox', 'radio'].includes(field.type)) {
            field.checked = initial.checked;
        } else {
            field.value = initial.value;
        }

        field.dispatchEvent(new Event(field instanceof HTMLInputElement && ['checkbox', 'radio'].includes(field.type) ? 'change' : 'input', {
            bubbles: true,
        }));
    });
};

const captureFilterDraft = (details) => {
    const fields = details.querySelectorAll('form input:not([type="hidden"]), form select, form textarea');

    filterDraftSnapshots.set(details, [...fields].map((field) => ({
        checked: field instanceof HTMLInputElement ? field.checked : false,
        value: field.value,
    })));
};

const initializeResponsiveFilter = (details) => {
    if (!(details instanceof HTMLDetailsElement)) {
        return;
    }

    if (!initializedResponsiveFilters.has(details)) {
        initializedResponsiveFilters.add(details);
        const form = details.querySelector('form');

        captureFilterDraft(details);

        form?.addEventListener('submit', () => {
            filterAwaitingApply = compactLayout.matches ? details.id : null;
        });
        details.querySelector('[data-catalog-filter-cancel]')?.addEventListener('click', () => {
            resetFilterDraft(details);
            details.open = false;
            details.querySelector('summary')?.focus({ preventScroll: true });
        });

        if (compactLayout.matches && Number(details.dataset.activeFilterCount || 0) === 0) {
            details.open = false;
        }
    }

    if (filterAwaitingApply === details.id && compactLayout.matches) {
        captureFilterDraft(details);
        details.open = false;
        filterAwaitingApply = null;
        details.querySelector('summary')?.focus({ preventScroll: true });
    }
};

const updateViewport = () => {
    viewportFrame = null;
    const visualHeight = Math.round(window.visualViewport?.height || window.innerHeight);
    const layoutHeight = Math.round(window.innerHeight);

    document.documentElement.style.setProperty('--app-visual-viewport-height', `${Math.max(1, visualHeight)}px`);
    document.documentElement.classList.toggle('app-keyboard-visible', layoutHeight - visualHeight > 150);
};

const scheduleViewportUpdate = () => {
    if (viewportFrame !== null) {
        return;
    }

    viewportFrame = window.requestAnimationFrame(updateViewport);
};

const showConnectionState = (state) => {
    const container = document.querySelector('[data-connection-status]');

    if (!(container instanceof HTMLElement)) {
        return;
    }

    const offline = container.querySelector('[data-connection-offline]');
    const restored = container.querySelector('[data-connection-restored]');

    window.clearTimeout(connectionStatusTimer);
    container.hidden = state === 'online';

    if (offline instanceof HTMLElement) {
        offline.hidden = state !== 'offline';
    }

    if (restored instanceof HTMLElement) {
        restored.hidden = state !== 'restored';
    }

    if (state === 'restored') {
        connectionStatusTimer = window.setTimeout(() => {
            container.hidden = true;
        }, 4_000);
    }
};

const handleOffline = () => {
    connectionWasOffline = true;
    showConnectionState('offline');
};

const handleOnline = () => {
    showConnectionState(connectionWasOffline ? 'restored' : 'online');
    connectionWasOffline = false;
};

const announceRoute = () => {
    const announcer = document.querySelector('[data-route-announcer]');
    const main = document.getElementById('main-content');

    if (announcer instanceof HTMLElement) {
        announcer.textContent = (announcer.dataset.routeAnnouncement || ':title').replace(':title', document.title);
    }

    if (main instanceof HTMLElement) {
        main.tabIndex = -1;
        main.focus({ preventScroll: true });
    }
};

const initializeRuntime = () => {
    if (runtimeReady) {
        return;
    }

    runtimeReady = true;
    window.addEventListener('online', handleOnline);
    window.addEventListener('offline', handleOffline);
    window.addEventListener('resize', scheduleViewportUpdate, { passive: true });
    window.visualViewport?.addEventListener('resize', scheduleViewportUpdate, { passive: true });
    window.visualViewport?.addEventListener('scroll', scheduleViewportUpdate, { passive: true });
    document.addEventListener('livewire:navigating', () => {
        document.querySelectorAll('[data-mobile-navigation][open]').forEach((navigation) => {
            navigation.open = false;
        });
    });
    document.addEventListener('livewire:navigated', announceRoute);
    window.addEventListener('pageshow', (event) => {
        if (event.persisted && document.body.dataset.privatePage === '1') {
            window.location.reload();

            return;
        }

        scheduleViewportUpdate();
    });

    scheduleViewportUpdate();
    showConnectionState(navigator.onLine === false ? 'offline' : 'online');
    connectionWasOffline = navigator.onLine === false;
};

export const initializeMobileRuntime = (root = document) => {
    initializeRuntime();
    root.querySelectorAll?.('[data-mobile-navigation]').forEach(initializeNavigation);
    root.querySelectorAll?.('[data-public-share]').forEach(initializeShareButton);
    root.querySelectorAll?.('[data-password-toggle]').forEach(initializePasswordToggle);
    root.querySelectorAll?.('[data-catalog-unified-filters]').forEach(initializeResponsiveFilter);
};
