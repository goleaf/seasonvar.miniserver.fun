import AxeBuilder from '@axe-core/playwright';
import { expect, test } from '@playwright/test';
import { installPlayerMediaFixtures } from './support/player-media-fixtures.js';

const isLocalHttpRequest = (requestUrl, baseURL) => {
    const target = new URL(requestUrl);
    const local = new URL(baseURL);

    return ['http:', 'https:'].includes(target.protocol)
        && target.origin === local.origin;
};

const isExpectedBrowserNavigationAbort = (request) => (
    request.failure()?.errorText === 'net::ERR_ABORTED'
);

const installNetworkGuard = async (page, baseURL) => {
    const localAssetFailures = [];
    const consoleErrors = [];
    const pageErrors = [];

    await page.route('**/*', async (route) => {
        if (!isLocalHttpRequest(route.request().url(), baseURL)) {
            await route.abort('blockedbyclient');

            return;
        }

        await route.continue();
    });
    await installPlayerMediaFixtures(page);

    page.on('response', (response) => {
        const request = response.request();
        const resourceType = request.resourceType();

        if (
            isLocalHttpRequest(response.url(), baseURL)
            && ['stylesheet', 'script', 'image', 'font'].includes(resourceType)
            && response.status() >= 400
        ) {
            localAssetFailures.push(`${response.status()} ${response.url()}`);
        }
    });
    page.on('requestfailed', (request) => {
        if (
            isLocalHttpRequest(request.url(), baseURL)
            && !isExpectedBrowserNavigationAbort(request)
        ) {
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

const auditRenderedPage = async (page, testInfo, label, path, { listPoster = false } = {}) => {
    const response = await page.goto(path);

    expect(response?.status()).toBe(200);
    await expect(page.locator('h1')).toHaveCount(1);
    await assertPageGeometry(page);

    const poster = page.locator('[data-ui-poster-layout="list"] [data-ui-poster-frame] img').first();
    let posterMetrics = null;

    if (listPoster) {
        await poster.scrollIntoViewIfNeeded();
        await expect(poster).toBeVisible();
        await expect.poll(() => poster.evaluate((image) => image.naturalWidth)).toBeGreaterThan(0);
        posterMetrics = await poster.evaluate((image) => {
            const frame = image.closest('[data-ui-poster-card-media]');
            const frameBox = frame?.getBoundingClientRect();

            return {
                frameHeight: frameBox?.height ?? null,
                frameWidth: frameBox?.width ?? null,
                naturalHeight: image.naturalHeight,
                naturalWidth: image.naturalWidth,
                objectFit: window.getComputedStyle(image).objectFit,
            };
        });

        expect(posterMetrics.objectFit).toBe('contain');
        expect(posterMetrics.naturalWidth).toBeGreaterThan(0);
        expect(posterMetrics.frameWidth).toBeGreaterThanOrEqual(64);
        expect(posterMetrics.frameHeight / posterMetrics.frameWidth).toBeCloseTo(1.5, 1);
    }

    const metrics = {
        finalUrl: page.url(),
        h1: (await page.locator('h1').innerText()).trim(),
        headings: await page.locator('h2').allInnerTexts(),
        horizontalOverflow: await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth),
        poster: posterMetrics,
        status: response?.status(),
        viewport: page.viewportSize(),
    };

    await testInfo.attach(label + '-metrics', {
        body: JSON.stringify(metrics, null, 2),
        contentType: 'application/json',
    });
    await page.screenshot({
        path: testInfo.outputPath(label + '.png'),
        fullPage: false,
    });
};

test('catalog keeps URL state, unified filters and responsive geometry', async ({ page, baseURL }, testInfo) => {
    const browserErrors = await installNetworkGuard(page, baseURL);

    await page.goto('/titles?q=Browser%20Smoke&sort=title_asc');

    await expect(page.getByRole('heading', { level: 1 })).toBeVisible();
    await expect(page.locator('#catalog-search')).toHaveValue('Browser Smoke');
    await expect(page).toHaveURL(/q=Browser(?:%20|\+)Smoke/);
    await expect(page.locator('[data-catalog-card]')).toHaveCount(1);
    await expect(page.locator('[data-catalog-results-list]')).toBeVisible();
    await expect(page.locator('[data-ui-poster-layout="list"]')).toHaveCount(1);
    await expect(page.locator('[data-catalog-view-option]')).toHaveCount(0);

    const filters = page.locator('#catalog-filters');

    if (testInfo.project.name === 'Desktop Chromium') {
        await expect(filters).toHaveAttribute('open', '');
    } else {
        await expect(filters).not.toHaveAttribute('open', '');
        await filters.locator('summary').click();
        await expect(filters).toHaveAttribute('open', '');
    }
    await expect(page.locator('[data-catalog-filter-groups]')).toBeVisible();
    await expect(page.getByText('Актёры', { exact: true }).first()).toBeVisible();

    await assertPageGeometry(page);
    await assertTouchTargets(page);
    await assertAccessibility(page);
    expect(browserErrors.localAssetFailures).toEqual([]);
    expect(browserErrors.consoleErrors).toEqual([]);
    expect(browserErrors.pageErrors).toEqual([]);
});

test('Top 100 genre filter submits, resets and keeps responsive geometry', async ({ page, baseURL }, testInfo) => {
    test.setTimeout(180_000);

    const browserErrors = await installNetworkGuard(page, baseURL);
    const widthsByProject = {
        'Desktop Chromium': [1440, 1920],
        'Mobile Chromium': [390],
        'Tablet Chromium': [768],
    };

    for (const width of widthsByProject[testInfo.project.name] ?? [1440]) {
        await page.setViewportSize({ width, height: width < 800 ? 1024 : 1200 });
        await page.goto('/top/movies');

        const genre = page.locator('#top-list-genre');

        await expect(genre).toBeVisible();
        await genre.selectOption('brauzernaia-drama');
        await page.getByRole('button', { name: 'Показать' }).click();
        await expect.poll(() => new URL(page.url()).searchParams.get('genre')).toBe('brauzernaia-drama');
        await expect(genre).toHaveValue('brauzernaia-drama');
        await expect(page.getByText('Browser Smoke', { exact: true }).first()).toBeVisible();
        await expect(page.getByRole('link', { name: 'Сериалы', exact: true })).toHaveAttribute('href', /genre=brauzernaia-drama/);
        await assertPageGeometry(page);
        await assertAccessibility(page);

        const controlHeights = await page.locator([
            '[data-top-list-filters] input',
            '[data-top-list-filters] select',
            '[data-top-list-filters] button',
            '[data-top-list-filters] a',
        ].join(',')).evaluateAll((controls) => controls
            .filter((control) => control.getClientRects().length > 0)
            .map((control) => control.getBoundingClientRect().height));

        expect(controlHeights.every((height) => height >= 44)).toBe(true);
        await page.getByRole('link', { name: 'Сбросить' }).click();
        await expect(page).toHaveURL(/\/top\/movies$/);
    }

    expect(browserErrors.localAssetFailures).toEqual([]);
    expect(browserErrors.consoleErrors).toEqual([]);
    expect(browserErrors.pageErrors).toEqual([]);
});

test('header search input keeps its neutral frame while focused and edited', async ({ page, baseURL }) => {
    const browserErrors = await installNetworkGuard(page, baseURL);

    await page.goto('/');

    const search = page.locator('#site-search');
    const searchFrame = page.locator('[data-header-search-input-frame]');
    const searchFrameStyle = () => searchFrame.evaluate((frame) => {
        const style = window.getComputedStyle(frame);
        const input = frame.querySelector('[data-header-search-input]');
        const inputStyle = input ? window.getComputedStyle(input) : null;

        return {
            borderColor: style.borderTopColor,
            boxShadow: style.boxShadow,
            inputBoxShadow: inputStyle?.boxShadow ?? '',
            inputOutlineStyle: inputStyle?.outlineStyle ?? '',
            inputOutlineWidth: inputStyle?.outlineWidth ?? '',
        };
    });
    for (const width of [375, 768, 1280, 1920]) {
        await page.setViewportSize({ width, height: width < 800 ? 1024 : 1200 });
        await assertPageGeometry(page);
        await search.evaluate((input) => input.blur());
        const idleSearchFrameStyle = await searchFrameStyle();
        await search.focus();
        expect(await searchFrameStyle()).toEqual(idleSearchFrameStyle);
        await search.fill('Б');
        expect(await searchFrameStyle()).toEqual(idleSearchFrameStyle);
        await search.fill('Browser Smoke');
        await expect(page.getByRole('listbox', { name: 'Подсказки поиска' })).toBeVisible();
        expect(await searchFrameStyle()).toEqual(idleSearchFrameStyle);

        const frameClasses = await searchFrame.getAttribute('class');

        expect(frameClasses).toContain('border-slate-300');
        expect(frameClasses).not.toContain('focus-within:border-');
        expect(frameClasses).not.toContain('focus-within:ring-');
    }

    expect(browserErrors.localAssetFailures).toEqual([]);
    expect(browserErrors.consoleErrors).toEqual([]);
    expect(browserErrors.pageErrors).toEqual([]);
});

test('header autocomplete works by keyboard and keeps two responsive rows', async ({ page, baseURL }) => {
    test.setTimeout(90_000);

    const browserErrors = await installNetworkGuard(page, baseURL);
    let releasePortal;
    const portalGate = new Promise((resolve) => {
        releasePortal = resolve;
    });

    await page.route('**/api/v1/search/suggestions?*', async (route) => {
        const requestUrl = new URL(route.request().url());

        if (requestUrl.searchParams.get('scope') === 'header_portal') {
            await portalGate;
        }

        await route.continue();
    });

    await page.goto('/');

    const search = page.locator('#site-search');
    const listbox = page.getByRole('listbox', { name: 'Подсказки поиска' });
    const titleOption = page.locator('[data-header-search-title-results] [role="option"]').first();

    await search.fill('Browser Smoke');
    await expect(listbox).toBeVisible();
    await expect(titleOption).toBeVisible();
    await expect(titleOption).toContainText('Browser Smoke');
    await expect(titleOption).toContainText('2025');
    await expect(titleOption).toContainText('1 сезон');
    await expect(titleOption).toContainText('1 серия');
    await expect(titleOption.locator('img')).toBeVisible();
    await expect.poll(() => titleOption.locator('img').evaluate((image) => image.naturalWidth)).toBeGreaterThan(0);
    await expect(page.getByRole('option', { name: /Browser Smoke category/ })).toHaveCount(0);

    releasePortal();
    await expect(page.getByRole('option', { name: /Browser Smoke category/ })).toBeVisible();
    await assertAccessibility(page);

    for (const width of [375, 768, 1280, 1920]) {
        await page.setViewportSize({ width, height: width < 800 ? 1024 : 1200 });
        await assertPageGeometry(page);

        const dropdownGeometry = await listbox.evaluate((dropdown) => {
            const box = dropdown.getBoundingClientRect();
            const style = window.getComputedStyle(dropdown);
            const primary = document.querySelector('[data-site-header-primary]')?.getBoundingClientRect();
            const navigation = [...document.querySelectorAll('[data-site-header-navigation]')]
                .find((element) => element.getClientRects().length > 0)
                ?.getBoundingClientRect();
            const inputFrame = document.querySelector('[data-header-search-input-frame]')?.getBoundingClientRect();
            const submit = document.querySelector('[data-header-search-autocomplete] button[type="submit"]')?.getBoundingClientRect();

            return {
                left: box.left,
                right: box.right,
                viewportWidth: window.innerWidth,
                overflowY: style.overflowY,
                inputLeft: inputFrame?.left ?? 0,
                inputWidth: inputFrame?.width ?? 0,
                inputHeight: inputFrame?.height ?? 0,
                submitHeight: submit?.height ?? 0,
                submitWidth: submit?.width ?? 0,
                primaryBottom: primary?.bottom ?? 0,
                navigationTop: navigation?.top ?? 0,
            };
        });

        expect(Math.abs(dropdownGeometry.left - dropdownGeometry.inputLeft)).toBeLessThanOrEqual(12);
        expect(Math.abs((dropdownGeometry.right - dropdownGeometry.left) - dropdownGeometry.inputWidth)).toBeLessThanOrEqual(24);
        expect(dropdownGeometry.right).toBeLessThanOrEqual(dropdownGeometry.viewportWidth + 1);
        expect(['auto', 'scroll']).not.toContain(dropdownGeometry.overflowY);
        expect(dropdownGeometry.inputHeight).toBeGreaterThanOrEqual(44);
        expect(dropdownGeometry.submitHeight).toBeGreaterThanOrEqual(44);
        expect(dropdownGeometry.submitWidth).toBeGreaterThanOrEqual(44);
        expect(dropdownGeometry.navigationTop).toBeGreaterThanOrEqual(dropdownGeometry.primaryBottom - 1);
    }

    await search.press('End');
    await expect(search).not.toHaveAttribute('aria-activedescendant', 'site-search-option-0');
    await search.press('Home');
    await expect(search).toHaveAttribute('aria-activedescendant', 'site-search-option-0');
    await search.press('Escape');
    await expect(listbox).toBeHidden();
    await search.evaluate((input) => input.blur());
    await search.focus();
    await expect(listbox).toBeVisible();
    await search.press('Home');
    await search.press('Enter');
    await expect(page).toHaveURL(/\/titles\/browser-smoke$/);
    await page.waitForLoadState('domcontentloaded');
    await expect(page.locator('#site-search')).toHaveCSS('min-height', '44px');

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

test('country pagination changes results, scrolls to them and keeps alphabet scripts separate', async ({ page, baseURL }, testInfo) => {
    const browserErrors = await installNetworkGuard(page, baseURL);

    await page.goto('/titles/country/turciia?country%5B0%5D=turciia');
    const results = page.locator('[data-catalog-results]');
    const loading = results.locator('[data-pagination-loading]');
    const firstTitle = await page.locator('[data-catalog-card]').first().innerText();

    await page.route(/\/livewire(?:-[^/]+)?\/update(?:\?.*)?$/, async (route) => {
        await new Promise((resolve) => setTimeout(resolve, 400));
        await route.continue();
    });

    await page.evaluate(() => window.scrollTo(0, document.documentElement.scrollHeight));
    await page.evaluate(() => {
        window.__paginationScrollSamples = [];
        window.__paginationScrollStartY = window.scrollY;
        window.addEventListener('scroll', () => {
            window.__paginationScrollSamples.push({ at: performance.now(), y: window.scrollY });
        }, { passive: true });
    });
    await Promise.all([
        expect(results).toHaveAttribute('aria-busy', 'true'),
        expect(loading).toBeVisible(),
        page.getByRole('link', { name: 'Страница 2' }).click(),
    ]);
    await expect(page).toHaveURL(/page=2/);
    await expect(page.locator('[data-catalog-pagination] [aria-current="page"]')).toHaveText('2');
    await expect(page.locator('[data-catalog-card]').first()).not.toHaveText(firstTitle);
    await expect(results).toHaveAttribute('aria-busy', 'false');
    await expect(loading).toBeHidden();
    const resultTop = () => results.evaluate((element) => Math.round(element.getBoundingClientRect().top));

    await expect.poll(resultTop).toBeGreaterThanOrEqual(0);
    await expect.poll(resultTop).toBeLessThan(320);
    await page.waitForTimeout(900);

    const scrollContract = await results.evaluate((element) => {
        const header = document.querySelector('[data-site-header]');
        const headerPosition = header ? window.getComputedStyle(header).position : 'static';
        const headerBottom = header && ['sticky', 'fixed'].includes(headerPosition)
            ? Math.max(0, header.getBoundingClientRect().bottom)
            : 0;
        const samples = window.__paginationScrollSamples || [];
        const startY = window.__paginationScrollStartY ?? window.scrollY;
        const finalY = window.scrollY;

        return {
            duration: samples.length > 1 ? samples.at(-1).at - samples[0].at : 0,
            expectedTop: headerBottom + 16,
            hasIntermediatePosition: samples.some(({ y }) => (
                Math.abs(y - startY) > 1 && Math.abs(y - finalY) > 1
            )),
            top: element.getBoundingClientRect().top,
        };
    });

    expect(scrollContract.hasIntermediatePosition).toBe(true);
    expect(scrollContract.duration).toBeGreaterThanOrEqual(500);
    expect(Math.abs(scrollContract.top - scrollContract.expectedTop)).toBeLessThanOrEqual(4);
    await page.screenshot({ path: testInfo.outputPath('pagination-result.png') });

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

test('country pagination respects reduced motion', async ({ browser, baseURL }, testInfo) => {
    test.skip(testInfo.project.name !== 'Desktop Chromium', 'Reduced motion needs one representative runtime check.');

    const context = await browser.newContext({
        baseURL,
        reducedMotion: 'reduce',
        viewport: { width: 1440, height: 1200 },
    });
    const page = await context.newPage();
    const browserErrors = await installNetworkGuard(page, baseURL);

    await page.goto('/titles/country/turciia?country%5B0%5D=turciia');
    const results = page.locator('[data-catalog-results]');
    await page.evaluate(() => window.scrollTo(0, document.documentElement.scrollHeight));
    await page.evaluate(() => {
        window.__paginationReducedMotionSamples = [];
        window.addEventListener('scroll', () => {
            window.__paginationReducedMotionSamples.push({ at: performance.now(), y: window.scrollY });
        }, { passive: true });
    });

    await page.getByRole('link', { name: 'Страница 2' }).click();
    await expect(page).toHaveURL(/page=2/);
    await expect(results).toHaveAttribute('aria-busy', 'false');
    await page.waitForTimeout(150);

    const motionContract = await results.evaluate((element) => {
        const header = document.querySelector('[data-site-header]');
        const headerPosition = header ? window.getComputedStyle(header).position : 'static';
        const headerBottom = header && ['sticky', 'fixed'].includes(headerPosition)
            ? Math.max(0, header.getBoundingClientRect().bottom)
            : 0;

        return {
            reduced: window.matchMedia('(prefers-reduced-motion: reduce)').matches,
            sampleCount: (window.__paginationReducedMotionSamples || []).length,
            top: element.getBoundingClientRect().top,
            expectedTop: headerBottom + 16,
        };
    });

    expect(motionContract.reduced).toBe(true);
    expect(motionContract.sampleCount).toBeLessThanOrEqual(3);
    expect(Math.abs(motionContract.top - motionContract.expectedTop)).toBeLessThanOrEqual(24);
    expect(browserErrors.localAssetFailures).toEqual([]);
    expect(browserErrors.consoleErrors).toEqual([]);
    expect(browserErrors.pageErrors).toEqual([]);

    await context.close();
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

test('list-only surfaces keep uncropped posters across responsive viewports', async ({ page, baseURL }, testInfo) => {
    test.setTimeout(60_000);
    const browserErrors = await installNetworkGuard(page, baseURL);

    await auditRenderedPage(page, testInfo, 'home', '/', { listPoster: true });
    await auditRenderedPage(page, testInfo, 'titles', '/titles?q=Browser%20Smoke', { listPoster: true });
    await expect(page.locator('[data-catalog-view-option]')).toHaveCount(0);
    await auditRenderedPage(page, testInfo, 'genres', '/genres');
    await expect(page.locator('[data-directory-results-list]')).toBeVisible();
    await auditRenderedPage(page, testInfo, 'title', '/titles/browser-smoke');

    await page.goto('/login');
    await page.getByLabel('Электронная почта').fill('browser@example.com');
    await page.getByLabel('Пароль', { exact: true }).fill('Browser-Strong-Password-42!');
    await page.getByRole('button', { name: 'Войти' }).click();
    await expect(page).toHaveURL(/\/library(?:\/|$)/);
    await auditRenderedPage(page, testInfo, 'library', '/library/watchlist', { listPoster: true });
    await expect(page.locator('[data-library-watchlist-list]')).toBeVisible();

    expect(browserErrors.localAssetFailures).toEqual([]);
    expect(browserErrors.consoleErrors).toEqual([]);
    expect(browserErrors.pageErrors).toEqual([]);
});
