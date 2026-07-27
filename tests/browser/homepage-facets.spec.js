import { expect, test } from '@playwright/test';

const login = async (page) => {
    await page.goto('/login');
    await page.getByLabel('Электронная почта').fill('browser@example.com');
    await page.getByLabel('Пароль', { exact: true }).fill('Browser-Strong-Password-42!');
    await page.getByRole('button', { name: 'Войти' }).click();
    await expect(page).toHaveURL('/library');
    await page.goto('/');
};

const observeRuntimeErrors = (page) => {
    const errors = [];

    page.on('console', (message) => {
        if (message.type() === 'error' && !message.text().startsWith('Failed to load resource:')) {
            errors.push(`console: ${message.text()}`);
        }
    });
    page.on('pageerror', (error) => errors.push(`page: ${error.message}`));
    page.on('response', (response) => {
        const responseUrl = new URL(response.url());
        const isOptionalPosterMiss = response.status() === 404
            && responseUrl.pathname.startsWith('/pwa/posters/');

        if (response.status() >= 400 && !isOptionalPosterMiss) {
            errors.push(`response: ${response.status()} ${response.url()}`);
        }
    });

    return errors;
};

const verifyCompleteFacets = async (page) => {
    const section = page.locator('[data-home-section="catalog-facets"]');
    const genres = section.locator('[data-home-facet-list="genres"]');
    const countries = section.locator('[data-home-facet-list="countries"]');
    const years = section.locator('[data-home-facet-list="years"]');

    await expect(section).toBeVisible();
    await expect(section.getByRole('heading', { name: 'Поиск по жанрам, странам и годам' })).toBeVisible();
    await expect(genres.getByRole('link')).toHaveCount(22);
    await expect(countries.getByRole('link')).toHaveCount(22);
    await expect(years.getByRole('link', { name: /2000/ })).toBeAttached();
    await expect(years.getByRole('link', { name: /2019/ })).toBeAttached();
    await expect(genres.getByText('Полный жанр 20', { exact: true })).toBeAttached();
    await expect(countries.getByText('Полная страна 20', { exact: true })).toBeAttached();
    await expect(genres).toHaveAttribute('tabindex', '0');
    await expect(countries).toHaveAttribute('tabindex', '0');
    await expect(years).toHaveAttribute('tabindex', '0');

    const hasHorizontalOverflow = await page.evaluate(
        () => document.documentElement.scrollWidth > document.documentElement.clientWidth,
    );

    expect(hasHorizontalOverflow).toBe(false);
};

test('guest homepage exposes every genre, country and valid year', async ({ page }, testInfo) => {
    const runtimeErrors = observeRuntimeErrors(page);

    await page.goto('/');
    await verifyCompleteFacets(page);
    await page.screenshot({ path: testInfo.outputPath('homepage-facets.png'), fullPage: true });

    expect(runtimeErrors).toEqual([]);
});

test('authenticated homepage keeps the complete facet block', async ({ page }, testInfo) => {
    const runtimeErrors = observeRuntimeErrors(page);

    await login(page);
    await verifyCompleteFacets(page);
    await page.screenshot({ path: testInfo.outputPath('homepage-facets.png'), fullPage: true });

    expect(runtimeErrors).toEqual([]);
});
