import AxeBuilder from '@axe-core/playwright';
import { expect, test } from '@playwright/test';
import { installPlayerMediaFixtures } from './support/player-media-fixtures.js';

const routes = [
    ['home', '/'],
    ['catalog', '/titles?q=Browser%20Smoke'],
    ['directory', '/actors'],
    ['discovery', '/discover/popular'],
    ['title', '/titles/browser-smoke'],
    ['stats', '/stats'],
];

const isLocalRequest = (url, baseURL) => new URL(url).origin === new URL(baseURL).origin;

const installBrowserGuard = async (page, baseURL) => {
    const errors = [];

    await page.route('**/*', async (route) => {
        if (!isLocalRequest(route.request().url(), baseURL)) {
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
            isLocalRequest(request.url(), baseURL)
            && !isEphemeralMediaRequest
            && failure !== 'net::ERR_ABORTED'
        ) {
            errors.push(`request: ${failure || 'failed'} ${request.url()}`);
        }
    });
    page.on('response', (response) => {
        if (isLocalRequest(response.url(), baseURL) && response.status() >= 400) {
            errors.push(`response: ${response.status()} ${response.url()}`);
        }
    });

    return errors;
};

test('light visual system stays consistent across primary public routes', async ({ page, baseURL }, testInfo) => {
    test.setTimeout(180_000);

    const errors = await installBrowserGuard(page, baseURL);
    const evidence = [];

    for (const [label, path] of routes) {
        const response = await page.goto(path);

        expect(response?.status(), `${label} HTTP status`).toBe(200);
        await expect(page.locator('h1'), `${label} primary heading`).toHaveCount(1);

        const metrics = await page.evaluate(() => {
            const bodyStyle = window.getComputedStyle(document.body);
            const colorProbe = document.createElement('span');

            colorProbe.style.backgroundColor = 'var(--color-page)';
            document.body.append(colorProbe);
            const expectedPageBackground = window.getComputedStyle(colorProbe).backgroundColor;
            colorProbe.style.backgroundColor = 'var(--color-surface-primary)';
            const expectedSurfaceBackground = window.getComputedStyle(colorProbe).backgroundColor;
            colorProbe.remove();

            const visibleHeading = [...document.querySelectorAll('h1:not(.sr-only)')]
                .find((heading) => heading.getClientRects().length > 0);
            const panel = [...document.querySelectorAll('[data-ui-panel]')]
                .find((surface) => surface.getClientRects().length > 0);
            const panelStyle = panel ? window.getComputedStyle(panel) : null;
            const importantLowContrastText = [...document.querySelectorAll('[class*="text-slate-400"]')]
                .filter((element) => (
                    element.getClientRects().length > 0
                    && !element.matches('[data-ui-icon], [aria-hidden="true"], [aria-disabled="true"], :disabled')
                    && element.textContent?.trim()
                ))
                .map((element) => element.textContent.trim());

            return {
                bodyBackground: bodyStyle.backgroundColor,
                expectedPageBackground,
                expectedSurfaceBackground,
                headingFontSize: visibleHeading
                    ? Number.parseFloat(window.getComputedStyle(visibleHeading).fontSize)
                    : null,
                headingFontWeight: visibleHeading
                    ? window.getComputedStyle(visibleHeading).fontWeight
                    : null,
                importantLowContrastText,
                overflow: document.documentElement.scrollWidth - window.innerWidth,
                panelBackground: panelStyle?.backgroundColor ?? null,
                panelRadius: panelStyle ? Number.parseFloat(panelStyle.borderRadius) : null,
                panelShadow: panelStyle?.boxShadow ?? null,
            };
        });

        expect(metrics.bodyBackground).toBe(metrics.expectedPageBackground);
        expect(metrics.overflow).toBeLessThanOrEqual(1);
        expect(metrics.importantLowContrastText).toEqual([]);

        if (metrics.headingFontSize !== null) {
            expect(metrics.headingFontSize).toBeGreaterThanOrEqual(30);
            expect(metrics.headingFontSize).toBeLessThanOrEqual(36);
            expect(metrics.headingFontWeight).toBe('600');
        }

        if (metrics.panelRadius !== null) {
            expect(metrics.panelBackground).toBe(metrics.expectedSurfaceBackground);
            expect(metrics.panelRadius).toBe(12);
            expect(metrics.panelShadow).toBe('none');
        }

        evidence.push({
            label,
            metrics,
            path,
            viewport: page.viewportSize(),
        });

        await page.screenshot({
            path: testInfo.outputPath(`${label}.png`),
            fullPage: false,
        });
    }

    const statsAccessibility = await new AxeBuilder({ page })
        .withTags(['wcag2a', 'wcag2aa'])
        .analyze();
    const blockingViolations = statsAccessibility.violations.filter(
        (violation) => ['critical', 'serious'].includes(violation.impact),
    );

    expect(blockingViolations).toEqual([]);
    expect(errors).toEqual([]);

    await testInfo.attach('visual-system-evidence', {
        body: JSON.stringify(evidence, null, 2),
        contentType: 'application/json',
    });
});
