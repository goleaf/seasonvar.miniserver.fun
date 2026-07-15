import { expect, test } from '@playwright/test';

const loginEmail = 'browser@example.com';
const loginPassword = 'Browser-Strong-Password-42!';

const isSameOrigin = (requestUrl, baseURL) => (
    new URL(requestUrl).origin === new URL(baseURL).origin
);

const installBrowserGuard = async (page, baseURL) => {
    const sameOriginFailures = [];
    const consoleErrors = [];
    const pageErrors = [];

    await page.route('**/*', async (route) => {
        if (!isSameOrigin(route.request().url(), baseURL)) {
            await route.abort('blockedbyclient');

            return;
        }

        await route.continue();
    });

    page.on('response', (response) => {
        if (isSameOrigin(response.url(), baseURL) && response.status() >= 400) {
            sameOriginFailures.push(`${response.status()} ${response.url()}`);
        }
    });
    page.on('requestfailed', (request) => {
        if (isSameOrigin(request.url(), baseURL)) {
            sameOriginFailures.push(`${request.failure()?.errorText || 'request failed'} ${request.url()}`);
        }
    });
    page.on('console', (message) => {
        if (message.type() === 'error' && !message.text().startsWith('Failed to load resource:')) {
            consoleErrors.push(message.text());
        }
    });
    page.on('pageerror', (error) => pageErrors.push(error.message));

    return { sameOriginFailures, consoleErrors, pageErrors };
};

const assertResponsivePage = async (page) => {
    await expect(page.locator('main')).toHaveCount(1);

    const geometry = await page.evaluate(() => ({
        overflow: document.documentElement.scrollWidth - window.innerWidth,
        bodyOverflowX: window.getComputedStyle(document.body).overflowX,
    }));

    expect(geometry.overflow).toBeLessThanOrEqual(1);
    expect(geometry.bodyOverflowX).not.toBe('scroll');
};

const assertReadableHeaderBrand = async (page) => {
    const brand = page.getByRole('banner').getByRole('link', { name: 'Каталог сериалов' });
    const box = await brand.boundingBox();

    expect(box).not.toBeNull();
    expect(box.width).toBeGreaterThanOrEqual(170);
};

const assertNoBrowserErrors = (browserErrors) => {
    expect(browserErrors.sameOriginFailures).toEqual([]);
    expect(browserErrors.consoleErrors).toEqual([]);
    expect(browserErrors.pageErrors).toEqual([]);
};

test('guest authentication and private library navigation are responsive', async ({ page, baseURL }, testInfo) => {
    const browserErrors = await installBrowserGuard(page, baseURL);

    await page.goto('/login');
    await expect(page.getByRole('heading', { level: 1, name: 'Вход' })).toBeVisible();
    await assertResponsivePage(page);

    await page.getByLabel('Электронная почта').fill(loginEmail);
    await page.getByLabel('Пароль', { exact: true }).fill(loginPassword);
    await page.getByRole('button', { name: 'Войти' }).click();

    await expect(page).toHaveURL(/\/library(?:\?|$)/);
    await expect(page.getByRole('heading', { level: 1, name: 'Моя библиотека' })).toBeVisible();
    await expect(page.getByRole('link', { name: /В списке/ })).toContainText('1');
    await expect(page.getByRole('link', { name: /Продолжить/ })).toContainText('1');
    await assertResponsivePage(page);
    await assertReadableHeaderBrand(page);

    await page.getByRole('link', { name: 'Профиль' }).click();
    await expect(page).toHaveURL(/\/profile$/);
    await expect(page.getByRole('heading', { level: 1, name: 'Профиль' })).toBeVisible();
    await expect(page.getByText('Почта подтверждена', { exact: true })).toBeVisible();
    await expect(page.getByRole('heading', { level: 2, name: 'Моя библиотека' })).toBeVisible();
    await assertResponsivePage(page);

    await page.goto('/library/ratings');
    await expect(page.getByText('Мои оценки', { exact: true })).toBeVisible();
    await page.goto('/library/history');
    await expect(page.getByText('История просмотров', { exact: true })).toBeVisible();
    await expect(page.getByText('Browser Smoke', { exact: true })).toBeVisible();
    await assertResponsivePage(page);

    await page.screenshot({
        path: `output/playwright/auth-library-${testInfo.project.name.toLowerCase().replaceAll(' ', '-')}.png`,
        fullPage: true,
    });

    await page.getByRole('button', { name: 'Выйти из аккаунта' }).click();
    await expect(page).toHaveURL(/\/$/);
    await page.goto('/library');
    await expect(page).toHaveURL(/\/login$/);
    await expect(page.getByRole('heading', { level: 1, name: 'Вход' })).toBeVisible();

    assertNoBrowserErrors(browserErrors);
});

test('verified player exposes saved progress and Continue Watching', async ({ page, baseURL }, testInfo) => {
    const browserErrors = await installBrowserGuard(page, baseURL);

    await page.goto('/login');
    await page.getByLabel('Электронная почта').fill(loginEmail);
    await page.getByLabel('Пароль', { exact: true }).fill(loginPassword);
    await page.getByRole('button', { name: 'Войти' }).click();
    await expect(page).toHaveURL(/\/library(?:\?|$)/);

    await page.goto('/titles/browser-smoke');
    await expect(page.locator('[data-player-shell]')).toBeVisible();

    const player = page.locator('video.js-catalog-player');

    await expect(player).toHaveAttribute('data-progress-enabled', '1');
    await expect(player).toHaveAttribute('data-progress-position', '120');
    await expect(player).toHaveAttribute('data-progress-session', /.+/);
    await assertResponsivePage(page);

    await page.goto('/library/continue-watching');
    await expect(page.getByRole('heading', { level: 2, name: 'Продолжить просмотр' })).toBeVisible();
    await expect(page.getByText('Browser Smoke', { exact: true })).toBeVisible();
    await expect(page.getByLabel('Просмотрено 20%')).toBeVisible();
    await expect(page.getByRole('link', { name: /^Продолжить/ })).toBeVisible();
    await assertResponsivePage(page);

    await page.screenshot({
        path: `output/playwright/auth-player-${testInfo.project.name.toLowerCase().replaceAll(' ', '-')}.png`,
        fullPage: true,
    });

    assertNoBrowserErrors(browserErrors);
});
