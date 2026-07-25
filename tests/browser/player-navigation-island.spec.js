import { expect, test } from '@playwright/test';
import { installPlayerMediaFixtures } from './support/player-media-fixtures.js';

test.afterEach(async ({ page }) => {
    await page.evaluate(() => {
        window.dispatchEvent(new PageTransitionEvent('pagehide', { persisted: false }));
    }).catch(() => {});
    await page.unrouteAll({ behavior: 'ignoreErrors' });
});

test('episode links refresh through the navigation island without replacing the player', async ({ page, baseURL }) => {
    const consoleErrors = [];
    const pageErrors = [];
    const localAssetFailures = [];
    const localOrigin = new URL(baseURL).origin;

    page.on('console', (message) => {
        if (message.type() === 'error' && !message.text().startsWith('Failed to load resource:')) {
            consoleErrors.push(message.text());
        }
    });
    page.on('pageerror', (error) => pageErrors.push(error.message));
    page.on('response', (response) => {
        const url = new URL(response.url());

        if (
            url.origin === localOrigin
            && ['stylesheet', 'script', 'image', 'font'].includes(response.request().resourceType())
            && response.status() >= 400
        ) {
            localAssetFailures.push(`${response.status()} ${url.pathname}`);
        }
    });

    await installPlayerMediaFixtures(page);
    await page.goto('/titles/browser-smoke?episode=1&format=mp4#player');

    const video = page.locator('video.js-catalog-player');
    const next = page.locator('[data-player-next-episode]');
    const previous = page.locator('[data-player-previous-episode]');

    await expect(video).toHaveCount(1);
    await expect(video).toHaveAttribute('data-player-ready', '1');
    await expect(next).toHaveAttribute('data-player-transition-episode', '2');
    await expect(next).toContainText('2 серия');

    await video.evaluate((element) => {
        const shell = element.closest('[data-player-shell]');

        window.__playerNavigationIslandIdentity = {
            video: element,
            shell,
            plyr: shell?.querySelector('.plyr'),
        };
    });

    await next.click();
    await expect.poll(() => new URL(page.url()).searchParams.get('episode')).toBe('2');
    await expect(video).toHaveAttribute('data-progress-episode', '2');
    await expect(previous).toHaveAttribute('data-player-transition-episode', '1');
    await expect(next).toHaveAttribute('data-player-transition-episode', '3');
    await expect(next).toContainText('3 серия');

    await next.click();
    await expect.poll(() => new URL(page.url()).searchParams.get('episode')).toBe('3');
    await expect(video).toHaveAttribute('data-progress-episode', '3');
    await expect(previous).toHaveAttribute('data-player-transition-episode', '2');
    await expect(next).toHaveCount(0);

    const identity = await video.evaluate((element) => {
        const shell = element.closest('[data-player-shell]');

        return {
            sameVideo: element === window.__playerNavigationIslandIdentity.video,
            sameShell: shell === window.__playerNavigationIslandIdentity.shell,
            samePlyr: shell?.querySelector('.plyr') === window.__playerNavigationIslandIdentity.plyr,
            playerCount: document.querySelectorAll('video.js-catalog-player').length,
            plyrCount: document.querySelectorAll('[data-player-shell] .plyr').length,
            horizontalOverflow: document.documentElement.scrollWidth - window.innerWidth,
        };
    });

    expect(identity).toEqual({
        sameVideo: true,
        sameShell: true,
        samePlyr: true,
        playerCount: 1,
        plyrCount: 1,
        horizontalOverflow: 0,
    });
    expect(localAssetFailures).toEqual([]);
    expect(consoleErrors).toEqual([]);
    expect(pageErrors).toEqual([]);
});

test('episode link performs a full fallback navigation when the player runtime is unavailable', async ({ page }) => {
    await installPlayerMediaFixtures(page);
    await page.goto('/titles/browser-smoke?episode=1&format=mp4#player');

    const video = page.locator('video.js-catalog-player');
    const next = page.locator('[data-player-next-episode]');

    await expect(video).toHaveAttribute('data-player-ready', '1');
    await expect(video).toHaveAttribute('data-progress-episode', '1');
    await expect(next).toHaveAttribute('data-player-transition-episode', '2');

    await page.evaluate(() => {
        document.documentElement.dataset.playerFallbackDocument = 'original';
        window.dispatchEvent(new PageTransitionEvent('pagehide', { persisted: false }));
    });

    await next.click();
    await expect.poll(() => new URL(page.url()).searchParams.get('episode')).toBe('2');
    await expect(page.locator('html')).not.toHaveAttribute('data-player-fallback-document', 'original');
    await expect(video).toHaveAttribute('data-progress-episode', '2');
    await expect(page.locator('[data-player-previous-episode]')).toHaveAttribute('data-player-transition-episode', '1');
    await expect(page.locator('[data-player-next-episode]')).toHaveAttribute('data-player-transition-episode', '3');
});
