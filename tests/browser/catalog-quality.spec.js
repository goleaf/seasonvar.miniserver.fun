import { execFileSync } from 'node:child_process';
import path from 'node:path';
import { expect, test } from '@playwright/test';

const runtimeName = process.env.PLAYWRIGHT_RUNTIME_NAME || 'browser';
const databasePath = path.resolve(`output/playwright/${runtimeName}.sqlite`);
const configCachePath = path.resolve(`output/playwright/${runtimeName}-config.php`);
const routesCachePath = path.resolve(`output/playwright/${runtimeName}-routes-v7.php`);

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

const login = async (page) => {
    await page.goto('/ru/login');
    await page.getByLabel('Электронная почта').fill('browser-admin@example.com');
    await page.getByLabel('Пароль', { exact: true }).fill('Browser-Strong-Password-42!');
    await page.getByRole('button', { name: 'Войти' }).click();
    await expect(page).toHaveURL(/\/library(?:\?|$)/);
};

test.beforeAll(() => {
    execFileSync('php', ['artisan', 'catalog:quality-refresh', '--limit=1000'], {
        cwd: path.resolve('.'),
        env: {
            ...process.env,
            APP_ENV: 'testing',
            APP_DEBUG: 'false',
            APP_KEY: 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=',
            APP_CONFIG_CACHE: configCachePath,
            APP_ROUTES_CACHE: routesCachePath,
            CACHE_DOMAIN_STORE: 'array',
            CACHE_HOT_STORE: 'array',
            CACHE_LOCK_STORE: 'array',
            CACHE_METRICS_STORE: 'array',
            CACHE_STORE: 'array',
            CACHE_VERSION_STORE: 'array',
            DB_CONNECTION: 'sqlite',
            DB_DATABASE: databasePath,
            MAIL_MAILER: 'array',
            QUEUE_CONNECTION: 'sync',
            SESSION_DRIVER: 'database',
        },
        stdio: 'pipe',
    });
});

test('catalog quality center is responsive, explainable and keeps filter state', async ({
    page,
    baseURL,
}, testInfo) => {
    const browserErrors = installBrowserGuard(page, baseURL);

    await login(page);

    const response = await page.goto('/admin/catalog/quality');

    expect(response?.status()).toBe(200);
    await expect(page.getByRole('heading', { level: 1, name: 'Центр качества каталога' })).toBeVisible();
    await expect(page.locator('[data-catalog-quality-center]')).toBeVisible();
    await expect(page.getByRole('button', { name: /Критические ошибки/ })).toBeVisible();
    await expect(page.getByRole('button', { name: /Подозрительные теги/ })).toBeVisible();
    await expect(page.getByRole('button', { name: /Конфликты данных/ })).toBeVisible();
    await expect(page.getByRole('button', { name: /Без постера/ })).toBeVisible();
    await expect(page.getByRole('button', { name: /Без видео/ })).toBeVisible();
    await expect(page.getByRole('button', { name: /Подозрительные серии/ })).toBeVisible();
    await expect(page.getByRole('button', { name: /Давно не проверялись/ })).toBeVisible();
    await expect(page.locator('article').first()).toBeVisible();
    await expect(page.locator('[aria-label^="Оценка качества:"]').first()).toBeVisible();

    const search = page.getByRole('searchbox', { name: 'Поиск', exact: true });

    await search.fill('Browser Smoke');
    await expect(page).toHaveURL(/quality_q=Browser(?:\+|%20)Smoke/);
    await expect(page.getByRole('heading', { level: 2, name: 'Browser Smoke' })).toBeVisible();

    await page.getByRole('button', { name: 'Сбросить фильтры' }).click();
    await expect(page).not.toHaveURL(/quality_queue=|quality_q=/);

    await page.getByRole('button', { name: /Без видео/ }).click();
    await expect(page).toHaveURL(/quality_queue=missing_video/);
    await expect(page.locator('article').first()).toBeVisible();

    await page.getByRole('button', { name: 'Сбросить фильтры' }).click();
    await expect(page).not.toHaveURL(/quality_queue=|quality_q=/);

    const resetBox = await page.getByRole('button', { name: 'Сбросить фильтры' }).boundingBox();

    expect(resetBox?.height).toBeGreaterThanOrEqual(44);
    expect(await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth)).toBeLessThanOrEqual(1);
    expect(browserErrors).toEqual([]);

    await page.screenshot({
        path: `output/playwright/catalog-quality-${testInfo.project.name.toLowerCase().replaceAll(' ', '-')}.png`,
        fullPage: true,
    });
});
