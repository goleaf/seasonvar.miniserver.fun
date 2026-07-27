import { expect, test } from '@playwright/test';
import { installPlayerMediaFixtures } from './support/player-media-fixtures.js';

test.afterEach(async ({ page }) => {
    await page.evaluate(() => {
        window.dispatchEvent(new PageTransitionEvent('pagehide', { persisted: false }));
    }).catch(() => {});
    await page.unrouteAll({ behavior: 'ignoreErrors' });
});

test('player workspace keeps theatre mode scoped and keyboard reversible', async ({ page }, testInfo) => {
    await installPlayerMediaFixtures(page);
    await page.goto('/titles/browser-smoke?episode=1&format=mp4#player');

    const video = page.locator('video.js-catalog-player');
    const theatre = page.locator('[data-player-theatre-toggle]');
    const theatreIcon = theatre.locator('[data-player-theatre-icon]');
    const region = page.locator('[data-player-workspace-region]');
    const seasonsPanel = page.locator('[data-player-seasons-panel]');
    const breadcrumbs = page.locator('[data-layout-breadcrumbs]');
    const stageHeading = page.locator('[data-player-stage-panel] > div:first-child');
    const contextBar = page.locator('[data-player-context-bar]');
    const contextSummary = page.locator('[data-player-context-summary]');
    const contextActions = page.locator('[data-player-context-actions]');

    await expect(video).toHaveAttribute('data-player-ready', '1');
    await expect(breadcrumbs).toBeVisible();
    await expect(stageHeading).toBeVisible();
    await expect(contextBar).toBeVisible();
    await expect(contextSummary).toBeVisible();
    await expect(contextActions).toBeVisible();
    const returnPosition = await page.evaluate(() => {
        window.__playerTheatreVideo = document.querySelector('video.js-catalog-player');

        return { x: window.scrollX, y: window.scrollY };
    });
    await theatre.click();
    await expect(page.locator('body')).toHaveClass(/player-theatre-active/);
    await expect(theatre).toHaveAttribute('aria-pressed', 'true');
    await expect(theatre).toHaveAttribute('aria-label', /Свернуть театр|Collapse theatre/);
    await expect(theatreIcon).toHaveClass(/fa-compress/);
    await expect(theatre).toBeInViewport();
    await expect(video).toBeInViewport();
    await expect(page.locator('[data-title-detail-sidebar]')).toBeHidden();
    await expect(page.locator('[data-site-header]')).toBeHidden();
    await expect(page.locator('[data-mobile-bottom-navigation]')).toBeHidden();
    await expect(page.locator('[data-site-footer]')).toBeHidden();
    await expect(breadcrumbs).toBeHidden();
    await expect(stageHeading).toBeHidden();
    await expect(contextSummary).toBeHidden();
    await expect(contextActions).toBeVisible();

    const theatreGeometry = await page.evaluate(() => {
        const regionElement = document.querySelector('[data-player-workspace-region]');
        const shellElement = document.querySelector('[data-player-shell]');
        const toggleElement = document.querySelector('[data-player-theatre-toggle]');
        const contextElement = document.querySelector('[data-player-context-actions]');

        if (
            !(regionElement instanceof HTMLElement)
            || !(shellElement instanceof HTMLElement)
            || !(toggleElement instanceof HTMLElement)
            || !(contextElement instanceof HTMLElement)
        ) {
            throw new Error('Theatre geometry elements are unavailable.');
        }

        const shell = shellElement.getBoundingClientRect();
        const toggle = toggleElement.getBoundingClientRect();
        const context = contextElement.getBoundingClientRect();

        return {
            regionWidth: regionElement.getBoundingClientRect().width,
            viewportWidth: window.innerWidth,
            overflow: document.documentElement.scrollWidth - window.innerWidth,
            shellTop: shell.top,
            shellRight: shell.right,
            shellBottom: shell.bottom,
            toggleTop: toggle.top,
            toggleRight: toggle.right,
            toggleBottom: toggle.bottom,
            toggleWidth: toggle.width,
            toggleHeight: toggle.height,
            contextTop: context.top,
        };
    });

    expect(Math.abs(theatreGeometry.regionWidth - theatreGeometry.viewportWidth)).toBeLessThanOrEqual(2);
    expect(theatreGeometry.overflow).toBeLessThanOrEqual(1);
    expect(theatreGeometry.shellTop).toBeGreaterThanOrEqual(-1);
    expect(theatreGeometry.shellTop).toBeLessThanOrEqual(20);
    expect(theatreGeometry.toggleTop).toBeGreaterThanOrEqual(theatreGeometry.shellTop);
    expect(theatreGeometry.toggleBottom).toBeLessThanOrEqual(theatreGeometry.shellBottom);
    expect(theatreGeometry.shellRight - theatreGeometry.toggleRight).toBeGreaterThanOrEqual(-1);
    expect(theatreGeometry.shellRight - theatreGeometry.toggleRight).toBeLessThanOrEqual(20);
    expect(theatreGeometry.toggleWidth).toBeGreaterThanOrEqual(44);
    expect(theatreGeometry.toggleHeight).toBeGreaterThanOrEqual(44);
    if (
        theatreGeometry.viewportWidth < 768
        || (page.viewportSize()?.height || 0) <= 600
    ) {
        expect(theatreGeometry.toggleWidth).toBeLessThanOrEqual(52);
    }
    expect(theatreGeometry.contextTop).toBeGreaterThanOrEqual(theatreGeometry.shellBottom - 1);
    expect(await seasonsPanel.evaluate(
        (element) => getComputedStyle(element).backgroundColor,
    )).not.toBe('rgb(255, 255, 255)');
    await page.screenshot({
        path: `output/playwright/task-114-theatre-${testInfo.project.name.toLowerCase().replaceAll(' ', '-')}.png`,
    });

    await page.evaluate(async () => {
        const root = document.querySelector('[data-active-player-session]');
        const component = root?.closest('[wire\\:id]');
        const componentId = component?.getAttribute('wire:id');

        if (!componentId) {
            throw new Error('Player Livewire component is unavailable.');
        }

        await window.Livewire.find(componentId).$refresh();
    });
    await expect(theatre).toHaveAttribute('aria-pressed', 'true');
    await expect(theatre.locator('[data-player-theatre-label]')).toContainText(/Свернуть театр|Collapse theatre/);
    expect(await page.evaluate(() => (
        window.__playerTheatreVideo === document.querySelector('video.js-catalog-player')
    ))).toBe(true);

    await theatre.click();
    await expect(page.locator('body')).not.toHaveClass(/player-theatre-active/);
    await expect(theatre).toHaveAttribute('aria-pressed', 'false');
    await expect(theatreIcon).toHaveClass(/fa-expand/);
    await expect.poll(async () => page.evaluate(
        ({ x, y }) => Math.max(Math.abs(window.scrollX - x), Math.abs(window.scrollY - y)),
        returnPosition,
    )).toBeLessThanOrEqual(2);
    await expect(page.locator('[data-site-header]')).toBeVisible();
    expect(await page.evaluate(() => (
        window.__playerTheatreVideo === document.querySelector('video.js-catalog-player')
    ))).toBe(true);

    await theatre.click();
    await expect(page.locator('body')).toHaveClass(/player-theatre-active/);
    await expect(theatre).toBeInViewport();
    await expect(video).toBeInViewport();

    await page.locator('[data-player-shortcuts-open]').click();
    await expect(page.locator('[data-player-shortcuts-dialog]')).toBeVisible();
    await page.keyboard.press('Escape');
    await expect(page.locator('[data-player-shortcuts-dialog]')).toBeHidden();
    await expect(page.locator('body')).toHaveClass(/player-theatre-active/);

    await page.keyboard.press('Escape');
    await expect(page.locator('body')).not.toHaveClass(/player-theatre-active/);
    await expect(theatre).toHaveAttribute('aria-pressed', 'false');
    await expect(theatre).toBeFocused();
    await expect.poll(async () => page.evaluate(
        ({ x, y }) => Math.max(Math.abs(window.scrollX - x), Math.abs(window.scrollY - y)),
        returnPosition,
    )).toBeLessThanOrEqual(2);
    expect(await page.evaluate(() => (
        window.__playerTheatreVideo === document.querySelector('video.js-catalog-player')
    ))).toBe(true);
});

test('player recovery source action opens the existing translations menu', async ({ page }, testInfo) => {
    await installPlayerMediaFixtures(page);
    await page.goto('/titles/browser-smoke?episode=1&format=mp4#player');

    await expect(page.locator('video.js-catalog-player')).toHaveAttribute('data-player-ready', '1');

    const recovery = page.locator('[data-player-recovery]');

    await recovery.evaluate((element) => {
        element.hidden = false;
    });
    await recovery.locator('[data-player-choose-source]').click();

    const dialog = page.locator('.catalog-player-menu');

    await expect(dialog).toBeVisible();
    await expect(dialog).toHaveAttribute('data-player-menu-level', 'translations');
    await expect(dialog.locator('[data-player-menu-section="translations"]')).toBeVisible();

    if (testInfo.project.use.hasTouch && (page.viewportSize()?.width || 0) < 768) {
        const bottomSheet = await dialog.locator('.catalog-player-menu__panel').evaluate((element) => {
            const rect = element.getBoundingClientRect();
            const mobileNavigation = document.querySelector('[data-mobile-bottom-navigation]');
            const navigationTop = mobileNavigation instanceof HTMLElement
                ? mobileNavigation.getBoundingClientRect().top
                : window.innerHeight;

            return {
                bottomGap: window.innerHeight - rect.bottom,
                navigationGap: Math.abs(navigationTop - rect.bottom),
                overflow: document.documentElement.scrollWidth - window.innerWidth,
            };
        });

        expect(Math.min(bottomSheet.bottomGap, bottomSheet.navigationGap)).toBeLessThanOrEqual(32);
        expect(bottomSheet.overflow).toBeLessThanOrEqual(1);
    }

    await page.keyboard.press('Escape');
    await expect(dialog).toBeHidden();
});

test('mobile player controls and next episode target keep the 44px touch contract', async ({ page }, testInfo) => {
    test.skip(!testInfo.project.use.hasTouch, 'Touch target contract applies to touch projects.');

    await installPlayerMediaFixtures(page);
    await page.goto('/titles/browser-smoke?episode=1&format=mp4#player');
    await expect(page.locator('video.js-catalog-player')).toHaveAttribute('data-player-ready', '1');

    const controls = page.locator([
        '[data-player-theatre-toggle]',
        '[data-player-restart-episode]',
        '[data-player-shortcuts-open]',
        '[data-player-next-episode]',
    ].join(','));
    const sizes = await controls.evaluateAll((elements) => elements.map((element) => {
        const rect = element.getBoundingClientRect();

        return { width: rect.width, height: rect.height };
    }));

    expect(sizes.length).toBeGreaterThanOrEqual(3);
    sizes.forEach(({ width, height }) => {
        expect(width).toBeGreaterThanOrEqual(44);
        expect(height).toBeGreaterThanOrEqual(44);
    });

    expect(await page.locator('[data-player-loading-skeleton]').evaluate(
        (element) => getComputedStyle(element).pointerEvents,
    )).toBe('none');
});
