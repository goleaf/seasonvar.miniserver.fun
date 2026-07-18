const initialized = new WeakSet();
const initializedEditors = new WeakSet();
let editorGuardsInitialized = false;

const initializeHelpSearch = (form) => {
    if (initialized.has(form)) {
        return;
    }

    const input = form.querySelector('[data-help-search-input]');
    const list = form.querySelector('[data-help-search-list]');
    const status = form.querySelector('[data-help-search-status]');

    if (!(input instanceof HTMLInputElement) || !(list instanceof HTMLElement) || !(status instanceof HTMLElement)) {
        return;
    }

    initialized.add(form);
    let timer = null;
    let controller = null;
    let sequence = 0;
    let options = [];
    let activeIndex = -1;

    const close = () => {
        list.classList.add('hidden');
        input.setAttribute('aria-expanded', 'false');
        input.removeAttribute('aria-activedescendant');
        activeIndex = -1;
    };

    const activate = (index) => {
        options.forEach((option) => option.setAttribute('aria-selected', 'false'));

        if (options.length === 0) {
            activeIndex = -1;
            input.removeAttribute('aria-activedescendant');
            return;
        }

        activeIndex = (index + options.length) % options.length;
        const option = options[activeIndex];
        option.setAttribute('aria-selected', 'true');
        input.setAttribute('aria-activedescendant', option.id);
        option.scrollIntoView({ block: 'nearest' });
    };

    const render = (items) => {
        list.replaceChildren();
        options = [];
        activeIndex = -1;

        items.forEach((item, index) => {
            if (!item || typeof item.url !== 'string' || typeof item.label !== 'string') {
                return;
            }

            let itemUrl;

            try {
                itemUrl = new URL(item.url, window.location.origin);
            } catch {
                return;
            }

            if (itemUrl.origin !== window.location.origin) {
                return;
            }

            const link = document.createElement('a');
            const label = document.createElement('span');
            const meta = document.createElement('span');

            link.id = `help-suggestion-${String(item.id || index).replace(/[^A-Za-z0-9_-]/g, '-')}`;
            link.href = itemUrl.toString();
            link.role = 'option';
            link.setAttribute('aria-selected', 'false');
            link.className = 'flex min-h-11 min-w-0 items-center justify-between gap-3 rounded-control px-3 py-2 text-sm hover:bg-emerald-50 focus:bg-emerald-50 focus:outline-none';
            label.className = 'min-w-0 break-words font-bold text-slate-800';
            label.textContent = item.label;
            meta.className = 'shrink-0 text-xs text-slate-500';
            meta.textContent = typeof item.meta === 'string' ? item.meta : '';
            link.append(label, meta);
            list.append(link);
            options.push(link);
        });

        if (options.length === 0) {
            const empty = document.createElement('p');
            empty.className = 'px-3 py-2 text-sm text-slate-600';
            empty.textContent = form.dataset.helpSearchEmpty || '';
            list.append(empty);
        }

        list.classList.remove('hidden');
        input.setAttribute('aria-expanded', 'true');
        status.textContent = `${form.dataset.helpSearchUpdated || ''}: ${options.length}`;
    };

    const search = async () => {
        const query = input.value.trim();
        controller?.abort();
        controller = null;

        if (query.length < 2) {
            close();
            status.textContent = '';
            return;
        }

        const current = ++sequence;
        const requestController = new AbortController();
        controller = requestController;
        status.textContent = form.dataset.helpSearching || '';

        try {
            const url = new URL(form.dataset.suggestionsUrl || '', window.location.origin);
            url.searchParams.set('q', query);
            url.searchParams.set('locale', form.dataset.helpLocale || document.documentElement.lang || 'ru');
            const response = await fetch(url, {
                headers: { Accept: 'application/json' },
                signal: requestController.signal,
            });

            if (!response.ok) {
                throw new Error('Help suggestions unavailable');
            }

            const payload = await response.json();

            if (current !== sequence || controller !== requestController) {
                return;
            }

            render(Array.isArray(payload.data) ? payload.data : []);
        } catch (error) {
            if (error instanceof DOMException && error.name === 'AbortError') {
                return;
            }

            if (current === sequence) {
                close();
                status.textContent = form.dataset.helpSearchFailed || '';
            }
        } finally {
            if (controller === requestController) {
                controller = null;
            }
        }
    };

    input.addEventListener('input', () => {
        window.clearTimeout(timer);
        controller?.abort();
        controller = null;
        sequence += 1;
        timer = window.setTimeout(() => void search(), 250);
    });
    input.addEventListener('keydown', (event) => {
        if (event.key === 'ArrowDown' && options.length > 0) {
            event.preventDefault();
            activate(activeIndex + 1);
        } else if (event.key === 'ArrowUp' && options.length > 0) {
            event.preventDefault();
            activate(activeIndex - 1);
        } else if (event.key === 'Enter' && activeIndex >= 0) {
            event.preventDefault();
            options[activeIndex].click();
        } else if (event.key === 'Escape') {
            event.preventDefault();
            close();
        }
    });
    input.addEventListener('blur', () => window.setTimeout(close, 150));
};

const initializeHelpEditor = (form) => {
    if (initializedEditors.has(form) || !(form instanceof HTMLFormElement)) {
        return;
    }

    initializedEditors.add(form);
    form.dataset.helpEditorDirty = 'false';
    form.addEventListener('input', () => {
        form.dataset.helpEditorDirty = 'true';
    });
    form.addEventListener('change', () => {
        form.dataset.helpEditorDirty = 'true';
    });
    form.addEventListener('submit', () => {
        form.dataset.helpEditorDirty = 'false';
    });
    const localeSwitch = form.querySelector('[data-help-locale-switch]');

    if (localeSwitch instanceof HTMLSelectElement) {
        localeSwitch.addEventListener('change', (event) => {
            if (form.dataset.helpEditorDirty !== 'true') {
                return;
            }

            if (!window.confirm(form.dataset.helpEditorWarning || '')) {
                event.preventDefault();
                event.stopImmediatePropagation();
                localeSwitch.value = localeSwitch.dataset.helpCurrentLocale || '';

                return;
            }

            form.dataset.helpEditorDirty = 'false';
        }, true);
    }

    if (!editorGuardsInitialized) {
        editorGuardsInitialized = true;
        window.addEventListener('beforeunload', (event) => {
            if (document.querySelector('[data-help-editor][data-help-editor-dirty="true"]')) {
                event.preventDefault();
                event.returnValue = '';
            }
        });
        document.addEventListener('livewire:navigating', (event) => {
            const dirtyForm = document.querySelector('[data-help-editor][data-help-editor-dirty="true"]');

            if (dirtyForm instanceof HTMLFormElement
                && !window.confirm(dirtyForm.dataset.helpEditorWarning || '')) {
                event.preventDefault();
            }
        });
    }
};

export const initializeHelpCenterInterfaces = (root = document) => {
    root.querySelectorAll?.('[data-help-search]').forEach(initializeHelpSearch);
    root.querySelectorAll?.('[data-help-editor]').forEach(initializeHelpEditor);
};
