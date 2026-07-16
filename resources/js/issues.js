const browserSummary = () => {
    const agent = navigator.userAgent;
    const families = [
        ['edge', /Edg\/(\d+)/],
        ['opera', /OPR\/(\d+)/],
        ['samsung', /SamsungBrowser\/(\d+)/],
        ['firefox', /Firefox\/(\d+)/],
        ['chromium', /(?:Chrome|CriOS)\/(\d+)/],
        ['safari', /Version\/(\d+).+Safari/],
    ];

    for (const [family, pattern] of families) {
        const match = agent.match(pattern);

        if (match) {
            return { family, major: Number.parseInt(match[1], 10) || null };
        }
    }

    return { family: 'unknown', major: null };
};

const operatingSystem = () => {
    const agent = navigator.userAgent;

    if (/Android/i.test(agent)) return 'android';
    if (/iPhone|iPad|iPod/i.test(agent)) return 'ios';
    if (/Windows/i.test(agent)) return 'windows';
    if (/CrOS/i.test(agent)) return 'chromeos';
    if (/Macintosh|Mac OS X/i.test(agent)) return 'macos';
    if (/Linux/i.test(agent)) return 'linux';

    return 'unknown';
};

const deviceCategory = () => {
    const width = Math.max(document.documentElement.clientWidth || 0, window.innerWidth || 0);
    const agent = navigator.userAgent;

    if (/SmartTV|SMART-TV|HbbTV|NetCast|Tizen/i.test(agent)) return 'television';
    if (/iPad|Tablet/i.test(agent) || (/Android/i.test(agent) && !/Mobile/i.test(agent))) return 'tablet';
    if (/Mobile|iPhone|iPod|Android/i.test(agent) || width < 640) return 'mobile';

    return 'desktop';
};

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

const initialize = () => {
    initializeTechnicalIssueForms();
    initializePlayerIssueLinks();
};

document.addEventListener('DOMContentLoaded', initialize);
document.addEventListener('livewire:navigated', initialize);

export { initializeTechnicalIssueForms, initializePlayerIssueLinks };
