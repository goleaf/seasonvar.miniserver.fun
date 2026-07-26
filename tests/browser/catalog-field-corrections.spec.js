import { expect, test } from '@playwright/test';
import { installPlayerMediaFixtures } from './support/player-media-fixtures.js';

const correctionFields = [
    'title',
    'year',
    'genre',
    'tag',
    'country',
    'actor',
    'poster',
    'description',
    'translation',
    'episode',
    'subtitles',
];

test('verified users can open a field-specific catalog correction on every viewport', async ({ page, baseURL }, testInfo) => {
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

    for (const field of correctionFields) {
        await expect(page.locator(`[data-correction-field="${field}"]`).first()).toBeVisible();
    }

    const correctionControls = page.locator('[data-correction-field]');
    const undersizedControls = await correctionControls.evaluateAll((controls) => controls
        .filter((control) => control.getClientRects().length > 0)
        .filter((control) => control.getBoundingClientRect().height < 44)
        .map((control) => control.getAttribute('data-correction-field')));

    expect(undersizedControls).toEqual([]);
    expect(await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth)).toBeLessThanOrEqual(1);

    await page.locator('[data-correction-field="tag"]').first().click();
    await expect(page).toHaveURL(/\/requests\/create\?.*field=tag.*target=\d+/);
    await expect(page.locator('#correction-field')).toBeDisabled();
    await expect(page.locator('#correction-field')).toHaveValue('tag');
    await expect(page.locator('#current-value')).toHaveValue('Браузерный тег');
    await expect(page.getByText('Не относится к сериалу', { exact: true })).toBeVisible();
    expect(await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth)).toBeLessThanOrEqual(1);
    await page.screenshot({
        path: testInfo.outputPath('field-correction-form.png'),
        fullPage: true,
    });

    expect(consoleErrors).toEqual([]);
    expect(pageErrors).toEqual([]);
    expect(failedRequests).toEqual([]);
});
