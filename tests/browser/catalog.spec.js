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

    await filters.locator(':scope > summary').click();
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
