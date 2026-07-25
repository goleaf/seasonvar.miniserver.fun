import { expect, test } from '@playwright/test';

const installBrowserGuard = (page, baseURL) => {
    const errors = [];
    const localOrigin = new URL(baseURL).origin;

    page.on('console', (message) => {
        if (message.type() === 'error' && !message.text().startsWith('Failed to load resource:')) {
            errors.push(`console: ${message.text()}`);
        }
    });
    page.on('pageerror', (error) => errors.push(`page: ${error.message}`));
    page.on('response', (response) => {
        if (new URL(response.url()).origin === localOrigin && response.status() >= 400) {
            errors.push(`${response.status()} ${response.url()}`);
        }
    });

    return errors;
};

const assertResponsivePage = async (page) => {
    expect(await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth)).toBeLessThanOrEqual(1);
};

const login = async (page, email) => {
    await page.goto('/ru/login');
    await page.getByLabel('Электронная почта').fill(email);
    await page.getByLabel('Пароль', { exact: true }).fill('Browser-Strong-Password-42!');
    await page.getByRole('button', { name: 'Войти' }).click();
    await expect(page).toHaveURL(/\/library(?:\?|$)/);
};

test('discovery and collection taxonomy stay text-only and responsive', async ({ page, baseURL }, testInfo) => {
    const browserErrors = installBrowserGuard(page, baseURL);
    const popularResponse = await page.goto('/discover/popular');

    expect(popularResponse?.status()).toBe(200);
    await expect(page.getByRole('heading', { level: 1, name: 'Популярные' })).toBeVisible();
    await expect(page.locator('[data-discovery-type-link]')).toHaveCount(9);
    await expect(page.locator('[data-discovery-title-results]')).toBeVisible();
    await expect(page.locator('[data-discovery-collection-results]')).toBeVisible();
    await expect(page.locator('[data-discovery-filters]')).not.toHaveAttribute('open', '');
    await expect(page.getByRole('heading', { level: 2, name: 'Подборки сериалов' })).toBeVisible();
    await expect(page.locator('[data-collection-explorer] img')).toHaveCount(0);
    await expect(page.getByText('Браузерная подборка детективов', { exact: true })).toBeVisible();

    if ((page.viewportSize()?.width ?? 0) < 640) {
        const recommendationTitleBox = await page.locator('[data-recommendation-row] h3').first().boundingBox();

        expect(recommendationTitleBox?.width).toBeGreaterThanOrEqual(150);
    }

    if ((page.viewportSize()?.width ?? 0) >= 640 && (page.viewportSize()?.width ?? 0) < 1024) {
        const recommendationMetaBox = await page
            .locator('[data-recommendation-row]')
            .nth(1)
            .getByText('материал каталога', { exact: true })
            .boundingBox();

        expect(recommendationMetaBox?.height).toBeLessThanOrEqual(20);
    }

    if ((page.viewportSize()?.width ?? 0) >= 1024) {
        await page.getByRole('button', { name: /Темы и жанры/ }).click();
        await page.getByRole('button', { name: /Детективы и криминал/ }).click();
    } else {
        await page.locator('#collection-explorer-category').selectOption('themes-and-genres');
        await page.locator('#collection-explorer-subcategory').selectOption('detective-and-crime');
    }

    await expect(page).toHaveURL(/collections_category=themes-and-genres/);
    await expect(page).toHaveURL(/collections_subcategory=detective-and-crime/);
    await expect(page.getByText('Браузерная подборка детективов', { exact: true })).toBeVisible();
    await assertResponsivePage(page);

    const refreshBox = await page.locator('[data-discovery-refresh-secondary]').boundingBox();

    expect(refreshBox?.height).toBeGreaterThanOrEqual(44);
    await page.screenshot({
        path: `output/playwright/discovery-popular-${testInfo.project.name.toLowerCase().replaceAll(' ', '-')}.png`,
        fullPage: true,
    });

    const randomResponse = await page.goto('/discover/random');

    expect(randomResponse?.status()).toBe(200);
    await expect(page.getByRole('heading', { level: 1, name: 'Случайная находка' })).toBeVisible();
    await expect(page.locator('[data-discovery-collection-results]')).toHaveCount(0);
    await assertResponsivePage(page);

    const englishResponse = await page.goto('/en/discover/popular');

    expect(englishResponse?.status()).toBe(200);
    await expect(page.getByRole('heading', { level: 1, name: 'Popular' })).toBeVisible();
    await expect(page.getByRole('heading', { level: 2, name: 'Series collections' })).toBeVisible();
    await assertResponsivePage(page);

    await login(page, 'browser@example.com');
    await page.goto('/collections/browser-detective-collection');
    await expect(page.getByRole('heading', { level: 1, name: 'Браузерная подборка детективов' })).toBeVisible();
    await expect(page.getByText('Темы и жанры › Детективы и криминал', { exact: true })).toBeVisible();
    await expect(page.locator('article').first().locator('img')).toHaveCount(0);
    await page.getByRole('link', { name: 'Управлять' }).click();
    await expect(page.locator('#collection-edit-category-root option:checked')).toHaveText('Темы и жанры');
    await expect(page.locator('#collection-edit-category-child option:checked')).toHaveText('Детективы и криминал');
    await assertResponsivePage(page);

    await page.getByRole('button', { name: 'Выйти', exact: true }).click();
    await expect(page).toHaveURL(/\/(?:ru\/?)?$/);
    await login(page, 'browser-admin@example.com');
    await page.goto('/admin/catalog?section=collections');
    await expect(page.getByText('Категории и подкатегории', { exact: true })).toBeVisible();
    await expect(page.locator('[data-category-create-form]')).toBeVisible();
    await expect(page.getByText('Детективы и криминал', { exact: true })).toBeVisible();
    await assertResponsivePage(page);

    expect(browserErrors).toEqual([]);
});
