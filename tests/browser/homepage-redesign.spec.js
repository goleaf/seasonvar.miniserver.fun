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

const verifyRedesignedHome = async (page) => {
    await expect(page.locator('[data-home-page]')).toBeVisible();

    for (const surface of ['amber', 'sky', 'emerald', 'slate']) {
        await expect(page.locator(`[data-home-surface="${surface}"]`).first()).toBeVisible();
    }

    const sectionActions = page.locator('[data-home-section-action]:visible');
    expect(await sectionActions.count()).toBeGreaterThan(0);

    const undersizedActions = await sectionActions.evaluateAll((links) => links
        .filter((link) => link.getBoundingClientRect().height < 44)
        .map((link) => ({
            href: link.getAttribute('href'),
            height: link.getBoundingClientRect().height,
        })));

    expect(undersizedActions).toEqual([]);

    const hasHorizontalOverflow = await page.evaluate(
        () => document.documentElement.scrollWidth > document.documentElement.clientWidth,
    );

    expect(hasHorizontalOverflow).toBe(false);
};

const clickPoster = async (page, section, title = null) => {
    const sectionCards = page.locator(`[data-home-section="${section}"] [data-ui-poster-card]`);
    const card = title === null
        ? sectionCards.first()
        : sectionCards.filter({ hasText: title }).first();
    const poster = card.locator('[data-ui-poster-card-media]');
    const titleLink = card.locator('[data-home-title-link]');

    await expect(card).toBeVisible();
    await expect(titleLink).toHaveCount(1);
    await expect(poster).toBeVisible();
    await poster.evaluate((element) => element.scrollIntoView({ block: 'center' }));

    const expectedHref = await titleLink.getAttribute('href');
    const targetPoint = await poster.evaluate((element) => {
        const rect = element.getBoundingClientRect();
        const link = element
            .closest('[data-ui-poster-card]')
            ?.querySelector('[data-home-title-link]');
        const xCandidates = [0.15, 0.5, 0.85]
            .map((ratio) => rect.left + (rect.width * ratio))
            .filter((x) => x >= 0 && x < window.innerWidth);
        const visibleTop = Math.max(rect.top, 0);
        const visibleBottom = Math.min(rect.bottom, window.innerHeight);

        for (let y = visibleTop + 8; y < visibleBottom - 8; y += 16) {
            for (const x of xCandidates) {
                const hit = document.elementFromPoint(x, y);

                if (hit?.closest('[data-home-title-link]') === link) {
                    return { x, y };
                }
            }
        }

        return null;
    });

    expect(targetPoint).not.toBeNull();

    await page.mouse.click(targetPoint.x, targetPoint.y);

    return expectedHref;
};

test('guest can open a title by pressing its homepage poster', async ({ page }, testInfo) => {
    const runtimeErrors = observeRuntimeErrors(page);

    await page.goto('/');
    await verifyRedesignedHome(page);
    await page.screenshot({ path: testInfo.outputPath('homepage-redesign-guest.png'), fullPage: true });
    expect(runtimeErrors).toEqual([]);

    const expectedHref = await clickPoster(page, 'watch-now');
    await expect(page).toHaveURL(new URL(expectedHref, page.url()).href);
});

test('authenticated poster keeps the exact continue-watching destination', async ({ page }, testInfo) => {
    const runtimeErrors = observeRuntimeErrors(page);

    await login(page);
    await verifyRedesignedHome(page);
    await page.screenshot({ path: testInfo.outputPath('homepage-redesign-authenticated.png'), fullPage: true });
    expect(runtimeErrors).toEqual([]);

    await clickPoster(page, 'continue-watching', 'Browser Smoke');
    await expect(page).toHaveURL(/\/titles\/browser-smoke\?episode=\d+#player$/);
});

test('localized homepage keeps the redesigned responsive surfaces', async ({ page }, testInfo) => {
    const runtimeErrors = observeRuntimeErrors(page);

    await page.goto('/en');
    await verifyRedesignedHome(page);
    await expect(page.locator('[data-home-title-link]').first()).toBeVisible();
    await page.screenshot({ path: testInfo.outputPath('homepage-redesign-en.png'), fullPage: true });

    expect(runtimeErrors).toEqual([]);
});
