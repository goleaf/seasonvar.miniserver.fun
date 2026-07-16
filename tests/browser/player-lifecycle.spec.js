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
        if (message.type() === 'error' && !message.text().startsWith('Failed to load resource:')) {
            consoleErrors.push(message.text());
        }
    });
    page.on('pageerror', (error) => pageErrors.push(error.message));

    return { sameOriginFailures, externalLeaks, consoleErrors, pageErrors };
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

for (const locale of [
    { code: 'ru', prefix: '' },
    { code: 'en', prefix: '/en' },
]) {
    test(`player keeps one localized session through lifecycle transitions (${locale.code})`, async ({ page, baseURL }) => {
        test.setTimeout(90_000);

        const errors = await installBrowserGuard(page, baseURL);
        const fixtures = await installPlayerMediaFixtures(page);

        await login(page, locale.prefix);
        await page.goto('/titles/browser-smoke?format=m3u8');

        const initialSession = await waitForPlayer(page);
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
        await expect(page).toHaveURL(/\/titles\/browser-smoke\?format=m3u8$/);
        await waitForPlayer(page);
        await expect(currentVideo(page)).not.toHaveAttribute('data-lifecycle-identity', 'preserved');

        await page.goForward();
        await expect(page).toHaveURL(/\/titles$/);
        await page.goBack();
        await waitForPlayer(page);

        const mp4Option = page.locator('[data-player-media-format="mp4"]');

        await mp4Option.click();
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

            return events.map(({ eventSequence, positionSeconds, reason }) => ({
                eventSequence,
                positionSeconds,
                reason,
            }));
        });

        expect(progress).toEqual([
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
    await page.goto('/titles/browser-smoke?format=m3u8');
    await waitForPlayer(page);

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

    const manualRetryBaseline = fixtures.count('/valid.m3u8');

    fixtures.scenario.manifestStatuses.push(503, 503);
    await page.reload();
    await waitForPlayer(page);
    await expect(page.locator('[data-player-shell]')).toHaveAttribute('data-player-state', 'error');
    await expect(page.locator('[data-player-retry]')).toBeVisible();
    const failedManifestCount = fixtures.count('/valid.m3u8');

    expect(failedManifestCount).toBe(manualRetryBaseline + 2);
    await page.locator('[data-player-retry]').click();
    await waitForFixtureCount(fixtures, '/valid.m3u8', failedManifestCount + 1);
    await page.waitForTimeout(1_500);
    expect(fixtures.count('/valid.m3u8')).toBe(failedManifestCount + 1);

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

    await page.locator('[data-player-media-format="mp4"]').click();
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
