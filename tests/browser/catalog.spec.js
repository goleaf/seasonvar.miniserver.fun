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
        '[data-title-card-watch]',
        '[data-title-card-library]',
        '[data-title-card-details]',
        '[data-title-card-menu] > summary',
        '[data-title-card-menu] button',
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

const openHeaderSearchForViewport = async (page) => {
    const root = page.locator('[data-header-search-autocomplete]');
    const input = page.locator('#site-search');

    if (page.viewportSize().width < 1024) {
        if (await root.getAttribute('data-mobile-open') !== 'true') {
            await page.locator('[data-site-header-row] [data-header-search-open]').click();
        }

        await expect(root).toHaveAttribute('data-mobile-open', 'true');
    } else {
        await input.evaluate((field) => field.blur());
        await input.focus();
    }

    await expect(input).toBeVisible();

    return { input, root };
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
    await expect(page.locator('[data-ui-poster-layout="grid"]')).toHaveCount(1);
    await expect(page.locator('[data-catalog-view-option]')).toHaveCount(2);

    const gridCard = page.locator('[data-catalog-card]').first();

    await expect(gridCard.locator('[data-title-card-title]')).toContainText('Browser Smoke');
    await expect(gridCard.locator('[data-title-card-original-title]')).toHaveText('Browser Smoke Original');
    await expect(gridCard).toContainText('2025');
    await expect(gridCard).toContainText('1 сезон');
    await expect(gridCard.locator('[data-title-card-rating]')).toHaveCount(1);
    await expect(gridCard.locator('[data-title-card-rating]')).toContainText('КиноПоиск 8,4');
    await expect(gridCard.locator('a[href*="/titles/genre/"]')).toHaveCount(2);
    await expect(gridCard.locator('[data-title-card-description]')).toHaveCount(0);
    await expect(gridCard.locator('[data-title-card-watch]')).toBeVisible();
    await expect(gridCard.locator('[data-title-card-library]')).toBeVisible();
    await gridCard.locator('[data-title-card-library]').focus();
    await expect(gridCard.locator('[data-title-card-library]')).toBeFocused();
    await page.screenshot({ path: testInfo.outputPath('catalog-grid-card.png') });

    const filters = page.locator('#catalog-filters');

    if (testInfo.project.name === 'Desktop Chromium') {
        await expect(filters.locator('form')).toBeVisible();
    } else {
        await expect(filters).not.toHaveAttribute('open', '');
        await page.locator('[data-catalog-mobile-filter-trigger]').click();
        await expect(filters).toHaveAttribute('open', '');
    }
    await expect(page.locator('[data-catalog-filter-groups]')).toBeVisible();
    await expect(page.getByText('Актёры', { exact: true }).first()).toBeVisible();

    await assertPageGeometry(page);
    await assertTouchTargets(page);
    await assertAccessibility(page);

    await page.goto('/titles?q=Browser%20Smoke&view=list');

    const listCard = page.locator('[data-catalog-card]').first();

    await expect(listCard).toHaveAttribute('data-ui-poster-layout', 'list');
    await expect(listCard).toContainText('2025 · Россия · 1 сезон · 3 серии');
    await expect(listCard.locator('[data-title-card-rating]')).toContainText('КиноПоиск 8,4');
    await expect(listCard.locator('[data-title-card-description]')).toHaveClass(/line-clamp-3/);
    await expect(listCard.locator('[data-title-card-watch]')).toBeVisible();
    await expect(listCard.locator('[data-title-card-library]')).toBeVisible();
    await expect(listCard.locator('[data-title-card-details]')).toBeVisible();
    await assertTouchTargets(page);
    await assertPageGeometry(page);
    await assertAccessibility(page);
    await page.screenshot({ path: testInfo.outputPath('catalog-list-card.png') });
    expect(browserErrors.localAssetFailures).toEqual([]);
    expect(browserErrors.consoleErrors).toEqual([]);
    expect(browserErrors.pageErrors).toEqual([]);
});

test('catalog filter action stack contains a long result label at every viewport', async ({
    page,
    baseURL,
}, testInfo) => {
    const browserErrors = await installNetworkGuard(page, baseURL);

    if (testInfo.project.name === 'Narrow Phone Chromium') {
        await page.setViewportSize({ width: 320, height: 568 });
    }

    await page.goto('/titles');

    const filters = page.locator('#catalog-filters');

    if (page.viewportSize().width < 1024) {
        await page.locator('[data-catalog-mobile-filter-trigger]').click();
        await expect(filters).toHaveAttribute('open', '');
    } else {
        await expect(filters.locator('form')).toBeVisible();
    }

    await expect(page.locator('[data-catalog-filter-groups]')).toBeVisible();

    const actions = filters.locator('[data-catalog-filter-actions]');
    const submit = actions.locator('button[type="submit"]');
    const cancel = actions.locator('[data-catalog-filter-cancel]');
    const reset = actions.getByRole('link', { name: 'Сбросить фильтры' });

    await expect(actions).toBeVisible();
    await actions.scrollIntoViewIfNeeded();
    await actions.locator('[data-catalog-filter-submit-label]').evaluate((label) => {
        label.textContent = 'Показать 33 005 сериалов';
    });

    const geometry = await actions.evaluate((container) => {
        const containerBox = container.getBoundingClientRect();
        const controls = [...container.querySelectorAll(':scope > button, :scope > a')]
            .map((control) => {
                const box = control.getBoundingClientRect();

                return {
                    bottom: box.bottom,
                    height: box.height,
                    left: box.left,
                    right: box.right,
                    top: box.top,
                    width: box.width,
                };
            });

        return {
            container: {
                left: containerBox.left,
                right: containerBox.right,
                width: containerBox.width,
            },
            controls,
            pageOverflow: document.documentElement.scrollWidth - window.innerWidth,
        };
    });

    expect(geometry.controls).toHaveLength(3);

    for (const control of geometry.controls) {
        expect(control.height).toBeGreaterThanOrEqual(44);
        expect(control.left).toBeGreaterThanOrEqual(geometry.container.left - 1);
        expect(control.right).toBeLessThanOrEqual(geometry.container.right + 1);
        expect(control.width).toBeGreaterThanOrEqual(geometry.container.width - 1);
    }

    for (let index = 1; index < geometry.controls.length; index += 1) {
        expect(geometry.controls[index].top)
            .toBeGreaterThanOrEqual(geometry.controls[index - 1].bottom);
    }

    expect(geometry.pageOverflow).toBeLessThanOrEqual(1);

    for (const control of [submit, cancel, reset]) {
        await control.focus();
        await expect(control).toBeFocused();
    }

    expect(browserErrors.localAssetFailures).toEqual([]);
    expect(browserErrors.consoleErrors).toEqual([]);
    expect(browserErrors.pageErrors).toEqual([]);
});

test('authenticated catalog card actions work by keyboard and remain available on touch', async ({ page, baseURL }, testInfo) => {
    const browserErrors = await installNetworkGuard(page, baseURL);

    await page.goto('/login');
    await page.getByLabel('Электронная почта').fill('browser@example.com');
    await page.getByLabel('Пароль', { exact: true }).fill('Browser-Strong-Password-42!');
    await page.getByRole('button', { name: 'Войти' }).click();
    await expect(page).toHaveURL(/\/library(?:\/|$)/);
    await page.goto('/titles?q=Browser%20Smoke');

    const card = page.locator('[data-catalog-card]').first();
    const library = card.locator('[data-title-card-library]');

    await expect(card.locator('[data-title-card-watch]')).toBeVisible();
    const initiallyInLibrary = await library.getAttribute('aria-pressed') === 'true';
    const expectedLibraryState = initiallyInLibrary ? 'false' : 'true';
    const expectedLibraryNotice = initiallyInLibrary
        ? 'Сериал удалён из библиотеки'
        : 'Сериал добавлен в библиотеку';
    await library.focus();
    await expect(library).toBeFocused();
    await library.press('Enter');
    await expect(library).toHaveAttribute('aria-pressed', expectedLibraryState);
    await expect(page.locator('[data-card-action-notice]')).toContainText(expectedLibraryNotice);

    const menu = card.locator('[data-title-card-menu]');
    const menuTrigger = menu.locator(':scope > summary');

    await menuTrigger.focus();
    await expect(menuTrigger).toBeFocused();
    await menuTrigger.press('Enter');
    await expect(menu).toHaveAttribute('open', '');

    const menuPanel = menu.locator(':scope > div');
    const panelPosition = await menuPanel.evaluate((element) => window.getComputedStyle(element).position);
    const touchSheetExpected = await page.evaluate(() => (
        window.innerWidth < 640
        || window.matchMedia('(hover: none), (pointer: coarse)').matches
    ));

    expect(panelPosition).toBe(touchSheetExpected ? 'fixed' : 'absolute');
    await page.screenshot({ path: testInfo.outputPath('catalog-card-actions.png') });

    const feedback = menu.locator('details');

    await feedback.locator('summary').press('Enter');
    await expect(feedback).toHaveAttribute('open', '');

    const reason = feedback.getByRole('button', { name: 'Уже смотрел в другом месте' });

    await reason.focus();
    await expect(reason).toBeFocused();
    await reason.press('Enter');
    await expect(page.locator('[data-card-action-notice]')).toContainText('Причина учтена');
    await assertTouchTargets(page);
    await assertPageGeometry(page);
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
        await page.goto('/top/series');

        const genre = page.locator('#top-list-genre');

        await expect(genre).toBeVisible();
        await genre.selectOption('brauzernaia-drama');
        await page.getByRole('button', { name: 'Показать' }).click();
        await expect.poll(() => new URL(page.url()).searchParams.get('genre')).toBe('brauzernaia-drama');
        await expect(genre).toHaveValue('brauzernaia-drama');
        await expect(page.getByText('Browser Smoke', { exact: true }).first()).toBeVisible();
        await expect(page.getByRole('link', { name: 'Фильмы', exact: true })).toHaveAttribute('href', /genre=brauzernaia-drama/);
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
        await expect(page).toHaveURL(/\/top\/series$/);
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
        await openHeaderSearchForViewport(page);
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

test('header autocomplete works by keyboard in one desktop row and compact fullscreen search', async ({ page, baseURL }) => {
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
    const dropdown = page.locator('[data-header-search-dropdown]');
    const titleOption = page.locator('[data-header-search-title-results] [role="option"]').first();

    await openHeaderSearchForViewport(page);
    await search.fill('Browser Smoke');
    await expect(listbox).toBeVisible();
    await expect(titleOption).toBeVisible();
    await expect(titleOption).toContainText('Browser Smoke');
    await expect(titleOption).toContainText('2025');
    await expect(titleOption).toContainText('Россия');
    await expect(titleOption).toContainText('1 сезон');
    await expect(titleOption).toContainText('3 серии');
    await expect(titleOption.locator('img')).toBeVisible();
    await expect.poll(() => titleOption.locator('img').evaluate((image) => image.naturalWidth)).toBeGreaterThan(0);
    await expect(page.getByRole('option', { name: /Browser Smoke category/ })).toHaveCount(0);

    releasePortal();
    await expect(page.getByRole('option', { name: /Browser Smoke category/ })).toBeVisible();
    await assertAccessibility(page);

    for (const width of [375, 768, 1280, 1920]) {
        await page.setViewportSize({ width, height: width < 800 ? 1024 : 1200 });
        await assertPageGeometry(page);
        const { root } = await openHeaderSearchForViewport(page);
        await search.focus();
        await expect(listbox).toBeVisible();

        const dropdownGeometry = await dropdown.evaluate((panel) => {
            const box = panel.getBoundingClientRect();
            const style = window.getComputedStyle(panel);
            const headerRow = document.querySelector('[data-site-header-row]')?.getBoundingClientRect();
            const navigation = document.querySelector('[data-site-header-primary-navigation]')?.getBoundingClientRect();
            const inputFrame = document.querySelector('[data-header-search-input-frame]')?.getBoundingClientRect();
            const submit = document.querySelector('[data-header-search-autocomplete] button[type="submit"]')?.getBoundingClientRect();
            const rootBox = document.querySelector('[data-header-search-autocomplete]')?.getBoundingClientRect();

            return {
                compact: window.innerWidth < 1024,
                left: box.left,
                right: box.right,
                viewportWidth: window.innerWidth,
                overflowY: style.overflowY,
                inputLeft: inputFrame?.left ?? 0,
                inputWidth: inputFrame?.width ?? 0,
                inputHeight: inputFrame?.height ?? 0,
                navigationBottom: navigation?.bottom ?? 0,
                navigationTop: navigation?.top ?? 0,
                headerBottom: headerRow?.bottom ?? 0,
                headerTop: headerRow?.top ?? 0,
                rootBottom: rootBox?.bottom ?? 0,
                rootLeft: rootBox?.left ?? 0,
                rootWidth: rootBox?.width ?? 0,
                rootTop: rootBox?.top ?? 0,
                submitHeight: submit?.height ?? 0,
                submitWidth: submit?.width ?? 0,
            };
        });

        expect(dropdownGeometry.right).toBeLessThanOrEqual(dropdownGeometry.viewportWidth + 1);
        expect(dropdownGeometry.inputHeight).toBeGreaterThanOrEqual(44);
        expect(dropdownGeometry.submitHeight).toBeGreaterThanOrEqual(44);
        expect(dropdownGeometry.submitWidth).toBeGreaterThanOrEqual(44);

        if (dropdownGeometry.compact) {
            await expect(root).toHaveAttribute('role', 'dialog');
            await expect(page.locator('[data-mobile-bottom-navigation]')).toBeHidden();
            expect(dropdownGeometry.rootTop).toBeLessThanOrEqual(1);
            expect(dropdownGeometry.rootBottom).toBeGreaterThanOrEqual((width < 800 ? 1024 : 1200) - 1);
        } else {
            expect(Math.abs(dropdownGeometry.left - dropdownGeometry.rootLeft)).toBeLessThanOrEqual(12);
            expect(Math.abs((dropdownGeometry.right - dropdownGeometry.left) - dropdownGeometry.rootWidth)).toBeLessThanOrEqual(24);
            expect(['auto', 'scroll']).not.toContain(dropdownGeometry.overflowY);
            expect(dropdownGeometry.navigationTop).toBeGreaterThanOrEqual(dropdownGeometry.headerTop - 1);
            expect(dropdownGeometry.navigationBottom).toBeLessThanOrEqual(dropdownGeometry.headerBottom + 1);
        }
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
    await expect.poll(async () => page.locator('#site-search').evaluate(
        (input) => input.getBoundingClientRect().height,
    )).toBeGreaterThanOrEqual(44);

    expect(browserErrors.localAssetFailures).toEqual([]);
    expect(browserErrors.consoleErrors).toEqual([]);
    expect(browserErrors.pageErrors).toEqual([]);
});

test('header search supports shortcuts, true-empty request CTA and session-only recent queries', async ({ page, baseURL }, testInfo) => {
    const browserErrors = await installNetworkGuard(page, baseURL);

    await page.route('**/api/v1/search/suggestions?*', async (route) => {
        const url = new URL(route.request().url());

        await route.fulfill({
            contentType: 'application/json',
            body: JSON.stringify({
                data: [],
                meta: {
                    query: url.searchParams.get('q'),
                    scope: url.searchParams.get('scope'),
                },
            }),
        });
    });
    await page.goto('/');
    await page.keyboard.press('Control+k');

    const { input, root } = await openHeaderSearchForViewport(page);
    const requestAction = page.locator('[data-header-search-request]');
    const catalogAction = page.locator('[data-header-search-all-results]');

    await expect(input).toBeFocused();
    await input.fill('Несуществующий сериал для заявки');
    await expect(requestAction).toBeVisible();
    await expect(catalogAction).toBeVisible();
    await expect(catalogAction).toHaveAttribute('href', /\/titles\?q=/);
    await page.screenshot({ path: testInfo.outputPath('header-search-empty.png') });
    await input.press('Enter');
    await expect(page).toHaveURL(/\/search\?q=/);

    await page.goto('/');
    await page.keyboard.press('Control+k');
    await openHeaderSearchForViewport(page);
    await expect(page.locator('[data-header-search-recent]')).toBeVisible();
    await expect(page.locator('[data-header-search-recent-results]')).toContainText('Несуществующий сериал для заявки');
    await page.locator('[data-header-search-recent-clear]').click();
    await expect(page.locator('[data-header-search-recent]')).toBeHidden();

    if (await root.getAttribute('data-mobile-open') === 'true') {
        await page.locator('[data-header-search-mobile-close]').click();
    } else {
        await input.press('Escape');
        await input.evaluate((field) => field.blur());
    }

    await page.keyboard.press('/');
    await expect(input).toBeFocused();

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

    const mobileViewport = (page.viewportSize()?.width || 0) < 1024;
    const alphabetMenu = mobileViewport
        ? page.locator('[data-catalog-mobile-alphabet]')
        : page.locator('[data-catalog-alphabet-menu]');

    if (mobileViewport) {
        await page.locator('[data-catalog-mobile-filter-trigger]').click();
    }

    await alphabetMenu.locator('summary').click();

    const alphabetRoot = mobileViewport
        ? alphabetMenu
        : alphabetMenu.locator('[data-catalog-desktop-alphabet]');
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

test('catalog list view and existing poster surfaces stay responsive', async ({ page, baseURL }, testInfo) => {
    test.setTimeout(60_000);
    const browserErrors = await installNetworkGuard(page, baseURL);

    await auditRenderedPage(page, testInfo, 'home', '/');
    await auditRenderedPage(page, testInfo, 'titles', '/titles?q=Browser%20Smoke&view=list', { listPoster: true });
    await expect(page.locator('[data-catalog-view-option]')).toHaveCount(2);
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
