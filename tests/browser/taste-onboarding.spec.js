import { expect, test } from '@playwright/test';

const login = async (page) => {
    await page.goto('/login');
    await page.getByLabel('Электронная почта').fill('browser@example.com');
    await page.getByLabel('Пароль', { exact: true }).fill('Browser-Strong-Password-42!');
    await page.getByRole('button', { name: 'Войти' }).click();
    await expect(page).toHaveURL(/\/library(?:\/|$)/);
};

test('taste onboarding is responsive, accessible, editable, and saves through Livewire', async ({
    page,
    baseURL,
}, testInfo) => {
    const browserErrors = [];
    const localOrigin = new URL(baseURL).origin;

    page.on('console', (message) => {
        if (message.type() === 'error' && !message.text().startsWith('Failed to load resource:')) {
            browserErrors.push(`console: ${message.text()}`);
        }
    });
    page.on('pageerror', (error) => browserErrors.push(`page: ${error.message}`));
    page.on('response', (response) => {
        if (new URL(response.url()).origin === localOrigin && response.status() >= 400) {
            browserErrors.push(`${response.status()} ${response.url()}`);
        }
    });

    await login(page);
    const response = await page.goto('/onboarding/tastes');

    expect(response?.status()).toBe(200);
    await expect(page.getByRole('heading', { level: 1, name: 'Настройте рекомендации' })).toBeVisible();
    const existingSelections = page.locator('button[aria-label^="Убрать"]');

    while (await existingSelections.count() > 0) {
        const remaining = await existingSelections.count();

        await existingSelections.first().evaluate((button) => button.click());
        await expect(existingSelections).toHaveCount(remaining - 1);
    }

    await expect(page.getByText('Выбрано знакомых сериалов: 0 из 5–10', { exact: true })).toBeVisible();
    await page.getByLabel('Браузерная драма', { exact: true }).check();
    await page.getByLabel('Турция', { exact: true }).check();

    for (let index = 1; index <= 5; index++) {
        await page.locator('#liked-title-search').fill('Турецкий браузерный сериал');
        const suggestion = page.getByRole('button', {
            name: new RegExp(`^Турецкий браузерный сериал ${String(index).padStart(2, '0')}`),
        });

        await expect(suggestion).toBeVisible();
        await suggestion.evaluate((button) => button.click());
        await expect(
            page.getByText(`Выбрано знакомых сериалов: ${index} из 5–10`, { exact: true }),
        ).toBeVisible();
    }

    await expect(page.getByText('Выбрано знакомых сериалов: 5 из 5–10', { exact: true })).toBeVisible();
    await page.getByLabel('Озвучка или субтитры').selectOption('dubbed');
    await page.getByLabel('Статус сериала').selectOption('ongoing');
    await page.getByLabel('Продолжительность серий').selectOption('short');

    const save = page.getByRole('button', { name: 'Сохранить и открыть подборку' });
    const saveBox = await save.boundingBox();

    expect(saveBox?.height).toBeGreaterThanOrEqual(44);
    expect(await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth)).toBeLessThanOrEqual(1);
    await page.screenshot({
        path: `output/playwright/taste-onboarding-${testInfo.project.name.toLowerCase().replaceAll(' ', '-')}.png`,
        fullPage: true,
    });
    await save.click();
    await expect(page).toHaveURL(/\/discover\/personalized(?:\?|$)/);
    await expect(page.getByRole('link', { name: 'Изменить вкусы' })).toBeVisible();

    await page.goto('/en/onboarding/tastes');
    await expect(page.getByRole('heading', { level: 1, name: 'Set up your recommendations' })).toBeVisible();
    await expect(page.getByText('Familiar series selected: 5 of 5–10', { exact: true })).toBeVisible();
    expect(await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth)).toBeLessThanOrEqual(1);
    expect(browserErrors).toEqual([]);
});
