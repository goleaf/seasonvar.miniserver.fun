import { expect, test } from '@playwright/test';

const renderedReleaseTimes = async (page) => page.locator(
    '[data-pagination-region="release-calendar-results"] time[datetime]',
).evaluateAll((times) => times.map((time) => Date.parse(time.getAttribute('datetime'))));

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
