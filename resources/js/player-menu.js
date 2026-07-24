const element = (tagName, {
    classes = [],
    text = '',
    attributes = {},
} = {}) => {
    const node = document.createElement(tagName);

    classes.filter(Boolean).forEach((className) => node.classList.add(className));

    if (text !== '') {
        node.textContent = text;
    }

    Object.entries(attributes).forEach(([name, value]) => {
        if (value !== null && value !== undefined) {
            node.setAttribute(name, String(value));
        }
    });

    return node;
};

const positiveInteger = (value) => {
    const parsed = Number.parseInt(String(value), 10);

    return Number.isInteger(parsed) && parsed > 0 ? parsed : null;
};

const SEASONS_PER_PAGE = 12;

const focusableWithin = (root) => [...root.querySelectorAll([
    'button:not([disabled])',
    '[href]',
    '[tabindex]:not([tabindex="-1"])',
].join(','))].filter((candidate) => (
    candidate instanceof HTMLElement
    && !candidate.hidden
    && candidate.closest('[hidden]') === null
    && candidate.getAttribute('aria-hidden') !== 'true'
    && candidate.tabIndex >= 0
));

export class CatalogPlayerMenu {
    constructor({
        shell,
        controlsRoot,
        copy,
        bootstrap,
        signal,
        loadEpisodePage,
        selectEpisode,
        selectTranslation,
    }) {
        this.shell = shell;
        this.controlsRoot = controlsRoot;
        this.copy = copy;
        this.bootstrap = bootstrap;
        this.signal = signal;
        this.loadEpisodePage = loadEpisodePage;
        this.selectEpisode = selectEpisode;
        this.selectTranslation = selectTranslation;
        this.seasons = Array.isArray(bootstrap.seasons) ? bootstrap.seasons : [];
        this.current = { ...bootstrap.current };
        this.translations = Array.isArray(bootstrap.translations) ? bootstrap.translations : [];
        this.episodes = [];
        this.pagination = null;
        const currentSeasonIndex = this.seasons.findIndex(
            (season) => Number(season.id) === Number(this.current.seasonId),
        );
        this.seasonPage = Math.max(1, Math.floor(Math.max(0, currentSeasonIndex) / SEASONS_PER_PAGE) + 1);
        this.activeLevel = 'seasons';
        this.lastOpener = null;
        this.destroyed = false;
        this.loading = false;
        this.dialogId = `catalog-player-menu-${crypto.randomUUID?.() || Date.now()}`;
        this.build();
        this.render();
    }

    build() {
        this.opener = element('button', {
            classes: ['plyr__controls__item', 'plyr__control', 'catalog-player-menu__opener'],
            attributes: {
                type: 'button',
                'aria-label': this.copy.open,
                'aria-haspopup': 'dialog',
                'aria-controls': this.dialogId,
            },
        });
        this.opener.append(element('span', {
            classes: ['fa-solid', 'fa-list'],
            attributes: { 'aria-hidden': 'true' },
        }));

        this.dialog = element('dialog', {
            classes: ['catalog-player-menu'],
            attributes: {
                id: this.dialogId,
                'aria-labelledby': `${this.dialogId}-title`,
            },
        });
        this.panel = element('div', { classes: ['catalog-player-menu__panel'] });
        const header = element('header', { classes: ['catalog-player-menu__header'] });
        this.backButton = element('button', {
            classes: ['catalog-player-menu__back'],
            text: this.copy.back,
            attributes: { type: 'button', hidden: 'hidden' },
        });
        this.title = element('h2', {
            classes: ['catalog-player-menu__title'],
            text: this.copy.title,
            attributes: { id: `${this.dialogId}-title` },
        });
        this.closeButton = element('button', {
            classes: ['catalog-player-menu__close'],
            attributes: {
                type: 'button',
                'aria-label': this.copy.close,
            },
        });
        this.closeButton.append(element('span', {
            classes: ['fa-solid', 'fa-xmark'],
            attributes: { 'aria-hidden': 'true' },
        }));
        header.append(this.backButton, this.title, this.closeButton);

        this.columns = element('div', { classes: ['catalog-player-menu__columns'] });
        this.seasonsSection = this.buildSection('seasons', this.copy.seasons);
        this.episodesSection = this.buildSection('episodes', this.copy.episodes);
        this.translationsSection = this.buildSection('translations', this.copy.translations);
        this.columns.append(
            this.seasonsSection.section,
            this.episodesSection.section,
            this.translationsSection.section,
        );

        this.seasonPaginationRoot = this.buildPagination(this.copy.seasons);
        this.seasonsSection.section.append(this.seasonPaginationRoot.root);
        this.paginationRoot = element('nav', {
            classes: ['catalog-player-menu__pagination'],
            attributes: { 'aria-label': this.copy.episodes, hidden: 'hidden' },
        });
        this.previousPageButton = element('button', {
            attributes: { type: 'button', 'aria-label': this.copy.previousPage },
        });
        this.previousPageButton.append(element('span', {
            classes: ['fa-solid', 'fa-chevron-left'],
            attributes: { 'aria-hidden': 'true' },
        }));
        this.pageStatus = element('span', {
            classes: ['catalog-player-menu__page'],
            attributes: { 'aria-live': 'polite' },
        });
        this.nextPageButton = element('button', {
            attributes: { type: 'button', 'aria-label': this.copy.nextPage },
        });
        this.nextPageButton.append(element('span', {
            classes: ['fa-solid', 'fa-chevron-right'],
            attributes: { 'aria-hidden': 'true' },
        }));
        this.paginationRoot.append(this.previousPageButton, this.pageStatus, this.nextPageButton);

        this.status = element('p', {
            classes: ['catalog-player-menu__status'],
            attributes: { role: 'status', 'aria-live': 'polite', hidden: 'hidden' },
        });
        this.panel.append(header, this.columns, this.paginationRoot, this.status);
        this.dialog.append(this.panel);
        this.controlsRoot.append(this.opener);
        this.shell.append(this.dialog);

        this.opener.addEventListener('click', () => this.toggle(), { signal: this.signal });
        this.closeButton.addEventListener('click', () => this.close(), { signal: this.signal });
        this.backButton.addEventListener('click', () => this.showLevel('seasons'), { signal: this.signal });
        this.previousPageButton.addEventListener('click', () => this.changePage(-1), { signal: this.signal });
        this.nextPageButton.addEventListener('click', () => this.changePage(1), { signal: this.signal });
        this.seasonPaginationRoot.previous.addEventListener(
            'click',
            () => this.changeSeasonPage(-1),
            { signal: this.signal },
        );
        this.seasonPaginationRoot.next.addEventListener(
            'click',
            () => this.changeSeasonPage(1),
            { signal: this.signal },
        );
        this.dialog.addEventListener('cancel', (event) => {
            event.preventDefault();
            this.close();
        }, { signal: this.signal });
        this.dialog.addEventListener('keydown', (event) => this.handleKeydown(event), { signal: this.signal });
    }

    buildPagination(label) {
        const root = element('nav', {
            classes: ['catalog-player-menu__pagination'],
            attributes: { 'aria-label': label, hidden: 'hidden' },
        });
        const previous = element('button', {
            attributes: { type: 'button', 'aria-label': this.copy.previousPage },
        });
        const status = element('span', {
            classes: ['catalog-player-menu__page'],
            attributes: { 'aria-live': 'polite' },
        });
        const next = element('button', {
            attributes: { type: 'button', 'aria-label': this.copy.nextPage },
        });

        previous.append(element('span', {
            classes: ['fa-solid', 'fa-chevron-left'],
            attributes: { 'aria-hidden': 'true' },
        }));
        next.append(element('span', {
            classes: ['fa-solid', 'fa-chevron-right'],
            attributes: { 'aria-hidden': 'true' },
        }));
        root.append(previous, status, next);

        return { root, previous, status, next };
    }

    buildSection(key, label) {
        const section = element('section', {
            classes: ['catalog-player-menu__section'],
            attributes: {
                'data-player-menu-section': key,
                'aria-labelledby': `${this.dialogId}-${key}`,
            },
        });
        const heading = element('h3', {
            text: label,
            attributes: { id: `${this.dialogId}-${key}` },
        });
        const list = element('div', {
            classes: ['catalog-player-menu__list'],
            attributes: {
                role: 'listbox',
                tabindex: '-1',
                'aria-labelledby': `${this.dialogId}-${key}`,
            },
        });

        section.append(heading, list);

        return { section, list };
    }

    render() {
        this.renderSeasons();
        this.renderEpisodes();
        this.renderTranslations();
        this.renderSeasonPagination();
        this.renderPagination();
        this.updateMobileLevel();
    }

    renderSeasons() {
        const start = (this.seasonPage - 1) * SEASONS_PER_PAGE;

        this.renderOptions(
            this.seasonsSection.list,
            this.seasons.slice(start, start + SEASONS_PER_PAGE),
            (season) => ({
                id: season.id,
                label: season.label,
                detail: String(Math.max(0, Number(season.episodeCount) || 0)),
                current: Number(season.id) === Number(this.current.seasonId),
                activate: () => this.chooseSeason(season),
            }),
        );
    }

    renderEpisodes() {
        this.renderOptions(
            this.episodesSection.list,
            this.episodes,
            (episode) => ({
                id: episode.id,
                label: episode.label,
                detail: episode.title || '',
                current: Number(episode.id) === Number(this.current.episodeId),
                activate: () => {
                    void this.selectEpisode(positiveInteger(episode.id));
                },
            }),
            this.copy.seasonEmpty,
        );
    }

    renderTranslations() {
        this.renderOptions(
            this.translationsSection.list,
            this.translations,
            (translation) => ({
                id: translation.mediaId,
                label: translation.label,
                detail: translation.detail || '',
                current: Number(translation.mediaId) === Number(this.current.mediaId),
                activate: () => {
                    void this.selectTranslation(
                        positiveInteger(this.current.episodeId),
                        positiveInteger(translation.mediaId),
                    );
                },
            }),
        );
    }

    renderOptions(list, options, normalize, emptyText = '') {
        list.replaceChildren();

        if (!Array.isArray(options) || options.length === 0) {
            if (emptyText) {
                list.append(element('p', {
                    classes: ['catalog-player-menu__empty'],
                    text: emptyText,
                }));
            }

            return;
        }

        options.forEach((option, index) => {
            const normalized = normalize(option);
            const button = element('button', {
                classes: ['catalog-player-menu__option'],
                attributes: {
                    type: 'button',
                    role: 'option',
                    tabindex: index === 0 ? '0' : '-1',
                    'data-player-menu-option': String(normalized.id),
                    ...(normalized.current ? {
                        'aria-current': 'true',
                        'aria-selected': 'true',
                    } : {
                        'aria-selected': 'false',
                    }),
                },
            });
            button.append(element('span', {
                classes: ['catalog-player-menu__option-label'],
                text: normalized.label,
            }));

            if (normalized.detail) {
                button.append(element('span', {
                    classes: ['catalog-player-menu__option-detail'],
                    text: normalized.detail,
                }));
            }

            if (!normalized.current) {
                button.addEventListener('click', normalized.activate, { signal: this.signal });
            }

            list.append(button);
        });
    }

    changeSeasonPage(direction) {
        const lastPage = Math.max(1, Math.ceil(this.seasons.length / SEASONS_PER_PAGE));
        const nextPage = this.seasonPage + direction;

        if (nextPage < 1 || nextPage > lastPage) {
            return;
        }

        this.seasonPage = nextPage;
        this.renderSeasons();
        this.renderSeasonPagination();
        this.seasonsSection.list.querySelector('button')?.focus();
    }

    renderSeasonPagination() {
        const lastPage = Math.max(1, Math.ceil(this.seasons.length / SEASONS_PER_PAGE));
        const visible = lastPage > 1;

        this.seasonPaginationRoot.root.hidden = !visible;

        if (!visible) {
            return;
        }

        this.seasonPaginationRoot.previous.disabled = this.seasonPage <= 1;
        this.seasonPaginationRoot.next.disabled = this.seasonPage >= lastPage;
        this.seasonPaginationRoot.status.textContent = this.copy.page
            .replace(':page', String(this.seasonPage))
            .replace(':lastPage', String(lastPage));
    }

    async chooseSeason(season) {
        const seasonId = positiveInteger(season.id);

        if (seasonId === null || this.loading) {
            return;
        }

        this.current.seasonId = seasonId;
        this.setLoading(true);
        this.showLevel('episodes');

        try {
            const payload = await this.loadEpisodePage(seasonId, 1);

            if (payload?.status !== 'ready') {
                this.setError(payload?.message || this.copy.seasonEmpty);

                return;
            }

            this.applyEpisodePage(payload);
        } catch {
            this.setError(this.copy.retry);
        } finally {
            this.setLoading(false);
        }
    }

    applyEpisodePage(payload) {
        this.episodes = Array.isArray(payload?.episodes) ? payload.episodes : [];
        this.pagination = payload?.pagination && typeof payload.pagination === 'object'
            ? payload.pagination
            : null;
        this.current.seasonId = positiveInteger(payload?.season?.id) ?? this.current.seasonId;
        this.render();
    }

    async changePage(direction) {
        const page = Number(this.pagination?.page) + direction;
        const seasonId = positiveInteger(this.current.seasonId);

        if (
            seasonId === null
            || !Number.isInteger(page)
            || page < 1
            || page > Number(this.pagination?.lastPage || 1)
            || this.loading
        ) {
            return;
        }

        this.setLoading(true);

        try {
            const payload = await this.loadEpisodePage(seasonId, page);

            if (payload?.status === 'ready') {
                this.applyEpisodePage(payload);
            } else {
                this.setError(payload?.message || this.copy.retry);
            }
        } catch {
            this.setError(this.copy.retry);
        } finally {
            this.setLoading(false);
        }
    }

    renderPagination() {
        const page = Number(this.pagination?.page);
        const lastPage = Number(this.pagination?.lastPage);
        const visible = Number.isInteger(page) && Number.isInteger(lastPage) && lastPage > 1;

        this.paginationRoot.hidden = !visible;

        if (!visible) {
            return;
        }

        this.previousPageButton.disabled = !this.pagination?.previousPage || this.loading;
        this.nextPageButton.disabled = !this.pagination?.nextPage || this.loading;
        this.pageStatus.textContent = this.copy.page
            .replace(':page', String(page))
            .replace(':lastPage', String(lastPage));
    }

    showLevel(level) {
        this.activeLevel = ['seasons', 'episodes', 'translations'].includes(level) ? level : 'seasons';
        this.updateMobileLevel();
        this.activeSection()?.querySelector('button:not([disabled])')?.focus();
    }

    updateMobileLevel() {
        this.dialog.dataset.playerMenuLevel = this.activeLevel;
        this.backButton.hidden = this.activeLevel === 'seasons';
    }

    activeSection() {
        return {
            seasons: this.seasonsSection.section,
            episodes: this.episodesSection.section,
            translations: this.translationsSection.section,
        }[this.activeLevel];
    }

    open() {
        if (this.destroyed || this.isOpen()) {
            return;
        }

        this.lastOpener = document.activeElement instanceof HTMLElement
            ? document.activeElement
            : this.opener;
        const fullscreenRoot = document.fullscreenElement;

        if (fullscreenRoot instanceof Element && this.shell.contains(fullscreenRoot)) {
            fullscreenRoot.append(this.dialog);
        } else if (this.dialog.parentElement !== this.shell) {
            this.shell.append(this.dialog);
        }

        if (typeof this.dialog.showModal === 'function') {
            this.dialog.showModal();
        } else {
            this.dialog.setAttribute('open', '');
        }

        this.opener.setAttribute('aria-expanded', 'true');
        this.showLevel('seasons');
        this.closeButton.focus();

        if (positiveInteger(this.current.seasonId) !== null && this.episodes.length === 0) {
            void this.chooseSeason(
                this.seasons.find((season) => Number(season.id) === Number(this.current.seasonId))
                || { id: this.current.seasonId },
            );
        }
    }

    close({ restoreFocus = true } = {}) {
        if (!this.isOpen()) {
            return;
        }

        if (typeof this.dialog.close === 'function') {
            this.dialog.close();
        } else {
            this.dialog.removeAttribute('open');
        }

        this.opener.setAttribute('aria-expanded', 'false');

        if (this.shell.isConnected && this.dialog.isConnected && this.dialog.parentElement !== this.shell) {
            this.shell.append(this.dialog);
        }

        if (restoreFocus) {
            const target = this.lastOpener?.isConnected ? this.lastOpener : this.opener;
            target?.focus();
        }
    }

    toggle() {
        if (this.isOpen()) {
            this.close();
        } else {
            this.open();
        }
    }

    isOpen() {
        return this.dialog.hasAttribute('open');
    }

    updateCurrent(transition) {
        if (!transition || transition.status !== 'ready') {
            return;
        }

        this.current = {
            seasonId: positiveInteger(transition.selection?.seasonId),
            episodeId: positiveInteger(transition.selection?.episodeId),
            mediaId: positiveInteger(transition.selection?.mediaId),
        };
        const currentSeasonIndex = this.seasons.findIndex(
            (season) => Number(season.id) === Number(this.current.seasonId),
        );

        if (currentSeasonIndex >= 0) {
            this.seasonPage = Math.floor(currentSeasonIndex / SEASONS_PER_PAGE) + 1;
        }

        this.translations = Array.isArray(transition.translations) ? transition.translations : [];
        this.render();
    }

    setLoading(isLoading) {
        this.loading = Boolean(isLoading);
        this.dialog.toggleAttribute('data-loading', this.loading);
        this.status.hidden = !this.loading;
        this.status.textContent = this.loading ? this.copy.loading : '';
        this.renderPagination();
    }

    setError(message) {
        this.status.hidden = false;
        this.status.setAttribute('role', 'alert');
        this.status.textContent = typeof message === 'string' && message !== ''
            ? message
            : this.copy.retry;
    }

    handleKeydown(event) {
        event.stopPropagation();

        if (event.key === 'Escape') {
            event.preventDefault();
            this.close();

            return;
        }

        const focusable = focusableWithin(this.dialog);

        if (event.key === 'Tab' && focusable.length > 0) {
            const first = focusable[0];
            const last = focusable[focusable.length - 1];

            if (event.shiftKey && document.activeElement === first) {
                event.preventDefault();
                last.focus();
            } else if (!event.shiftKey && document.activeElement === last) {
                event.preventDefault();
                first.focus();
            }

            return;
        }

        if (!['ArrowUp', 'ArrowDown', 'ArrowLeft', 'ArrowRight'].includes(event.key)) {
            return;
        }

        const active = document.activeElement;
        const list = active?.closest?.('[role="listbox"]');

        if (!(active instanceof HTMLButtonElement) || !(list instanceof HTMLElement)) {
            return;
        }

        event.preventDefault();
        const options = [...list.querySelectorAll('button:not([disabled])')];

        if (event.key === 'ArrowUp' || event.key === 'ArrowDown') {
            const index = options.indexOf(active);
            const direction = event.key === 'ArrowDown' ? 1 : -1;
            const next = options[(index + direction + options.length) % options.length];

            options.forEach((option) => option.tabIndex = option === next ? 0 : -1);
            next?.focus();

            return;
        }

        const sections = [
            this.seasonsSection.section,
            this.episodesSection.section,
            this.translationsSection.section,
        ].filter((section) => !section.hidden);
        const sectionIndex = sections.indexOf(active.closest('section'));
        const direction = event.key === 'ArrowRight' ? 1 : -1;
        const nextSection = sections[(sectionIndex + direction + sections.length) % sections.length];

        nextSection?.querySelector('button:not([disabled])')?.focus();
    }

    destroy() {
        if (this.destroyed) {
            return;
        }

        this.close({ restoreFocus: false });
        this.destroyed = true;
        this.dialog.remove();
        this.opener.remove();
    }
}
