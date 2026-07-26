import { expect, test } from '@playwright/test';
import { mkdirSync } from 'node:fs';
import { installPlayerMediaFixtures } from './support/player-media-fixtures.js';

const loginPassword = 'Browser-Strong-Password-42!';
const artifactDirectory = 'output/playwright/task87-playback-quality';

const login = async (page, email) => {
    await page.goto('/login');
    await page.locator('input[type="email"]').fill(email);
    await page.locator('input[type="password"]').fill(loginPassword);
    await page.locator('form')
        .filter({ has: page.locator('input[type="email"]') })
        .locator('button[type="submit"]')
        .click();
    await expect(page).toHaveURL(/\/library(?:\?|$)/);
};

const waitForPlayer = async (page) => {
    await expect(page.locator('video.js-catalog-player')).toHaveCount(1);
    await expect(page.locator('video.js-catalog-player')).toHaveAttribute('data-player-ready', '1');
};

const assertNoHorizontalOverflow = async (page) => {
    const overflow = await page.evaluate(
        () => document.documentElement.scrollWidth - window.innerWidth,
    );

    expect(overflow).toBeLessThanOrEqual(1);
};

test.beforeAll(() => {
    mkdirSync(artifactDirectory, { recursive: true });
});

test.afterEach(async ({ page }) => {
    await page.unrouteAll({ behavior: 'ignoreErrors' });
});

test('report action runs one safe probe and previews revocable diagnostics', async ({ page }, testInfo) => {
    test.skip(testInfo.project.name === 'Tablet Chromium', 'Desktop and narrow mobile cover the responsive report boundary.');
    test.setTimeout(90_000);

    const consoleErrors = [];
    const pageErrors = [];
    const networkTests = [];
    const reports = [];

    page.on('console', (message) => {
        if (message.type() === 'error' && !message.text().startsWith('Failed to load resource:')) {
            consoleErrors.push(message.text());
        }
    });
    page.on('pageerror', (error) => pageErrors.push(error.message));
    page.on('request', (request) => {
        const path = new URL(request.url()).pathname;

        if (path === '/playback/network-test') {
            networkTests.push(request.url());
        }

        if (path === '/playback/quality' && request.method() === 'POST') {
            const payload = request.postDataJSON();

            if (payload.event === 'report') {
                reports.push(payload);
            }
        }
    });

    await installPlayerMediaFixtures(page);
    await login(page, 'browser@example.com');
    await page.goto('/titles/browser-smoke?episode=1&format=m3u8');
    await waitForPlayer(page);

    const report = page.locator('[data-player-quality-report]');
    const reportBox = await report.boundingBox();

    await expect(report).toHaveText('Видео не работает');
    expect(reportBox?.height ?? 0).toBeGreaterThanOrEqual(44);
    await report.dblclick();
    await expect(page).toHaveURL(/\/issues\/new\?/);
    await expect(page.locator('#playback-diagnostic-preview')).toBeVisible();
    await expect(page.locator('[data-technical-issue-consent]')).toBeChecked();
    await expect(page.getByText('Адрес видео, IP и полный User-Agent не передаются.')).toBeVisible();
    await expect(page.getByText('1080p')).toBeVisible();

    const playbackPreview = page.locator('section[aria-labelledby="playback-diagnostic-preview"]');
    const requestId = await playbackPreview.locator('dd').first().textContent();

    expect(requestId?.trim()).toMatch(/^[0-9a-f-]{36}$/);
    expect(networkTests).toHaveLength(1);
    expect(reports).toHaveLength(1);
    expect(reports[0].network_test_status).toBe('ok');
    expect(reports[0]).not.toHaveProperty('user_id');
    expect(reports[0]).not.toHaveProperty('provider_code');
    expect(reports[0]).not.toHaveProperty('source_url');
    expect(reports[0]).not.toHaveProperty('user_agent');
    await assertNoHorizontalOverflow(page);

    await page.screenshot({
        path: `${artifactDirectory}/${testInfo.project.name.toLowerCase().replaceAll(' ', '-')}-report.png`,
        fullPage: true,
    });

    await page.context().clearCookies();
    await login(page, 'browser-admin@example.com');
    await page.goto('/admin/issues?qualityPeriod=30');
    await expect(page.getByRole('heading', { name: 'Качество просмотра' })).toBeVisible();
    await expect(page.locator('#quality-period')).toHaveValue('30');
    await expect(page.getByText('Среднее время запуска')).toBeVisible();
    await expect(page.getByText('Доля буферизации')).toBeVisible();
    await expect(page.getByText('Ошибки по браузеру')).toBeVisible();
    await expect(page.locator('body')).not.toContainText(requestId.trim());
    await assertNoHorizontalOverflow(page);

    await page.screenshot({
        path: `${artifactDirectory}/${testInfo.project.name.toLowerCase().replaceAll(' ', '-')}-admin.png`,
        fullPage: true,
    });

    expect(consoleErrors).toEqual([]);
    expect(pageErrors).toEqual([]);
});

test('failed primary source exposes both fallback stages and one telemetry identity', async ({ page }, testInfo) => {
    test.skip(testInfo.project.name !== 'Desktop Chromium', 'The deterministic source-failure lifecycle runs once.');
    test.setTimeout(90_000);

    await page.addInitScript(() => {
        window.__playbackQualityStatusLog = [];

        const record = () => {
            for (const selector of ['[data-player-status-text]', '[data-player-notice]']) {
                const element = document.querySelector(selector);
                const text = element?.textContent?.trim();

                if (text && window.__playbackQualityStatusLog.at(-1) !== text) {
                    window.__playbackQualityStatusLog.push(text);
                }
            }
        };

        document.addEventListener('DOMContentLoaded', () => {
            record();
            new MutationObserver(record).observe(document.documentElement, {
                childList: true,
                subtree: true,
                characterData: true,
                attributes: true,
                attributeFilter: ['hidden'],
            });
        });
    });

    const qualityEvents = [];

    page.on('request', (request) => {
        if (new URL(request.url()).pathname !== '/playback/quality' || request.method() !== 'POST') {
            return;
        }

        const payload = request.postDataJSON();

        if (['error', 'fallback'].includes(payload.event)) {
            qualityEvents.push(payload);
        }
    });

    const fixtures = await installPlayerMediaFixtures(page);

    await login(page, 'browser@example.com');
    await page.goto('/titles/browser-smoke?episode=1&format=m3u8');
    await waitForPlayer(page);

    fixtures.scenario.manifestStatuses.push(503, 503);
    await page.reload();
    await waitForPlayer(page);
    await expect(page).toHaveURL(/format=mp4/);
    await expect.poll(() => qualityEvents.map(({ event }) => event)).toEqual(['error', 'fallback']);

    const statuses = await page.evaluate(() => window.__playbackQualityStatusLog);

    expect(statuses).toContain('Источник 1 не ответил.');
    expect(statuses).toContain('Переключаю на источник 2…');
    expect(new Set(qualityEvents.map(({ request_id: requestId }) => requestId)).size).toBe(1);

    for (const payload of qualityEvents) {
        expect(payload).not.toHaveProperty('source_url');
        expect(payload).not.toHaveProperty('provider_code');
        expect(payload).not.toHaveProperty('user_id');
    }
});
