import { expect, test } from '@playwright/test';

test('catalog card surfaces never render the redundant open-title action', async ({ page, baseURL }) => {
    const browserErrors = [];
    const localOrigin = new URL(baseURL).origin;

    page.on('console', (message) => {
        if (message.type() === 'error' && !message.text().startsWith('Failed to load resource:')) {
            browserErrors.push(`console: ${message.text()}`);
        }
    });
    page.on('pageerror', (error) => browserErrors.push(`page: ${error.message}`));
    page.on('response', (response) => {
        if (new URL(response.url()).origin === localOrigin && response.status() >= 400) {
            browserErrors.push(`${response.status()} ${response.url()}`);
        }
    });

    for (const path of ['/', '/titles?q=Browser%20Smoke', '/titles/browser-smoke']) {
        const response = await page.goto(path);

        expect(response?.status()).toBe(200);
        await expect(page.getByText('Открыть тайтл', { exact: true })).toHaveCount(0);
        expect(await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth)).toBeLessThanOrEqual(1);
    }

    await page.goto('/titles/browser-smoke');
    const recommendationRows = page.locator('[data-recommendation-list] [data-recommendation-row]');

    await expect(recommendationRows).toHaveCount(1);
    await expect(recommendationRows.getByRole('link', { name: 'Рекомендованный браузерный сериал' })).toBeVisible();
    await expect(recommendationRows.getByText('Похожие жанры и темы', { exact: true })).toBeVisible();
    const recommendationHrefs = await recommendationRows.locator('a[href*="/titles/"]').evaluateAll(
        (links) => links.map((link) => link.getAttribute('href')),
    );

    expect(recommendationHrefs).not.toContain(`${baseURL}/titles/browser-smoke`);
    expect(new Set(recommendationHrefs).size).toBe(recommendationHrefs.length);

    await page.goto('/login');
    await page.getByLabel('Электронная почта').fill('browser@example.com');
    await page.getByLabel('Пароль', { exact: true }).fill('Browser-Strong-Password-42!');
    await page.getByRole('button', { name: 'Войти' }).click();
    await expect(page).toHaveURL(/\/library(?:\/|$)/);

    const personalizedResponse = await page.goto('/discover/personalized');
    const personalizedRows = page.locator('[data-recommendation-list] [data-recommendation-row]');

    expect(personalizedResponse?.status()).toBe(200);
    await expect(personalizedRows.getByRole('link', { name: 'Рекомендованный браузерный сериал' })).toBeVisible();
    await expect(personalizedRows.getByText('По сериалам из вашего списка', { exact: true })).toBeVisible();
    await expect(page.getByText('Открыть тайтл', { exact: true })).toHaveCount(0);
    expect(await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth)).toBeLessThanOrEqual(1);

    const response = await page.goto('/library/watchlist');

    expect(response?.status()).toBe(200);
    await expect(page.locator('[data-library-watchlist-list]')).toBeVisible();
    await expect(page.locator('[data-user-card-state]')).toBeVisible();
    await expect(page.getByText('Открыть тайтл', { exact: true })).toHaveCount(0);
    expect(await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth)).toBeLessThanOrEqual(1);

    const continueResponse = await page.goto('/library/continue-watching');

    expect(continueResponse?.status()).toBe(200);
    await expect(page.locator('[data-library-continue-list]')).toBeVisible();
    await expect(page.getByRole('link', { name: /^Продолжить/ })).toBeVisible();
    await expect(page.getByText('Открыть тайтл', { exact: true })).toHaveCount(0);
    expect(await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth)).toBeLessThanOrEqual(1);
    expect(browserErrors).toEqual([]);
});
