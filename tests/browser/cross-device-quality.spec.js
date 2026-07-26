import AxeBuilder from '@axe-core/playwright';
import { expect, test } from '@playwright/test';
import { installPlayerMediaFixtures } from './support/player-media-fixtures.js';

const representativeRoutes = [
    ['home-ru', '/'],
    ['catalog-ru', '/titles'],
    ['discovery-ru', '/discover/popular'],
    ['discovery-en', '/en/discover/popular'],
    ['title-ru', '/titles/browser-smoke'],
    ['login-ru', '/ru/login'],
];

const isSameOrigin = (url, baseURL) => {
    const target = new URL(url);
    const local = new URL(baseURL);

    return target.origin === local.origin;
};

const installBrowserGuard = async (page, baseURL) => {
    const errors = [];

    await page.route('**/*', async (route) => {
        if (!isSameOrigin(route.request().url(), baseURL)) {
            await route.abort('blockedbyclient');

            return;
        }

        await route.continue();
    });
    await installPlayerMediaFixtures(page);

    page.on('console', (message) => {
        if (message.type() === 'error' && !message.text().startsWith('Failed to load resource:')) {
            errors.push(`console: ${message.text()}`);
        }
    });
    page.on('pageerror', (error) => errors.push(`page: ${error.message}`));
    page.on('requestfailed', (request) => {
        const failure = request.failure()?.errorText;
        const isEphemeralMediaRequest = request.url().startsWith('blob:');

        if (
            isSameOrigin(request.url(), baseURL)
            && !isEphemeralMediaRequest
            && failure !== 'net::ERR_ABORTED'
        ) {
            errors.push(`request: ${failure || 'failed'} ${request.url()}`);
        }
    });
    page.on('response', (response) => {
        if (isSameOrigin(response.url(), baseURL) && response.status() >= 400) {
            errors.push(`response: ${response.status()} ${response.url()}`);
        }
    });

    return errors;
};

const assertPageGeometry = async (page) => {
    const geometry = await page.evaluate(() => ({
        clientWidth: document.documentElement.clientWidth,
        scrollWidth: document.documentElement.scrollWidth,
        viewportWidth: window.innerWidth,
        visibleDialogs: [...document.querySelectorAll('dialog, [role="dialog"]')]
            .filter((element) => element.getClientRects().length > 0)
            .map((element) => {
                const rect = element.getBoundingClientRect();

                return {
                    bottom: rect.bottom,
                    left: rect.left,
                    right: rect.right,
                    top: rect.top,
                };
            }),
    }));

    expect(geometry.scrollWidth - geometry.clientWidth).toBeLessThanOrEqual(1);

    for (const dialog of geometry.visibleDialogs) {
        expect(dialog.left).toBeGreaterThanOrEqual(-1);
        expect(dialog.right).toBeLessThanOrEqual(geometry.viewportWidth + 1);
        expect(dialog.top).toBeGreaterThanOrEqual(-1);
    }
};

const assertPrimaryTargets = async (page, playerMinimumSize) => {
    const undersized = await page.locator([
        '[data-site-header] a',
        '[data-site-header] button',
        '[data-site-header] input',
        '[data-site-header] summary',
        'main button',
        'main select',
    ].join(',')).evaluateAll((elements, playerMinimum) => elements
        .filter((element) => element.getClientRects().length > 0)
        .map((element) => {
            const type = element instanceof HTMLInputElement ? element.type : null;
            const hitTarget = ['checkbox', 'radio'].includes(type)
                ? element.closest('label') || element
                : element;
            const rect = hitTarget.getBoundingClientRect();
            const minimumSize = element.closest('.plyr__controls') === null
                ? 44
                : playerMinimum;

            return {
                height: rect.height,
                minimumSize,
                name: element.getAttribute('aria-label')
                    || hitTarget.textContent?.trim()
                    || element.tagName,
                type,
                width: rect.width,
            };
        })
        .filter((element) => (
            element.height < element.minimumSize
            || element.width < element.minimumSize
        )), playerMinimumSize);

    expect(undersized, 'Visible primary controls must be at least 44×44px.').toEqual([]);
};

const assertKeyboardFocus = async (page) => {
    await page.evaluate(() => document.body.focus());
    await page.keyboard.press('Tab');

    const focused = await page.evaluate(() => {
        const element = document.activeElement;

        if (!(element instanceof HTMLElement)) {
            return null;
        }

        const rect = element.getBoundingClientRect();
        const style = window.getComputedStyle(element);

        return {
            bottom: rect.bottom,
            height: rect.height,
            left: rect.left,
            outlineStyle: style.outlineStyle,
            outlineWidth: style.outlineWidth,
            right: rect.right,
            top: rect.top,
            width: rect.width,
        };
    });

    expect(focused).not.toBeNull();
    expect(focused.width).toBeGreaterThan(0);
    expect(focused.height).toBeGreaterThan(0);
    expect(focused.right).toBeGreaterThan(0);
    expect(focused.bottom).toBeGreaterThan(0);
};

test('representative public surfaces keep cross-device geometry and semantics', async ({ page, baseURL }, testInfo) => {
    test.setTimeout(180_000);

    const browserErrors = await installBrowserGuard(page, baseURL);
    const routeEvidence = [];
    const playerMinimumSize = testInfo.project.use.hasTouch
        || testInfo.project.name === 'TV-like Chromium'
        ? 44
        : 32;

    for (const [label, path] of representativeRoutes) {
        const response = await page.goto(path);

        expect(response?.status(), `${label} HTTP status`).toBe(200);
        await expect(page.locator('h1'), `${label} must have one primary heading`).toHaveCount(1);
        await expect(page.locator('body')).not.toContainText(/\b(?:catalog|mobile|home)\.[a-z0-9_.]+\b/i);
        await assertPageGeometry(page);
        await assertPrimaryTargets(page, playerMinimumSize);
        await assertKeyboardFocus(page);

        routeEvidence.push({
            h1: (await page.locator('h1').innerText()).trim(),
            label,
            path,
            url: page.url(),
        });
    }

    const axeResults = await new AxeBuilder({ page })
        .withTags(['wcag2a', 'wcag2aa'])
        .analyze();
    const blockingViolations = axeResults.violations.filter(
        (violation) => ['critical', 'serious'].includes(violation.impact),
    );

    expect(blockingViolations).toEqual([]);
    expect(browserErrors).toEqual([]);

    await testInfo.attach('cross-device-route-evidence', {
        body: JSON.stringify({
            project: testInfo.project.name,
            routes: routeEvidence,
            viewport: page.viewportSize(),
        }, null, 2),
        contentType: 'application/json',
    });
});

test('private and administration routes keep the guest boundary', async ({ page, baseURL }) => {
    const browserErrors = await installBrowserGuard(page, baseURL);

    for (const path of ['/library', '/admin']) {
        const response = await page.goto(path);

        expect(response?.status()).toBe(200);
        await expect(page).toHaveURL(/\/login(?:\?|$)/);
        await expect(page.locator('h1')).toHaveCount(1);
        await assertPageGeometry(page);
    }

    expect(browserErrors).toEqual([]);
});

test('tablet-width shell keeps compact navigation until the desktop content breakpoint', async ({ page }, testInfo) => {
    const viewport = page.viewportSize();
    const expectsCompactNavigation = viewport.width < 1024;

    await page.goto('/');

    const compactNavigation = page.locator('[data-mobile-navigation]');
    const desktopNavigation = page.locator('details[data-mobile-navigation] + nav[data-site-header-navigation]');

    if (expectsCompactNavigation) {
        await expect(compactNavigation.locator('summary')).toBeVisible();
        await expect(desktopNavigation).toBeHidden();

        return;
    }

    await expect(compactNavigation.locator('summary')).toBeHidden();
    await expect(desktopNavigation).toBeVisible();

    await testInfo.attach('navigation-breakpoint', {
        body: JSON.stringify({
            compact: expectsCompactNavigation,
            viewport,
        }, null, 2),
        contentType: 'application/json',
    });
});

test('title FAQ disclosures expose full touch targets', async ({ page }) => {
    await page.goto('/titles/browser-smoke');

    const undersized = await page.locator('main details > summary').evaluateAll((summaries) => summaries
        .filter((summary) => summary.getClientRects().length > 0)
        .map((summary) => ({
            height: summary.getBoundingClientRect().height,
            name: summary.textContent?.trim() || 'SUMMARY',
        }))
        .filter((summary) => summary.height < 44));

    expect(undersized).toEqual([]);
});

test('catalog filter searches expose full touch targets', async ({ page }) => {
    await page.goto('/titles');

    const filters = page.locator('#catalog-filters');
    const summary = filters.locator('summary');

    await page.evaluate(() => {
        document.querySelector('#catalog-filters')?.scrollIntoView({
            block: 'center',
            inline: 'nearest',
        });
    });
    await expect(summary).toBeVisible();

    if (await filters.getAttribute('open') === null) {
        await summary.click();
    }

    const undersized = await page.locator('input[id^="catalog-filter-search-"]').evaluateAll((inputs) => inputs
        .filter((input) => input.getClientRects().length > 0)
        .map((input) => ({
            height: input.getBoundingClientRect().height,
            id: input.id,
        }))
        .filter((input) => input.height < 44));

    expect(undersized).toEqual([]);
});

test('TV-like viewport uses a comfortable large-display reading scale', async ({ page }, testInfo) => {
    test.skip(testInfo.project.name !== 'TV-like Chromium');

    await page.goto('/');

    const largeDisplay = await page.evaluate(() => {
        const firstNavigationLink = [...document.querySelectorAll('[data-site-header-navigation] a')]
            .find((link) => link.getClientRects().length > 0);
        const rootFontSize = Number.parseFloat(window.getComputedStyle(document.documentElement).fontSize);

        firstNavigationLink?.focus();

        return {
            focusOutlineWidth: firstNavigationLink instanceof HTMLElement
                ? Number.parseFloat(window.getComputedStyle(firstNavigationLink).outlineWidth)
                : 0,
            rootFontSize,
        };
    });

    expect(largeDisplay.rootFontSize).toBeGreaterThanOrEqual(18);
    expect(largeDisplay.focusOutlineWidth).toBeGreaterThanOrEqual(3);
});
