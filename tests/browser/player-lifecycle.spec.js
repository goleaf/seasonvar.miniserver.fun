import { expect, test } from '@playwright/test';
import { installPlayerMediaFixtures } from './support/player-media-fixtures.js';

const loginPassword = 'Browser-Strong-Password-42!';

test.afterEach(async ({ page }) => {
    await page.evaluate(() => {
        window.dispatchEvent(new PageTransitionEvent('pagehide', { persisted: false }));
    }).catch(() => {});
    await page.unrouteAll({ behavior: 'ignoreErrors' });
});

const isSameOrigin = (requestUrl, baseURL) => {
    const url = new URL(requestUrl);

    return ['http:', 'https:'].includes(url.protocol)
        && url.origin === new URL(baseURL).origin;
};

const installBrowserGuard = async (page, baseURL) => {
    const sameOriginFailures = [];
    const externalLeaks = [];
    const consoleErrors = [];
    const reportOnlyDiagnostics = [];
    const pageErrors = [];

    await page.context().route('**/*', async (route) => {
        const requestUrl = route.request().url();
        const url = new URL(requestUrl);

        if (
            url.origin === 'https://media.example.com'
            && url.pathname.startsWith('/player-fixtures/')
        ) {
            await route.fallback();

            return;
        }

        if (!isSameOrigin(requestUrl, baseURL)) {
            externalLeaks.push(`${url.origin}${url.pathname}`);
            await route.abort('blockedbyclient');

            return;
        }

        await route.fallback();
    });

    page.on('response', (response) => {
        const url = new URL(response.url());

        if (
            isSameOrigin(response.url(), baseURL)
            && response.status() >= 400
            && !url.pathname.startsWith('/player-fixtures/')
            && !url.pathname.startsWith('/playback/')
        ) {
            sameOriginFailures.push(`${response.status()} ${url.pathname}`);
        }
    });
    page.on('requestfailed', (request) => {
        if (
            isSameOrigin(request.url(), baseURL)
            && request.failure()?.errorText !== 'net::ERR_ABORTED'
        ) {
            sameOriginFailures.push(`${request.failure()?.errorText || 'request failed'} ${new URL(request.url()).pathname}`);
        }
    });
    page.on('console', (message) => {
        if (message.type() !== 'error' || message.text().startsWith('Failed to load resource:')) {
            return;
        }

        if (
            message.text().includes('Content-Security-Policy: (Report-Only policy)')
            && message.text().includes('/vendor/livewire/livewire')
        ) {
            reportOnlyDiagnostics.push(message.text());

            return;
        }

        consoleErrors.push(message.text());
    });
    page.on('pageerror', (error) => pageErrors.push(error.message));

    return {
        sameOriginFailures,
        externalLeaks,
        consoleErrors,
        reportOnlyDiagnostics,
        pageErrors,
    };
};

const assertNoBrowserErrors = (errors) => {
    expect(errors.sameOriginFailures).toEqual([]);
    expect(errors.externalLeaks).toEqual([]);
    expect(errors.consoleErrors).toEqual([]);
    expect(errors.pageErrors).toEqual([]);
};

const login = async (page, localePrefix = '') => {
    await page.goto(`${localePrefix}/login`);
    await page.locator('input[type="email"]').fill(
        localePrefix === '/en' ? 'browser-en@example.com' : 'browser@example.com',
    );
    await page.locator('input[type="password"]').fill(loginPassword);
    await page.locator('form').filter({ has: page.locator('input[type="email"]') }).locator('button[type="submit"]').click();
    await expect(page).toHaveURL(/\/library(?:\?|$)/);

};

const currentVideo = (page) => page.locator('video.js-catalog-player');

const waitForPlayer = async (page) => {
    await expect(currentVideo(page)).toHaveCount(1);
    await expect(currentVideo(page)).toHaveAttribute('data-player-ready', '1');

    return currentVideo(page).getAttribute('data-player-session');
};

const selectPlayerMediaFormat = async (page, format) => {
    const option = page.locator(`[data-player-media-format="${format}"]`);

    await page.locator('[data-player-context-control="quality"] > summary').click();
    await expect(option).toBeVisible();
    await option.click();
};

const setAutoplayPreference = async (page, enabled) => {
    const toggle = page.locator('[data-player-autoplay-toggle]');
    const desired = enabled ? 'true' : 'false';

    await expect(toggle).toHaveAttribute('aria-pressed', /^(true|false)$/);

    if (await toggle.getAttribute('aria-pressed') !== desired) {
        const saved = page.waitForResponse((response) => (
            new URL(response.url()).pathname.includes('/livewire')
            && response.request().method() === 'POST'
            && response.status() === 200
        ));

        await toggle.click();
        await expect(toggle).toHaveAttribute('aria-pressed', desired);
        await saved;
    }

    if (!enabled) {
        await currentVideo(page).evaluate((video) => video.pause());
    }
};

const playerCopy = async (page) => page.locator('[data-player-shell]').evaluate(
    (shell) => JSON.parse(shell.dataset.playerCopy),
);

const assertResponsivePlayer = async (page) => {
    const geometry = await page.evaluate(() => ({
        overflow: document.documentElement.scrollWidth - window.innerWidth,
        statusLive: document.querySelector('[data-player-status]')?.getAttribute('aria-live'),
        captionLive: document.querySelector('[data-player-caption-status]')?.getAttribute('aria-live'),
    }));

    expect(geometry.overflow).toBeLessThanOrEqual(1);
    expect(geometry.statusLive).toBe('polite');
    expect(geometry.captionLive).toBe('polite');
};

const waitForFixtureCount = async (fixtures, suffix, minimum) => {
    await expect.poll(() => fixtures.count(suffix)).toBeGreaterThanOrEqual(minimum);
};

test('desktop Chromium and Firefox decode and advance the verified MP4 fixture', async ({ page, baseURL }, testInfo) => {
    test.skip(
        !['Desktop Chromium', 'Desktop Firefox'].includes(testInfo.project.name),
        'Physical and responsive projects have separate evidence boundaries.',
    );

    const errors = await installBrowserGuard(page, baseURL);
    const fixtures = await installPlayerMediaFixtures(page);

    await page.goto('/titles/browser-smoke?episode=1&format=mp4');
    await waitForPlayer(page);
    await currentVideo(page).evaluate(async (media) => {
        media.muted = true;
        media.currentTime = 0;
        await media.play();
    });

    await expect.poll(() => currentVideo(page).evaluate((media) => ({
        currentTime: media.currentTime,
        readyState: media.readyState,
        error: media.error?.code ?? null,
    }))).toMatchObject({
        error: null,
    });
    await expect.poll(
        () => currentVideo(page).evaluate((media) => media.readyState),
    ).toBeGreaterThanOrEqual(2);
    await expect.poll(
        () => currentVideo(page).evaluate((media) => media.currentTime),
    ).toBeGreaterThan(0.05);
    await waitForFixtureCount(fixtures, '/direct.mp4', 1);
    expect(fixtures.observations.some(({ path, status }) => (
        path.endsWith('/direct.mp4') && [200, 206].includes(status)
    ))).toBe(true);

    await currentVideo(page).evaluate((media) => media.pause());
    expect(errors.reportOnlyDiagnostics.every((message) => (
        message.includes('Content-Security-Policy: (Report-Only policy)')
        && message.includes('/vendor/livewire/livewire')
    ))).toBe(true);
    assertNoBrowserErrors(errors);
});

for (const locale of [
    { code: 'ru', prefix: '' },
    { code: 'en', prefix: '/en' },
]) {
    test(`player keeps one localized session through lifecycle transitions (${locale.code})`, async ({ page, baseURL }) => {
        test.setTimeout(90_000);

        const errors = await installBrowserGuard(page, baseURL);
        const fixtures = await installPlayerMediaFixtures(page);

        await login(page, locale.prefix);
        await page.goto('/titles/browser-smoke?episode=1&format=m3u8');

        const initialSession = await waitForPlayer(page);
        await setAutoplayPreference(page, false);
        const copy = await playerCopy(page);
        const statusText = await page.locator('[data-player-status-text]').textContent();

        expect(await page.locator('html').getAttribute('lang')).toBe(locale.code);
        expect(Object.values(copy.runtime)).toContain(statusText.trim());
        await expect(page.locator('[data-plyr="play"]').first()).toHaveAttribute('aria-label', copy.controls.play);
        await expect(page.locator('[data-player-caption-status]')).toBeHidden();
        await waitForFixtureCount(fixtures, '/valid.m3u8', 1);

        await currentVideo(page).evaluate((video) => {
            video.dataset.lifecycleIdentity = 'preserved';
        });

        const viewport = page.viewportSize();

        await page.setViewportSize({
            width: Math.max(360, viewport.width - 8),
            height: Math.max(640, viewport.height - 8),
        });
        await expect(currentVideo(page)).toHaveAttribute('data-lifecycle-identity', 'preserved');
        await expect(currentVideo(page)).toHaveAttribute('data-player-session', initialSession);

        await page.evaluate(() => {
            document.dispatchEvent(new Event('livewire:navigated'));
            document.dispatchEvent(new Event('livewire:navigated'));
        });
        await expect(currentVideo(page)).toHaveCount(1);
        await expect(currentVideo(page)).toHaveAttribute('data-player-session', initialSession);

        await page.evaluate(() => window.Livewire.navigate('/titles'));
        await expect(page).toHaveURL(/\/titles$/);
        await expect(currentVideo(page)).toHaveCount(0);

        await page.goBack();
        await expect(page).toHaveURL(/\/titles\/browser-smoke\?episode=1&format=m3u8$/);
        await waitForPlayer(page);
        await expect(currentVideo(page)).not.toHaveAttribute('data-lifecycle-identity', 'preserved');

        await page.goForward();
        await expect(page).toHaveURL(/\/titles$/);
        await page.goBack();
        await waitForPlayer(page);

        await selectPlayerMediaFormat(page, 'mp4');
        await expect(page).toHaveURL(/format=mp4/);
        await expect(currentVideo(page)).not.toHaveAttribute('data-player-session', initialSession);
        const mp4Session = await waitForPlayer(page);

        expect(mp4Session).not.toBe(initialSession);
        await expect(currentVideo(page)).toHaveCount(1);

        const readyAfterPageHide = await page.evaluate(() => {
            window.dispatchEvent(new PageTransitionEvent('pagehide', { persisted: true }));

            return document.querySelector('video.js-catalog-player')?.dataset.playerReady ?? null;
        });

        expect(readyAfterPageHide).toBeNull();
        await page.evaluate(() => {
            window.dispatchEvent(new PageTransitionEvent('pageshow', { persisted: true }));
        });
        await expect(currentVideo(page)).toHaveAttribute('data-player-ready', '1');

        const progress = await page.evaluate(() => {
            const video = document.querySelector('video.js-catalog-player');
            const events = [];

            video.addEventListener('catalog-progress', (event) => {
                events.push(event.detail);
                event.stopPropagation();
            });
            Object.defineProperties(video, {
                currentTime: { configurable: true, writable: true, value: 10 },
                duration: { configurable: true, value: 100 },
                ended: { configurable: true, value: false },
                paused: { configurable: true, value: false },
            });
            video.dispatchEvent(new Event('play'));
            video.currentTime = 20;
            Object.defineProperty(video, 'paused', { configurable: true, value: true });
            video.dispatchEvent(new Event('pause'));

            return {
                events: events.map(({ eventSequence, positionSeconds, reason }) => ({
                    eventSequence,
                    positionSeconds,
                    reason,
                })),
                state: {
                    activeSession: video.closest('[data-active-player-session]')?.dataset.activePlayerSession,
                    playerSession: video.dataset.playerSession,
                    progressEnabled: video.dataset.progressEnabled,
                    hasToken: Boolean(video.dataset.progressSession),
                    ready: video.dataset.playerReady,
                },
            };
        });

        expect(progress.events, JSON.stringify(progress.state)).toEqual([
            { eventSequence: 1, positionSeconds: 10, reason: 'play' },
            { eventSequence: 2, positionSeconds: 20, reason: 'pause' },
        ]);

        const playButton = page.locator('[data-plyr="play"]').first();

        await playButton.focus();
        await expect(playButton).toBeFocused();
        await assertResponsivePlayer(page);
        assertNoBrowserErrors(errors);
    });
}

test('episode menu keeps focus and exposes localized seasons episodes and translations', async ({ page, baseURL }, testInfo) => {
    test.skip(testInfo.project.name !== 'Desktop Chromium', 'Episode menu keyboard behavior runs once.');

    const errors = await installBrowserGuard(page, baseURL);

    await installPlayerMediaFixtures(page);
    await login(page);
    await page.goto('/titles/browser-smoke?episode=1&format=mp4');
    await waitForPlayer(page);
    await setAutoplayPreference(page, false);

    const copy = await playerCopy(page);
    const opener = page.getByRole('button', { name: copy.menu.open });

    await expect(opener).toHaveCount(1);
    await opener.click();

    const dialog = page.getByRole('dialog', { name: copy.menu.title });

    await expect(dialog).toBeVisible();
    await expect(dialog.getByRole('heading', { name: copy.menu.seasons, exact: true })).toBeVisible();
    await expect(dialog.getByRole('heading', { name: copy.menu.episodes, exact: true })).toBeVisible();
    await expect(dialog.getByRole('heading', { name: copy.menu.translations, exact: true })).toBeVisible();
    await expect(dialog.locator('[data-player-menu-section="seasons"] [aria-current="true"]')).toHaveCount(1);
    await expect(dialog.locator('[data-player-menu-section="translations"] [aria-current="true"]')).toHaveCount(1);

    const focusedBeforeTab = await page.evaluate(() => document.activeElement?.closest('dialog') !== null);

    expect(focusedBeforeTab).toBe(true);

    for (let index = 0; index < 12; index += 1) {
        await page.keyboard.press('Tab');
        expect(await page.evaluate(() => document.activeElement?.closest('dialog') !== null)).toBe(true);
    }

    await page.keyboard.press('Escape');
    await expect(dialog).toBeHidden();
    await expect(opener).toBeFocused();

    await page.keyboard.press('Shift+E');
    await expect(dialog).toBeVisible();

    const playbackBefore = await currentVideo(page).evaluate((video) => ({
        paused: video.paused,
        currentTime: video.currentTime,
    }));

    for (const key of ['Space', 'k', 'ArrowLeft', 'ArrowRight']) {
        if (!await dialog.isVisible()) {
            await page.keyboard.press('Shift+E');
            await expect(dialog).toBeVisible();
        }

        await dialog.getByRole('button', { name: copy.menu.close }).focus();
        await page.keyboard.press(key);
    }

    const playbackAfter = await currentVideo(page).evaluate((video) => ({
        paused: video.paused,
        currentTime: video.currentTime,
    }));

    expect(playbackAfter).toEqual(playbackBefore);

    if (await dialog.isVisible()) {
        await page.keyboard.press('Escape');
    }

    assertNoBrowserErrors(errors);
});

test('player hot swaps episodes and translations without replacing its media owners', async ({ page, baseURL }, testInfo) => {
    test.skip(testInfo.project.name !== 'Desktop Chromium', 'In-place identity behavior runs once.');

    const errors = await installBrowserGuard(page, baseURL);

    const fixtures = await installPlayerMediaFixtures(page);
    await login(page);
    await page.goto('/titles/browser-smoke?episode=1&format=mp4&ref=browser#player');
    await waitForPlayer(page);
    await setAutoplayPreference(page, false);

    const copy = await playerCopy(page);
    const initialEpisodeId = await currentVideo(page).getAttribute('data-progress-episode');

    await page.evaluate(() => {
        const video = document.querySelector('video.js-catalog-player');
        const shell = video?.closest('[data-player-shell]');
        const plyr = shell?.querySelector('.plyr');

        window.__catalogPlayerIdentity = { video, shell, plyr };
        video.dataset.hotSwapIdentity = 'video';
        shell.dataset.hotSwapIdentity = 'shell';
        plyr.dataset.hotSwapIdentity = 'plyr';
    });

    await page.getByRole('button', { name: copy.menu.open }).click();
    const dialog = page.getByRole('dialog', { name: copy.menu.title });
    const episodeOptions = dialog.locator('[data-player-menu-section="episodes"] [role="option"]');

    await expect(episodeOptions).toHaveCount(3);
    await episodeOptions.nth(1).click();
    await expect(currentVideo(page)).not.toHaveAttribute('data-progress-episode', initialEpisodeId);
    await expect(dialog).toBeHidden();

    const episodeIdentity = await page.evaluate(() => {
        const currentVideoNode = document.querySelector('video.js-catalog-player');
        const currentShell = currentVideoNode?.closest('[data-player-shell]');
        const currentPlyr = currentShell?.querySelector('.plyr');

        return {
            video: currentVideoNode === window.__catalogPlayerIdentity.video,
            shell: currentShell === window.__catalogPlayerIdentity.shell,
            plyr: currentPlyr === window.__catalogPlayerIdentity.plyr,
            videoCount: document.querySelectorAll('video.js-catalog-player').length,
            plyrCount: currentShell?.querySelectorAll('.plyr').length,
            position: currentVideoNode?.currentTime,
        };
    });

    expect(episodeIdentity).toEqual({
        video: true,
        shell: true,
        plyr: true,
        videoCount: 1,
        plyrCount: 1,
        position: 0,
    });
    await expect(page).toHaveURL(/episode=\d+.*#player$/);
    expect(new URL(page.url()).searchParams.get('ref')).toBe('browser');

    await page.getByRole('button', { name: copy.menu.open }).click();
    const translationOptions = dialog.locator('[data-player-menu-section="translations"] [role="option"]');
    const currentMediaId = await currentVideo(page).getAttribute('data-player-media-id');

    await expect(translationOptions).toHaveCount(2);
    await dialog.locator('[data-player-menu-section="translations"] [role="option"]:not([aria-current="true"])').first().click();
    await expect(currentVideo(page)).not.toHaveAttribute('data-player-media-id', currentMediaId);
    await waitForFixtureCount(fixtures, '/valid-next.m3u8', 1);
    const translatedMediaId = await currentVideo(page).getAttribute('data-player-media-id');

    await expect(currentVideo(page)).toHaveAttribute('data-hot-swap-identity', 'video');
    await expect(page.locator('[data-player-shell]')).toHaveAttribute('data-hot-swap-identity', 'shell');
    await expect(page.locator('[data-player-shell] .plyr')).toHaveAttribute('data-hot-swap-identity', 'plyr');
    expect(new URL(page.url()).searchParams.get('ref')).toBe('browser');
    expect(new URL(page.url()).hash).toBe('#player');
    await expect.poll(() => new URL(page.url()).searchParams.get('media')).toBe(translatedMediaId);

    await page.evaluate(() => window.history.back());
    await expect(currentVideo(page)).toHaveAttribute('data-player-media-id', currentMediaId);
    await expect(currentVideo(page)).toHaveCount(1);
    expect(new URL(page.url()).searchParams.get('ref')).toBe('browser');

    await page.evaluate(() => window.history.forward());
    await expect(currentVideo(page)).toHaveAttribute('data-player-media-id', translatedMediaId);
    await expect(currentVideo(page)).toHaveCount(1);
    expect(new URL(page.url()).hash).toBe('#player');
    assertNoBrowserErrors(errors);
});

test('immediate auto next keeps the same player and has no countdown delay', async ({ page, baseURL }, testInfo) => {
    test.skip(testInfo.project.name !== 'Desktop Chromium', 'Immediate auto-next timing runs once.');

    const errors = await installBrowserGuard(page, baseURL);

    await installPlayerMediaFixtures(page);
    await login(page);
    await page.goto('/titles/browser-smoke?episode=1&format=mp4#player');
    await waitForPlayer(page);

    const initialEpisodeId = await currentVideo(page).getAttribute('data-progress-episode');

    await expect(page.locator('[data-player-autoplay-countdown]')).toHaveCount(0);
    await page.evaluate(() => {
        const video = document.querySelector('video.js-catalog-player');

        window.__autoNextEvidence = {
            video,
            shell: video.closest('[data-player-shell]'),
            plyr: video.closest('[data-player-shell]').querySelector('.plyr'),
            completedAt: null,
            rotatedAt: null,
            completedEpisodeId: null,
        };
        video.addEventListener('catalog-progress', (event) => {
            if (event.detail?.completed) {
                window.__autoNextEvidence.completedAt = performance.now();
                window.__autoNextEvidence.completedEpisodeId = String(event.detail.episodeId);
            }
        });
        new MutationObserver(() => {
            if (
                window.__autoNextEvidence.rotatedAt === null
                && video.dataset.progressEpisode !== String(window.__autoNextEvidence.completedEpisodeId)
            ) {
                window.__autoNextEvidence.rotatedAt = performance.now();
            }
        }).observe(video, { attributes: true, attributeFilter: ['data-progress-episode'] });
    });

    await setAutoplayPreference(page, true);

    await currentVideo(page).evaluate((video) => video.play());
    await expect(currentVideo(page)).not.toHaveAttribute('data-progress-episode', initialEpisodeId, {
        timeout: 5_000,
    });

    const evidence = await page.evaluate(() => {
        const video = document.querySelector('video.js-catalog-player');
        const shell = video.closest('[data-player-shell]');

        return {
            sameVideo: video === window.__autoNextEvidence.video,
            sameShell: shell === window.__autoNextEvidence.shell,
            samePlyr: shell.querySelector('.plyr') === window.__autoNextEvidence.plyr,
            completedEpisodeId: window.__autoNextEvidence.completedEpisodeId,
            transitionDelay: window.__autoNextEvidence.rotatedAt - window.__autoNextEvidence.completedAt,
            currentTime: video.currentTime,
        };
    });

    expect(evidence.sameVideo).toBe(true);
    expect(evidence.sameShell).toBe(true);
    expect(evidence.samePlyr).toBe(true);
    expect(evidence.completedEpisodeId).toBe(initialEpisodeId);
    expect(evidence.transitionDelay).toBeGreaterThanOrEqual(0);
    expect(evidence.transitionDelay).toBeLessThan(1_000);
    expect(evidence.currentTime).toBeLessThan(0.5);
    await setAutoplayPreference(page, false);
    assertNoBrowserErrors(errors);
});

test('responsive episode menu stays within the viewport with reachable touch controls', async ({ page, baseURL }) => {
    const errors = await installBrowserGuard(page, baseURL);

    await installPlayerMediaFixtures(page);
    await login(page);
    await page.goto('/titles/browser-smoke?episode=1&format=mp4#player');
    await waitForPlayer(page);
    await setAutoplayPreference(page, false);

    const copy = await playerCopy(page);

    await page.getByRole('button', { name: copy.menu.open }).click();
    const dialog = page.getByRole('dialog', { name: copy.menu.title });

    await expect(dialog).toBeVisible();
    await expect(dialog.locator('[data-player-menu-section="episodes"] [role="option"]')).toHaveCount(3);

    const geometry = await dialog.evaluate((menu) => {
        const panel = menu.querySelector('.catalog-player-menu__panel');
        const panelRect = panel.getBoundingClientRect();
        const visibleSections = [...menu.querySelectorAll('[data-player-menu-section]')]
            .filter((section) => {
                const style = getComputedStyle(section);

                return style.display !== 'none' && style.visibility !== 'hidden';
            });
        const visibleTargets = [...menu.querySelectorAll([
            '.catalog-player-menu__option',
            '.catalog-player-menu__back',
            '.catalog-player-menu__close',
            '.catalog-player-menu__pagination button',
        ].join(','))]
            .filter((target) => {
                const rect = target.getBoundingClientRect();
                const style = getComputedStyle(target);

                return rect.width > 0 && rect.height > 0 && style.visibility !== 'hidden';
            })
            .map((target) => {
                const rect = target.getBoundingClientRect();

                return { width: rect.width, height: rect.height };
            });

        return {
            documentOverflow: document.documentElement.scrollWidth - window.innerWidth,
            panel: {
                top: panelRect.top,
                right: panelRect.right,
                bottom: panelRect.bottom,
                left: panelRect.left,
            },
            viewport: { width: window.innerWidth, height: window.innerHeight },
            visibleSectionCount: visibleSections.length,
            listOverflow: visibleSections.some((section) => {
                const list = section.querySelector('.catalog-player-menu__list');

                return list.scrollHeight > list.clientHeight + 1 || list.scrollWidth > list.clientWidth + 1;
            }),
            visibleTargets,
        };
    });

    expect(geometry.documentOverflow).toBeLessThanOrEqual(1);
    expect(geometry.panel.top).toBeGreaterThanOrEqual(0);
    expect(geometry.panel.left).toBeGreaterThanOrEqual(0);
    expect(geometry.panel.right).toBeLessThanOrEqual(geometry.viewport.width);
    expect(geometry.panel.bottom).toBeLessThanOrEqual(geometry.viewport.height);
    expect(geometry.visibleSectionCount).toBe(geometry.viewport.width >= 768 ? 3 : 1);
    expect(geometry.listOverflow).toBe(false);

    for (const target of geometry.visibleTargets) {
        expect(target.width).toBeGreaterThanOrEqual(44);
        expect(target.height).toBeGreaterThanOrEqual(44);
    }

    assertNoBrowserErrors(errors);
});

test('fullscreen identity survives an in-player episode transition', async ({ page, baseURL }, testInfo) => {
    test.skip(testInfo.project.name !== 'Desktop Chromium', 'Standard fullscreen capability runs once.');

    const errors = await installBrowserGuard(page, baseURL);

    await installPlayerMediaFixtures(page);
    await login(page);
    await page.goto('/titles/browser-smoke?episode=1&format=mp4#player');
    await waitForPlayer(page);
    await setAutoplayPreference(page, false);

    const normalBackground = await page.evaluate(() => {
        const player = document.querySelector('[data-player-shell] .plyr');
        const wrapper = player?.querySelector('.plyr__video-wrapper');
        const video = player?.querySelector('video.js-catalog-player');

        return [player, wrapper, video].map((node) => getComputedStyle(node).backgroundColor);
    });

    expect(normalBackground).toEqual([
        'rgb(0, 0, 0)',
        'rgb(0, 0, 0)',
        'rgb(0, 0, 0)',
    ]);

    const enteredFullscreen = await page.evaluate(async () => {
        const player = document.querySelector('[data-player-shell] .plyr');

        if (!document.fullscreenEnabled || typeof player?.requestFullscreen !== 'function') {
            return false;
        }

        try {
            await player.requestFullscreen();

            window.__fullscreenPlayerIdentity = document.fullscreenElement;

            return document.fullscreenElement === player;
        } catch {
            return false;
        }
    });

    test.skip(!enteredFullscreen, 'The browser did not grant standard fullscreen.');

    const fullscreenBackground = await page.evaluate(() => {
        const player = document.fullscreenElement;
        const wrapper = player?.querySelector('.plyr__video-wrapper');
        const video = player?.querySelector('video.js-catalog-player');

        return [player, wrapper, video].map((node) => getComputedStyle(node).backgroundColor);
    });

    expect(fullscreenBackground).toEqual([
        'rgb(0, 0, 0)',
        'rgb(0, 0, 0)',
        'rgb(0, 0, 0)',
    ]);

    const copy = await playerCopy(page);

    await page.getByRole('button', { name: copy.menu.open }).click();
    const dialog = page.getByRole('dialog', { name: copy.menu.title });

    await expect(dialog).toBeVisible();
    expect(await dialog.evaluate((node) => document.fullscreenElement?.contains(node))).toBe(true);

    const episodeOptions = dialog.locator('[data-player-menu-section="episodes"] [role="option"]');
    const currentEpisodeId = await currentVideo(page).getAttribute('data-progress-episode');

    await expect(episodeOptions).toHaveCount(3);
    await dialog.locator('[data-player-menu-section="episodes"] [role="option"]:not([aria-current="true"])').first().click();
    await expect(currentVideo(page)).not.toHaveAttribute('data-progress-episode', currentEpisodeId);
    expect(await page.evaluate(() => (
        document.fullscreenElement === window.__fullscreenPlayerIdentity
    ))).toBe(true);
    expect(await page.evaluate(() => document.fullscreenElement?.querySelectorAll('video').length)).toBe(1);
    expect(await page.evaluate(() => {
        const player = document.fullscreenElement;
        const wrapper = player?.querySelector('.plyr__video-wrapper');
        const video = player?.querySelector('video.js-catalog-player');

        return [player, wrapper, video].map((node) => getComputedStyle(node).backgroundColor);
    })).toEqual([
        'rgb(0, 0, 0)',
        'rgb(0, 0, 0)',
        'rgb(0, 0, 0)',
    ]);
    assertNoBrowserErrors(errors);
});

test('center touch controls stay aligned and seek exactly ten seconds', async ({ page, baseURL }) => {
    test.setTimeout(90_000);

    const errors = await installBrowserGuard(page, baseURL);

    await installPlayerMediaFixtures(page);
    await login(page);
    await page.goto('/titles/browser-smoke?episode=1&format=mp4');
    await waitForPlayer(page);
    await setAutoplayPreference(page, false);

    const copy = await playerCopy(page);
    const controls = page.locator('[data-player-center-controls]');
    const buttons = controls.locator('button');
    const rewind = controls.locator('[data-player-center-control="rewind"]');
    const toggle = controls.locator('[data-player-center-control="toggle"]');
    const forward = controls.locator('[data-player-center-control="forward"]');
    const rewindLabel = copy.controls.rewind.replace('{seektime}', '10');
    const forwardLabel = copy.controls.fastForward.replace('{seektime}', '10');

    await expect(controls).toHaveCount(1);
    await expect(buttons).toHaveCount(3);
    await expect(rewind).toHaveAttribute('type', 'button');
    await expect(toggle).toHaveAttribute('type', 'button');
    await expect(forward).toHaveAttribute('type', 'button');
    await expect(rewind).toHaveAttribute('aria-label', rewindLabel);
    await expect(toggle).toHaveAttribute('aria-label', copy.controls.play);
    await expect(forward).toHaveAttribute('aria-label', forwardLabel);

    const geometry = await page.evaluate(() => {
        const player = document.querySelector('[data-player-shell] .plyr');
        const controlsNode = player?.querySelector('[data-player-center-controls]');
        const orderedControls = [...(controlsNode?.querySelectorAll('[data-player-center-control]') || [])];
        const playerBox = player?.getBoundingClientRect();
        const boxes = orderedControls.map((control) => {
            const box = control.getBoundingClientRect();

            return {
                kind: control.dataset.playerCenterControl,
                width: box.width,
                height: box.height,
                centerX: box.left + (box.width / 2),
                centerY: box.top + (box.height / 2),
            };
        });

        return {
            overflow: document.documentElement.scrollWidth - window.innerWidth,
            playerCenterX: playerBox ? playerBox.left + (playerBox.width / 2) : null,
            playerCenterY: playerBox ? playerBox.top + (playerBox.height / 2) : null,
            boxes,
        };
    });

    expect(geometry.overflow).toBeLessThanOrEqual(1);
    expect(geometry.boxes.map(({ kind }) => kind)).toEqual(['rewind', 'toggle', 'forward']);
    expect(geometry.boxes[0].width).toBeGreaterThanOrEqual(56);
    expect(geometry.boxes[0].height).toBeGreaterThanOrEqual(56);
    expect(geometry.boxes[1].width).toBeGreaterThanOrEqual(68);
    expect(geometry.boxes[1].height).toBeGreaterThanOrEqual(68);
    expect(geometry.boxes[1].width).toBeGreaterThan(geometry.boxes[0].width);
    expect(Math.abs(geometry.boxes[0].centerY - geometry.boxes[1].centerY)).toBeLessThanOrEqual(1);
    expect(Math.abs(geometry.boxes[2].centerY - geometry.boxes[1].centerY)).toBeLessThanOrEqual(1);
    expect(Math.abs(geometry.boxes[1].centerX - geometry.playerCenterX)).toBeLessThanOrEqual(2);
    expect(Math.abs(geometry.boxes[1].centerY - geometry.playerCenterY)).toBeLessThanOrEqual(2);

    await currentVideo(page).evaluate((video) => {
        const state = {
            currentTime: 50,
            duration: 120,
            paused: true,
            plays: 0,
            pauses: 0,
        };

        window.__playerCenterControlState = state;
        Object.defineProperties(video, {
            currentTime: {
                configurable: true,
                get: () => state.currentTime,
                set: (value) => {
                    state.currentTime = Number(value);
                },
            },
            duration: {
                configurable: true,
                get: () => state.duration,
            },
            ended: {
                configurable: true,
                get: () => false,
            },
            paused: {
                configurable: true,
                get: () => state.paused,
            },
        });
        video.play = () => {
            state.paused = false;
            state.plays += 1;
            video.dispatchEvent(new Event('play'));

            return Promise.resolve();
        };
        video.pause = () => {
            state.paused = true;
            state.pauses += 1;
            video.dispatchEvent(new Event('pause'));
        };
    });

    await rewind.click();
    expect(await page.evaluate(() => window.__playerCenterControlState.currentTime)).toBe(40);
    await forward.click();
    expect(await page.evaluate(() => window.__playerCenterControlState.currentTime)).toBe(50);

    await toggle.click();
    await expect.poll(() => page.evaluate(() => window.__playerCenterControlState.plays)).toBe(1);
    await expect(toggle).toHaveAttribute('aria-label', copy.controls.pause);

    await toggle.click();
    await expect.poll(() => page.evaluate(() => window.__playerCenterControlState.pauses)).toBe(1);
    await expect(toggle).toHaveAttribute('aria-label', copy.controls.play);

    assertNoBrowserErrors(errors);
});

test('global playback shortcuts work outside the player and respect interaction boundaries', async ({ page, baseURL }, testInfo) => {
    test.skip(testInfo.project.name !== 'Desktop Chromium', 'Keyboard behavior runs once.');

    const errors = await installBrowserGuard(page, baseURL);

    await installPlayerMediaFixtures(page);
    await login(page);
    await page.goto('/titles/browser-smoke?episode=1&format=mp4');
    await waitForPlayer(page);
    await setAutoplayPreference(page, false);
    const copy = await playerCopy(page);
    const menuDialog = page.getByRole('dialog', { name: copy.menu.title });

    await currentVideo(page).evaluate((video) => {
        const state = {
            currentTime: 50,
            duration: 120,
            paused: true,
            plays: 0,
            pauses: 0,
        };

        window.__playerKeyboardState = state;
        Object.defineProperties(video, {
            currentTime: {
                configurable: true,
                get: () => state.currentTime,
                set: (value) => {
                    state.currentTime = Number(value);
                },
            },
            duration: {
                configurable: true,
                get: () => state.duration,
            },
            ended: {
                configurable: true,
                get: () => false,
            },
            paused: {
                configurable: true,
                get: () => state.paused,
            },
        });
        video.play = () => {
            state.paused = false;
            state.plays += 1;
            video.dispatchEvent(new Event('play'));

            return Promise.resolve();
        };
        video.pause = () => {
            state.paused = true;
            state.pauses += 1;
            video.dispatchEvent(new Event('pause'));
        };
    });

    const focusPage = () => page.evaluate(() => {
        if (document.activeElement instanceof HTMLElement) {
            document.activeElement.blur();
        }
    });

    await focusPage();
    await page.keyboard.press('Space');
    await expect.poll(() => page.evaluate(() => window.__playerKeyboardState.plays)).toBe(1);
    await page.keyboard.press('k');
    await expect.poll(() => page.evaluate(() => window.__playerKeyboardState.pauses)).toBe(1);

    const playButton = page.locator('[data-plyr="play"]').first();

    await playButton.focus();
    await page.keyboard.press('k');
    await expect.poll(() => page.evaluate(() => window.__playerKeyboardState.plays)).toBe(2);
    await page.keyboard.press('k');
    await expect.poll(() => page.evaluate(() => window.__playerKeyboardState.pauses)).toBe(2);

    await focusPage();
    await page.keyboard.press('ArrowRight');
    expect(await page.evaluate(() => window.__playerKeyboardState.currentTime)).toBe(60);
    await page.keyboard.press('ArrowLeft');
    expect(await page.evaluate(() => window.__playerKeyboardState.currentTime)).toBe(50);

    await page.evaluate(() => {
        window.__playerKeyboardState.currentTime = 5;
    });
    await page.keyboard.press('ArrowLeft');
    expect(await page.evaluate(() => window.__playerKeyboardState.currentTime)).toBe(0);

    await page.evaluate(() => {
        window.__playerKeyboardState.currentTime = 115;
    });
    await page.keyboard.press('ArrowRight');
    expect(await page.evaluate(() => window.__playerKeyboardState.currentTime)).toBe(120);

    const input = page.locator('#site-search');

    await input.focus();
    await input.press('Shift+E');
    await expect(input).toHaveValue('E');
    await expect(menuDialog).not.toBeVisible();
    await input.fill('');
    await page.keyboard.press('k');
    await page.keyboard.press('ArrowLeft');
    expect(await input.inputValue()).toBe('k');
    expect(await page.evaluate(() => window.__playerKeyboardState)).toMatchObject({
        currentTime: 120,
        plays: 2,
        pauses: 2,
    });

    await page.locator('[data-player-shortcuts-open]').click();
    const shortcutsDialog = page.locator('[data-player-shortcuts-dialog]');

    await expect(shortcutsDialog).toHaveAttribute('open', '');
    await shortcutsDialog.evaluate((dialog) => {
        dialog.tabIndex = -1;
        dialog.focus();
    });
    await page.keyboard.press('Shift+E');
    await expect(shortcutsDialog).toHaveAttribute('open', '');
    await expect(menuDialog).not.toBeVisible();
    await page.keyboard.press('Space');
    expect(await page.evaluate(() => window.__playerKeyboardState.plays)).toBe(2);
    await page.locator('[data-player-shortcuts-close]').click();

    await focusPage();
    await page.keyboard.press('Control+k');
    expect(await page.evaluate(() => window.__playerKeyboardState.plays)).toBe(2);

    await page.evaluate(() => window.Livewire.navigate('/titles'));
    await expect(page).toHaveURL(/\/titles$/);
    await expect(currentVideo(page)).toHaveCount(0);
    await page.locator('body').evaluate((body) => {
        body.focus();
        body.dispatchEvent(new KeyboardEvent('keydown', {
            key: ' ',
            bubbles: true,
            cancelable: true,
        }));
    });
    expect(await page.evaluate(() => window.__playerKeyboardState.plays)).toBe(2);
    assertNoBrowserErrors(errors);
});

test('desktop player uses deterministic HLS recovery, MP4 ranges, and WebVTT states', async ({ page, baseURL }, testInfo) => {
    test.skip(testInfo.project.name !== 'Desktop Chromium', 'Detailed media matrix runs once.');
    test.setTimeout(90_000);

    const errors = await installBrowserGuard(page, baseURL);
    const fixtures = await installPlayerMediaFixtures(page);

    await page.addInitScript(() => {
        const attachTrack = () => {
            const video = document.querySelector('video.js-catalog-player');

            if (!video || video.querySelector('track[data-player-fixture-track]')) {
                return false;
            }

            const track = document.createElement('track');

            track.kind = 'subtitles';
            track.srclang = 'ru';
            track.default = true;
            track.dataset.playerFixtureTrack = '1';
            track.src = '/player-fixtures/subtitles-ru.vtt';
            video.append(track);

            return true;
        };
        const observer = new MutationObserver(() => {
            if (attachTrack()) {
                observer.disconnect();
            }
        });
        observer.observe(document, { childList: true, subtree: true });
    });

    await login(page);
    await page.goto('/titles/browser-smoke?episode=1&format=m3u8');
    await waitForPlayer(page);
    await setAutoplayPreference(page, false);

    const copy = await playerCopy(page);

    await waitForFixtureCount(fixtures, '/valid.m3u8', 1);
    await waitForFixtureCount(fixtures, '/hls-init.mp4', 1);
    await waitForFixtureCount(fixtures, '/hls-segment.m4s', 1);
    await waitForFixtureCount(fixtures, '/subtitles-ru.vtt', 1);
    await expect(page.locator('[data-player-caption-status]')).toBeHidden();

    const failedCaptionBaseline = fixtures.count('/subtitles-ru.vtt');

    fixtures.scenario.captionStatus = 503;
    await page.reload();
    await waitForPlayer(page);
    await waitForFixtureCount(fixtures, '/subtitles-ru.vtt', failedCaptionBaseline + 1);
    await page.locator('track[data-player-fixture-track]').evaluate((track) => {
        track.dispatchEvent(new Event('error'));
    });
    await expect(page.locator('[data-player-caption-status]')).toBeVisible();
    await expect(page.locator('[data-player-caption-status]')).toHaveText(copy.runtime.captionsUnavailable);
    await expect(currentVideo(page)).toBeEnabled();
    fixtures.scenario.captionStatus = 200;

    const retryManifestBaseline = fixtures.count('/valid.m3u8');

    fixtures.scenario.manifestStatuses.push(503);
    await page.reload();
    await waitForPlayer(page);
    await expect(page.locator('[data-player-shell]')).toHaveAttribute('data-player-state', 'retrying');
    await expect(page.locator('[data-player-status-text]')).toHaveText(copy.runtime.retryingNetwork);
    await waitForFixtureCount(fixtures, '/valid.m3u8', retryManifestBaseline + 2);

    const expiredManifestBaseline = fixtures.count('/valid.m3u8');

    fixtures.scenario.manifestStatuses.push(503, 410);
    await page.reload();
    await waitForPlayer(page);
    await expect(page.locator('[data-player-shell]')).toHaveAttribute('data-player-state', 'expired');
    await expect(page.locator('[data-player-status-text]')).toHaveText(copy.runtime.expired);
    await expect(page.locator('[data-player-retry]')).toBeVisible();
    await page.waitForTimeout(1_500);
    expect(fixtures.count('/valid.m3u8')).toBe(expiredManifestBaseline + 2);

    const fallbackManifestBaseline = fixtures.count('/valid.m3u8');

    fixtures.scenario.manifestStatuses.push(503, 503);
    await page.reload();
    await waitForPlayer(page);
    await waitForFixtureCount(fixtures, '/valid.m3u8', fallbackManifestBaseline + 2);
    await waitForFixtureCount(fixtures, '/direct.mp4', 1);
    await expect(page.locator('[data-player-shell]')).toHaveAttribute('data-player-state', 'ready');
    await expect(page).toHaveURL(/format=mp4/);

    const hlsUrl = await page.locator('[data-player-media-format="m3u8"]').getAttribute('href');

    await page.goto(hlsUrl);
    await expect(page).toHaveURL(/format=m3u8/);
    await waitForPlayer(page);

    const corruptSegmentBaseline = fixtures.count('/hls-segment.m4s');

    fixtures.scenario.segmentBodies.push('corrupt');
    await page.reload();
    await waitForPlayer(page);
    await waitForFixtureCount(fixtures, '/hls-segment.m4s', corruptSegmentBaseline + 2);

    const recoveredSegments = fixtures.observations
        .filter(({ path }) => path.endsWith('/hls-segment.m4s'))
        .slice(corruptSegmentBaseline, corruptSegmentBaseline + 2);

    expect(recoveredSegments.map(({ bodyVariant }) => bodyVariant)).toEqual(['corrupt', 'valid']);
    await expect(page.locator('[data-player-shell]')).toHaveAttribute('data-player-state', 'ready');

    await selectPlayerMediaFormat(page, 'mp4');
    await waitForPlayer(page);
    await expect.poll(() => fixtures.observations.some((observation) => (
        observation.path.endsWith('/direct.mp4')
        && observation.range !== null
        && observation.status === 206
    ))).toBe(true);

    const rangeObservation = fixtures.observations.find((observation) => (
        observation.path.endsWith('/direct.mp4') && observation.status === 206
    ));

    expect(rangeObservation.range).toMatch(/^bytes=\d+-\d*$/);
    await assertResponsivePlayer(page);
    assertNoBrowserErrors(errors);
});
