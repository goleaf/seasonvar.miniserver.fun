import { browserSummary, deviceCategory, operatingSystem } from './client-diagnostics.js';

const setInput = (container, key, value) => {
    const input = container.querySelector(`[data-diagnostic="${key}"]`);

    if (!input) return;
    input.value = value === null ? '' : String(value);
    input.dispatchEvent(new Event('input', { bubbles: true }));
    input.dispatchEvent(new Event('change', { bubbles: true }));
};

const collectDiagnostics = (form) => {
    const consent = form.querySelector('[data-technical-issue-consent]');
    const container = form.querySelector('[data-technical-issue-diagnostics]');

    if (!consent?.checked || !container) return;

    const browser = browserSummary();
    const os = operatingSystem();
    const device = deviceCategory();
    const width = Math.max(1, Math.round(window.innerWidth));
    const height = Math.max(1, Math.round(window.innerHeight));
    let timezone = '';

    try {
        timezone = Intl.DateTimeFormat().resolvedOptions().timeZone || '';
    } catch {
        timezone = '';
    }

    setInput(container, 'browserFamily', browser.family);
    setInput(container, 'browserMajor', browser.major);
    setInput(container, 'operatingSystem', os);
    setInput(container, 'deviceCategory', device);
    setInput(container, 'viewportWidth', width);
    setInput(container, 'viewportHeight', height);
    setInput(container, 'timezone', timezone);
    setInput(container, 'networkOnline', navigator.onLine ? 1 : 0);
    container.querySelector('[data-diagnostic-label="browser"]')?.replaceChildren(`${browser.family}${browser.major ? ` ${browser.major}` : ''}`);
    container.querySelector('[data-diagnostic-label="os"]')?.replaceChildren(os);
    container.querySelector('[data-diagnostic-label="device"]')?.replaceChildren(device);
    container.querySelector('[data-diagnostic-label="viewport"]')?.replaceChildren(`${width} × ${height}`);
};

const initializeTechnicalIssueForms = (root = document) => {
    root.querySelectorAll?.('[data-technical-issue-form]').forEach((form) => {
        if (form.dataset.technicalIssueReady === 'true') return;
        form.dataset.technicalIssueReady = 'true';
        const consent = form.querySelector('[data-technical-issue-consent]');
        consent?.addEventListener('change', () => collectDiagnostics(form));
        form.addEventListener('submit', () => collectDiagnostics(form));

        if (consent?.checked) collectDiagnostics(form);
    });
};

const initializePlayerIssueLinks = (root = document) => {
    root.querySelectorAll?.('[data-player-issue-link]').forEach((link) => {
        if (link.dataset.playerIssueReady === 'true') return;
        link.dataset.playerIssueReady = 'true';
        link.addEventListener('click', () => {
            const playerRoot = link.closest('[data-active-player-session]') || document;
            const shell = playerRoot.querySelector('[data-player-shell]');
            const position = Number.parseInt(shell?.dataset.playerPosition || '', 10);

            if (!Number.isFinite(position) || position < 0) return;

            const url = new URL(link.href, window.location.href);
            url.searchParams.set('position', String(position));
            link.href = url.toString();
        });
    });
};

export const initializeTechnicalIssueInterfaces = (root = document) => {
    initializeTechnicalIssueForms(root);
    initializePlayerIssueLinks(root);
};

export { initializeTechnicalIssueForms, initializePlayerIssueLinks };
