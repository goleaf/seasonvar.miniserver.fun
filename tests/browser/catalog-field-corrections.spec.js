import { expect, test } from '@playwright/test';
import { installPlayerMediaFixtures } from './support/player-media-fixtures.js';

test('catalog corrections are absent from the public frontend and direct access is forbidden', async ({ page, baseURL }, testInfo) => {
    const consoleErrors = [];
    const pageErrors = [];
    const failedRequests = [];

    await installPlayerMediaFixtures(page);

    page.on('console', (message) => {
        if (message.type() === 'error' && ! message.text().startsWith('Failed to load resource:')) {
            consoleErrors.push(message.text());
        }
    });
    page.on('pageerror', (error) => pageErrors.push(error.message));
    page.on('requestfailed', (request) => {
        const url = new URL(request.url());

        if (url.protocol !== 'blob:'
            && url.origin === new URL(baseURL).origin
            && request.failure()?.errorText !== 'net::ERR_ABORTED') {
            failedRequests.push(`${request.failure()?.errorText ?? 'request failed'} ${request.url()}`);
        }
    });

    await page.goto('/login');
    await page.getByLabel('Электронная почта').fill('browser@example.com');
    await page.getByLabel('Пароль', { exact: true }).fill('Browser-Strong-Password-42!');
    await page.getByRole('button', { name: 'Войти' }).click();
    await expect(page).toHaveURL(/\/library(?:\?|$)/);

    await page.goto('/titles/browser-smoke?episode=1');
    await expect(page.getByRole('heading', { level: 1, name: 'Browser Smoke' })).toBeVisible();
    await expect(page.locator('[data-correction-field]')).toHaveCount(0);
    await expect(page.getByText('Исправить данные', { exact: true })).toHaveCount(0);
    expect(await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth)).toBeLessThanOrEqual(1);
    await page.screenshot({
        path: testInfo.outputPath('catalog-without-correction-controls.png'),
        fullPage: true,
    });

    const response = await page.goto('/requests/create?type=metadata_correction');

    expect(response?.status()).toBe(403);
    await expect(page.getByText('403')).toBeVisible();
    expect(consoleErrors).toEqual([]);
    expect(pageErrors).toEqual([]);
    expect(failedRequests).toEqual([]);
});
