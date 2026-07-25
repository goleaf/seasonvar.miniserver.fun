import { expect, test } from '@playwright/test';

const sectionTop = async (locator) => locator.evaluate((element) => element.getBoundingClientRect().top);

test('title quick navigation reaches every section and exposes the active destination', async ({ page }) => {
    await page.goto('/titles/browser-smoke');

    const navigation = page.locator('[data-title-quick-navigation]');

    await expect(navigation).toBeVisible();

    for (const destination of [
        ['Смотреть', 'player'],
        ['Сезоны', 'seasons'],
        ['О сериале', 'data-title-reference'],
        ['Отзывы зрителей', 'reviews'],
    ]) {
        const [label, targetId] = destination;
        const link = navigation.getByRole('link', { name: label, exact: true });
        const target = page.locator(`#${targetId}`);

        await expect(target).toHaveCount(1);
        await link.click();
        await expect(page).toHaveURL(new RegExp(`#${targetId}$`));
        await expect(link).toHaveAttribute('aria-current', 'location');
        await expect.poll(() => sectionTop(target)).toBeGreaterThan(0);
        await expect.poll(() => sectionTop(target)).toBeLessThan(360);
    }
});

test('guest title actions preserve the requested form and open the filtered schedule', async ({ page }) => {
    await page.goto('/titles/browser-smoke');

    const missingMaterial = page.getByRole('link', {
        name: 'Сообщить о недостающем материале',
        exact: true,
    });
    const schedule = page.getByRole('link', {
        name: 'Расписание сериала',
        exact: true,
    });

    await expect(missingMaterial).toHaveAttribute('href', /\/requests\/create\?/);
    await expect(schedule).toHaveAttribute('href', /\/calendar\/upcoming\?title=\d+/);

    await missingMaterial.click();
    await expect(page).toHaveURL(/\/login$/);
    await page.getByLabel('Электронная почта').fill('browser@example.com');
    await page.getByLabel('Пароль', { exact: true }).fill('Browser-Strong-Password-42!');
    await page.getByRole('button', { name: 'Войти' }).click();
    await expect.poll(() => new URL(page.url()).pathname).toBe('/requests/create');
    await expect.poll(() => new URL(page.url()).searchParams.get('type')).toBe('broken_content_restoration');
    await expect.poll(() => new URL(page.url()).searchParams.get('catalog_title_id')).not.toBeNull();

    await page.goto('/titles/browser-smoke');
    await page.getByRole('link', { name: 'Расписание сериала', exact: true }).click();
    await expect.poll(() => new URL(page.url()).pathname).toBe('/calendar/upcoming');
    await expect.poll(() => new URL(page.url()).searchParams.get('title')).not.toBeNull();
});
