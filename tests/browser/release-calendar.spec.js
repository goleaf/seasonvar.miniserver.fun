import { expect, test } from '@playwright/test';

const renderedReleaseTimes = async (page) => page.locator(
    '[data-pagination-region="release-calendar-results"] time[datetime]',
).evaluateAll((times) => times.map((time) => Date.parse(time.getAttribute('datetime'))));

const login = async (page) => {
    await page.goto('/login');
    await page.getByLabel('Электронная почта').fill('browser@example.com');
    await page.getByLabel('Пароль', { exact: true }).fill('Browser-Strong-Password-42!');
    await page.getByRole('button', { name: 'Войти' }).click();
    await expect(page).toHaveURL(/\/library(?:\?|$)/);
};

test('recent calendar defaults to newest first and keeps explicit earliest sorting', async ({ page }) => {
    const pageErrors = [];

    page.on('pageerror', (error) => pageErrors.push(error.message));

    await page.goto('/calendar');

    const sort = page.locator('select[wire\\:change^="changeSort"]');

    await expect(sort).toHaveValue('latest');
    await expect.poll(async () => (await renderedReleaseTimes(page)).length).toBeGreaterThanOrEqual(2);

    const latestFirst = await renderedReleaseTimes(page);

    expect(latestFirst).toEqual([...latestFirst].sort((left, right) => right - left));
    expect(new URL(page.url()).searchParams.has('sort')).toBe(false);

    const nextPage = page.locator(
        '[data-pagination-region="release-calendar-results"] a[rel="next"]',
    );

    await expect(nextPage).toBeVisible();
    await nextPage.click();
    await expect.poll(() => new URL(page.url()).searchParams.get('calendarPage')).toBe('2');

    await sort.selectOption('earliest');
    await expect.poll(() => new URL(page.url()).searchParams.get('sort')).toBe('earliest');
    await expect.poll(() => new URL(page.url()).searchParams.has('calendarPage')).toBe(false);
    await expect.poll(async () => {
        const times = await renderedReleaseTimes(page);

        return times.every((time, index) => index === 0 || times[index - 1] <= time);
    }).toBe(true);

    await sort.selectOption('latest');
    await expect.poll(() => new URL(page.url()).searchParams.has('sort')).toBe(false);
    await expect.poll(async () => {
        const times = await renderedReleaseTimes(page);

        return times.every((time, index) => index === 0 || times[index - 1] >= time);
    }).toBe(true);

    expect(await page.evaluate(
        () => document.documentElement.scrollWidth - window.innerWidth,
    )).toBeLessThanOrEqual(1);
    expect(pageErrors).toEqual([]);
});

test('private calendar subscription manager is responsive and exposes working provider actions', async ({
    page,
    context,
    baseURL,
}, testInfo) => {
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
    await context.grantPermissions(['clipboard-read', 'clipboard-write'], {
        origin: localOrigin,
    });
    await login(page);

    const response = await page.goto('/calendar/mine');
    expect(response?.status()).toBe(200);

    const manager = page.locator('[data-calendar-feed-manager]');
    await expect(manager).toBeVisible();
    await expect(manager.getByRole('heading', { level: 2, name: 'Календарь в вашем приложении' })).toBeVisible();
    await manager.getByRole('button', { name: 'Создать приватную подписку' }).click();
    await expect(manager.getByText('Приватная подписка создана.')).toBeVisible();

    const feed = manager.locator('li[wire\\:key^="calendar-feed-"]').first();
    const privateUrl = await feed.locator('input[readonly]').inputValue();

    expect(privateUrl).toMatch(/^http:\/\/127\.0\.0\.1:\d+\/calendar\/feed\/[A-Za-z0-9_-]{64}\.ics$/);
    await expect(feed.locator('[data-calendar-google]')).toHaveAttribute(
        'href',
        'https://calendar.google.com/calendar/u/0/r/settings/addbyurl',
    );
    await expect(feed.locator('[data-calendar-google]')).toHaveAttribute('rel', 'noopener noreferrer');
    await expect(feed.getByRole('link', { name: 'Добавить в Apple Calendar' })).toHaveAttribute(
        'href',
        /^webcal:\/\/127\.0\.0\.1:\d+\/calendar\/feed\/[A-Za-z0-9_-]{64}\.ics$/,
    );

    await feed.getByRole('button', { name: 'Скопировать ICS-ссылку' }).click();
    await expect(feed.getByText('ICS-ссылка скопирована')).toBeVisible();
    expect(await page.evaluate(() => navigator.clipboard.readText())).toBe(privateUrl);
    expect(await page.evaluate(
        () => document.documentElement.scrollWidth - window.innerWidth,
    )).toBeLessThanOrEqual(1);
    expect(errors).toEqual([]);

    const viewport = page.viewportSize();
    await page.screenshot({
        path: `output/playwright/calendar-feed-${testInfo.project.name.toLowerCase().replaceAll(' ', '-')}-${viewport.width}x${viewport.height}.png`,
        fullPage: true,
    });
});
