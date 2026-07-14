import AxeBuilder from '@axe-core/playwright';
import { expect, test } from '@playwright/test';

const isExternalRequest = (requestUrl, baseURL) => {
    const target = new URL(requestUrl);
    const local = new URL(baseURL);

    return target.origin !== local.origin;
};

const installNetworkGuard = async (page, baseURL) => {
    const localAssetFailures = [];
    const consoleErrors = [];
    const pageErrors = [];

    await page.route('**/*', async (route) => {
        if (isExternalRequest(route.request().url(), baseURL)) {
            await route.abort('blockedbyclient');

            return;
        }

        await route.continue();
    });

    page.on('response', (response) => {
        const request = response.request();
        const resourceType = request.resourceType();

        if (
            new URL(response.url()).origin === new URL(baseURL).origin
            && ['stylesheet', 'script', 'image', 'font'].includes(resourceType)
            && response.status() >= 400
        ) {
            localAssetFailures.push(`${response.status()} ${response.url()}`);
        }
    });
    page.on('requestfailed', (request) => {
        if (new URL(request.url()).origin === new URL(baseURL).origin) {
            localAssetFailures.push(`${request.failure()?.errorText || 'request failed'} ${request.url()}`);
        }
    });
    page.on('console', (message) => {
        if (message.type() === 'error' && !message.text().startsWith('Failed to load resource:')) {
            consoleErrors.push(message.text());
        }
    });
    page.on('pageerror', (error) => pageErrors.push(error.message));

    return { localAssetFailures, consoleErrors, pageErrors };
};

const assertPageGeometry = async (page) => {
    const overflow = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);

    expect(overflow).toBeLessThanOrEqual(1);
};

const assertTouchTargets = async (page) => {
    const undersized = await page.locator([
        '[data-catalog-unified-filters] > summary',
        '[data-catalog-sort-option]',
        '[data-catalog-view-option]',
        '[data-catalog-page-size-option]',
        '[data-catalog-alphabet-option]',
    ].join(',')).evaluateAll((controls) => controls
        .filter((control) => control.getClientRects().length > 0)
        .map((control) => ({
            label: control.getAttribute('aria-label') || control.textContent?.trim() || control.tagName,
            height: control.getBoundingClientRect().height,
            minHeight: window.getComputedStyle(control).getPropertyValue('min-height'),
        }))
        .filter((control) => control.height < 44));

    expect(undersized, 'Controls with min-height contract must be at least 44px tall.').toEqual([]);
};

const assertAccessibility = async (page) => {
    const results = await new AxeBuilder({ page })
        .withTags(['wcag2a', 'wcag2aa'])
        .analyze();
    const blockingViolations = results.violations.filter(
        (violation) => ['critical', 'serious'].includes(violation.impact),
    );

    expect(blockingViolations).toEqual([]);
};

test('catalog keeps URL state, unified filters and responsive geometry', async ({ page, baseURL }) => {
    const browserErrors = await installNetworkGuard(page, baseURL);

    await page.goto('/titles?q=Browser%20Smoke&sort=title_asc');

    await expect(page.getByRole('heading', { level: 1 })).toBeVisible();
    await expect(page.locator('#catalog-search')).toHaveValue('Browser Smoke');
    await expect(page).toHaveURL(/q=Browser(?:%20|\+)Smoke/);
    await expect(page.locator('[data-catalog-card]')).toHaveCount(1);

    const filters = page.locator('#catalog-filters');

    await expect(filters).toHaveAttribute('open', '');
    await expect(page.locator('[data-catalog-filter-groups]')).toBeVisible();
    await expect(page.getByText('Актеры', { exact: true }).first()).toBeVisible();

    await assertPageGeometry(page);
    await assertTouchTargets(page);
    await assertAccessibility(page);
    expect(browserErrors.localAssetFailures).toEqual([]);
    expect(browserErrors.consoleErrors).toEqual([]);
    expect(browserErrors.pageErrors).toEqual([]);
});

test('route country and publication type can be removed independently', async ({ page, baseURL }) => {
    const browserErrors = await installNetworkGuard(page, baseURL);
    const routeState = '/titles/country/rossiia?country%5B0%5D=rossiia&publication_type%5B0%5D=show';

    await page.goto(routeState);
    await expect(page.locator('[data-catalog-filter-groups]')).toBeVisible();

    const country = page.locator('input[type="checkbox"][name="country[]"][value="rossiia"]');
    const publicationType = page.locator('input[type="checkbox"][name="publication_type[]"][value="show"]');

    await expect(country).toBeChecked();
    await expect(publicationType).toBeChecked();
    await publicationType.uncheck();
    await expect(publicationType).not.toBeChecked();
    await expect(country).toBeChecked();
    await expect(page).toHaveURL(/\/titles\/country\/rossiia(?:\?|$)/);
    await expect(page).not.toHaveURL(/publication_type/);

    await page.goto(routeState);
    await expect(page.locator('[data-catalog-filter-groups]')).toBeVisible();
    await expect(country).toBeChecked();
    await expect(publicationType).toBeChecked();
    await country.uncheck();
    await expect(page).toHaveURL(/\/titles\?.*publication_type(?:%5B0%5D|\[0\])=show/);
    await expect(publicationType).toBeChecked();

    expect(browserErrors.localAssetFailures).toEqual([]);
    expect(browserErrors.consoleErrors).toEqual([]);
    expect(browserErrors.pageErrors).toEqual([]);
});

test('country pagination changes results, scrolls to them and keeps alphabet scripts separate', async ({ page, baseURL }) => {
    const browserErrors = await installNetworkGuard(page, baseURL);

    await page.goto('/titles/country/turciia?country%5B0%5D=turciia');
    const results = page.locator('[data-catalog-results]');
    const firstTitle = await page.locator('[data-catalog-card]').first().innerText();

    await page.evaluate(() => window.scrollTo(0, document.documentElement.scrollHeight));
    await page.getByRole('link', { name: 'Страница 2' }).click();
    await expect(page).toHaveURL(/page=2/);
    await expect(page.locator('[data-catalog-pagination] [aria-current="page"]')).toHaveText('2');
    await expect(page.locator('[data-catalog-card]').first()).not.toHaveText(firstTitle);
    const resultTop = () => results.evaluate((element) => Math.round(element.getBoundingClientRect().top));

    await expect.poll(resultTop).toBeGreaterThanOrEqual(0);
    await expect.poll(resultTop).toBeLessThan(320);

    await page.getByRole('link', { name: 'Назад' }).click();
    await expect(page).not.toHaveURL(/page=2/);

    const mobileControls = page.locator('[data-catalog-mobile-output-controls]');
    if ((page.viewportSize()?.width || 0) < 1024) {
        await mobileControls.locator('summary').click();
    }

    const alphabetRoot = (page.viewportSize()?.width || 0) < 1024
        ? mobileControls
        : page.locator('[data-catalog-desktop-alphabet]');
    await expect(alphabetRoot.locator('[data-catalog-alphabet-group="cyrillic"]')).toBeVisible();
    await expect(alphabetRoot.locator('[data-catalog-alphabet-group="latin"]')).toBeVisible();
    await expect(alphabetRoot.locator('[data-alphabet-letter="A"]')).toBeVisible();
    await expect(alphabetRoot.locator('[data-alphabet-letter="Z"]')).toBeVisible();

    await page.goto('/actors');
    await expect(page.locator('[data-directory-alphabet-group="cyrillic"]')).toBeVisible();
    await expect(page.locator('[data-directory-alphabet-group="latin"]')).toBeVisible();
    await expect(page.locator('[data-directory-alphabet-symbols]')).toBeVisible();
    await assertPageGeometry(page);
    expect(browserErrors.localAssetFailures).toEqual([]);
    expect(browserErrors.consoleErrors).toEqual([]);
    expect(browserErrors.pageErrors).toEqual([]);
});

test('title page renders the player shell without local asset failures', async ({ page, baseURL }) => {
    const browserErrors = await installNetworkGuard(page, baseURL);

    await page.goto('/titles/browser-smoke');

    await expect(page.locator('[data-title-hero]')).toBeVisible();
    await expect(page.locator('[data-player-shell]')).toBeVisible();
    await expect(page.locator('video.js-catalog-player')).toHaveCount(1);
    await assertPageGeometry(page);
    await assertAccessibility(page);
    expect(browserErrors.localAssetFailures).toEqual([]);
    expect(browserErrors.consoleErrors).toEqual([]);
    expect(browserErrors.pageErrors).toEqual([]);
});
