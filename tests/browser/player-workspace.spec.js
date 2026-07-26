import { expect, test } from '@playwright/test';
import { installPlayerMediaFixtures } from './support/player-media-fixtures.js';

test.afterEach(async ({ page }) => {
    await page.evaluate(() => {
        window.dispatchEvent(new PageTransitionEvent('pagehide', { persisted: false }));
    }).catch(() => {});
    await page.unrouteAll({ behavior: 'ignoreErrors' });
});

test('player workspace keeps theatre mode scoped and keyboard reversible', async ({ page }) => {
    await installPlayerMediaFixtures(page);
    await page.goto('/titles/browser-smoke?episode=1&format=mp4#player');

    const video = page.locator('video.js-catalog-player');
    const theatre = page.locator('[data-player-theatre-toggle]');
    const region = page.locator('[data-player-workspace-region]');

    await expect(video).toHaveAttribute('data-player-ready', '1');
    await expect(page.locator('[data-player-context-bar]')).toBeVisible();
    await theatre.click();
    await expect(page.locator('body')).toHaveClass(/player-theatre-active/);
    await expect(theatre).toHaveAttribute('aria-pressed', 'true');
    await expect(page.locator('[data-title-detail-sidebar]')).toBeHidden();

    const theatreGeometry = await region.evaluate((element) => ({
        regionWidth: element.getBoundingClientRect().width,
        viewportWidth: window.innerWidth,
        overflow: document.documentElement.scrollWidth - window.innerWidth,
    }));

    expect(Math.abs(theatreGeometry.regionWidth - theatreGeometry.viewportWidth)).toBeLessThanOrEqual(2);
    expect(theatreGeometry.overflow).toBeLessThanOrEqual(1);

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

    await page.locator('[data-player-shortcuts-open]').click();
    await expect(page.locator('[data-player-shortcuts-dialog]')).toBeVisible();
    await page.keyboard.press('Escape');
    await expect(page.locator('[data-player-shortcuts-dialog]')).toBeHidden();
    await expect(page.locator('body')).toHaveClass(/player-theatre-active/);

    await page.keyboard.press('Escape');
    await expect(page.locator('body')).not.toHaveClass(/player-theatre-active/);
    await expect(theatre).toHaveAttribute('aria-pressed', 'false');
    await expect(theatre).toBeFocused();
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
