import AxeBuilder from '@axe-core/playwright';
import { expect, test } from '@playwright/test';

const login = async (page) => {
    await page.goto('/login');
    await page.getByLabel('Электронная почта').fill('browser-admin@example.com');
    await page.getByLabel('Пароль', { exact: true }).fill('Browser-Strong-Password-42!');
    await page.getByRole('button', { name: 'Войти' }).click();
    await expect(page).toHaveURL(/\/library(?:\?|$)/);
};

test('import administration shows one truthful bounded queue status', async ({ page }, testInfo) => {
    const browserErrors = [];

    page.on('console', (message) => {
        if (message.type() === 'error') {
            browserErrors.push(`console: ${message.text()}`);
        }
    });
    page.on('pageerror', (error) => {
        browserErrors.push(`page: ${error.message}`);
    });
    page.on('response', (response) => {
        const url = new URL(response.url());

        if (url.origin === new URL(testInfo.project.use.baseURL).origin && response.status() >= 400) {
            browserErrors.push(`response ${response.status()}: ${url.pathname}`);
        }
    });

    await login(page);
    const response = await page.goto('/admin/imports');

    expect(response?.status()).toBe(200);
    await expect(page.getByRole('heading', { level: 1, name: 'Импорт Seasonvar' })).toBeVisible();
    await expect(page.getByRole('heading', { level: 2, name: 'Состояние очереди' })).toBeVisible();
    await expect(page.getByText(/^Сохранённый прогресс:/)).toBeVisible();
    await expect(page.getByText(/^Распределение: нет данных/)).toBeVisible();
    await expect(page.getByText(/^Heartbeat worker:/)).toBeVisible();

    const overflow = await page.evaluate(() => ({
        body: document.body.scrollWidth - document.body.clientWidth,
        document: document.documentElement.scrollWidth - document.documentElement.clientWidth,
    }));

    expect(overflow.body).toBeLessThanOrEqual(1);
    expect(overflow.document).toBeLessThanOrEqual(1);

    const accessibility = await new AxeBuilder({ page })
        .include('[data-livewire-seasonvar-import-manager]')
        .withTags(['wcag2a', 'wcag2aa'])
        .analyze();
    const seriousViolations = accessibility.violations.filter(
        (violation) => violation.impact === 'critical' || violation.impact === 'serious',
    );

    expect(seriousViolations).toEqual([]);
    expect(browserErrors).toEqual([]);

    await page.screenshot({
        path: `output/playwright/task-108-${testInfo.project.name.toLowerCase().replaceAll(' ', '-')}.png`,
        fullPage: true,
    });
});
