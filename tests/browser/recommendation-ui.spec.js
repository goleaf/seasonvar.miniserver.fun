import { expect, test } from '@playwright/test';

test('compact catalog cards expose details and one recommendation reason', async ({ page, baseURL }, testInfo) => {
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

    for (const path of ['/', '/titles?q=Browser%20Smoke', '/titles/browser-smoke']) {
        const response = await page.goto(path);

        expect(response?.status()).toBe(200);
        await expect(page.getByText('Открыть тайтл', { exact: true })).toHaveCount(0);
        await expect(page.locator('[data-title-card-details]').first()).toBeVisible();
        expect(await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth)).toBeLessThanOrEqual(1);
    }

    await page.goto('/titles?q=Browser%20Smoke');
    const description = page.locator('[data-title-card-description]').first();

    await expect(description).toBeVisible();
    await expect(description).toHaveClass(/line-clamp-3/);
    expect(await description.evaluate((element) => {
        const lineHeight = Number.parseFloat(getComputedStyle(element).lineHeight);

        return element.scrollHeight <= Math.ceil(lineHeight * 3) + 1;
    })).toBe(true);

    await page.goto('/titles/browser-smoke');
    const recommendationRows = page.locator('[data-recommendation-list] [data-recommendation-row]');

    await expect(recommendationRows).toHaveCount(1);
    await expect(recommendationRows.getByRole('link', { name: 'Рекомендованный браузерный сериал' })).toBeVisible();
    await expect(recommendationRows.getByRole('link', { name: 'Подробнее' })).toBeVisible();
    await expect(recommendationRows.getByText('Почему это показано:', { exact: true })).toBeVisible();
    await expect(recommendationRows.getByText('Похожие жанры и темы', { exact: true })).toBeVisible();
    const recommendationHrefs = await recommendationRows.locator('a[href*="/titles/"]').evaluateAll(
        (links) => links.map((link) => link.getAttribute('href')),
    );
    const recommendedTitleUrl = `${baseURL}/titles/browser-recommended`;
    const recommendationTitleHrefs = recommendationHrefs.filter((href) => href === recommendedTitleUrl);

    expect(recommendationHrefs).not.toContain(`${baseURL}/titles/browser-smoke`);
    expect(recommendationTitleHrefs).toHaveLength(2);
    expect(new Set(recommendationTitleHrefs).size).toBe(1);

    await page.goto('/login');
    await page.getByLabel('Электронная почта').fill('browser@example.com');
    await page.getByLabel('Пароль', { exact: true }).fill('Browser-Strong-Password-42!');
    await page.getByRole('button', { name: 'Войти' }).click();
    await expect(page).toHaveURL(/\/library(?:\/|$)/);

    const personalizedResponse = await page.goto('/discover/personalized');
    const personalizedRows = page.locator('[data-recommendation-list] [data-recommendation-row]');

    expect(personalizedResponse?.status()).toBe(200);
    const preferences = page.locator('[data-recommendation-preferences]');

    await expect(preferences.getByRole('heading', { name: 'Настройка личных рекомендаций' })).toBeVisible();
    await expect(preferences.getByRole('button', { name: 'Больше разнообразия' })).toBeVisible();
    await expect(preferences.getByRole('button', { name: 'Больше новых' })).toBeVisible();
    await expect(preferences.getByRole('button', { name: 'Больше проверенных' })).toBeVisible();
    await expect(preferences.getByRole('button', { name: 'Сбросить профиль вкусов' })).toBeVisible();
    await preferences.getByRole('button', { name: 'Больше новых' }).click();
    await expect(page.getByText('Настройки личных рекомендаций сохранены.', { exact: true })).toBeVisible();
    await expect(preferences.getByRole('button', { name: 'Больше новых' })).toHaveAttribute('aria-pressed', 'true');
    await expect(personalizedRows.getByRole('link', { name: 'Рекомендованный браузерный сериал' })).toBeVisible();
    const personalizedReasonGroup = personalizedRows.first().locator('[aria-label="Почему это показано"]');

    await expect(personalizedReasonGroup.getByText('Почему это показано:', { exact: true })).toBeVisible();
    await expect(personalizedReasonGroup.locator('p > span')).toHaveCount(2);
    const feedback = personalizedRows.first().locator('[data-recommendation-feedback]');

    await feedback.getByText('Настроить рекомендацию', { exact: true }).click();
    await expect(feedback.getByText('Учтём интерес к похожим темам и признакам.', { exact: true })).toBeVisible();
    await feedback.getByText('Не интересует', { exact: true }).click();
    await expect(feedback.getByText('Почему рекомендация не подходит?', { exact: true })).toBeVisible();
    await expect(feedback.getByRole('button', { name: 'Уже смотрел в другом месте' })).toBeVisible();
    await expect(feedback.getByText('Не нравится жанр', { exact: true })).toBeVisible();
    await expect(feedback.getByRole('button', { name: 'Браузерная драма', exact: true })).toBeVisible();
    await expect(
        feedback.getByRole('button', { name: 'Скрыть жанр «Браузерная драма» на 30 дней', exact: true }),
    ).toBeVisible();
    await page.screenshot({
        path: `output/playwright/recommendation-feedback-${testInfo.project.name.toLowerCase().replaceAll(' ', '-')}.png`,
        fullPage: true,
    });

    const positiveFeedback = feedback.getByRole('button', { name: /Больше похожего/ });
    const positiveFeedbackBox = await positiveFeedback.boundingBox();

    expect(positiveFeedbackBox?.height).toBeGreaterThanOrEqual(44);
    await positiveFeedback.click();
    await expect(page.getByText('Будем чаще учитывать похожие сериалы в персональных рекомендациях.', { exact: true })).toBeVisible();
    await page.getByRole('button', { name: 'Отменить', exact: true }).click();
    await expect(page.getByText('Настройка рекомендации отменена.', { exact: true })).toBeVisible();
    await expect(page.getByText('Открыть тайтл', { exact: true })).toHaveCount(0);
    expect(await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth)).toBeLessThanOrEqual(1);

    const response = await page.goto('/library/watchlist');

    expect(response?.status()).toBe(200);
    await expect(page.locator('[data-library-watchlist-list]')).toBeVisible();
    await expect(page.locator('[data-user-card-state]')).toBeVisible();
    await expect(page.getByText('Открыть тайтл', { exact: true })).toHaveCount(0);
    expect(await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth)).toBeLessThanOrEqual(1);

    const continueResponse = await page.goto('/library/continue-watching');

    expect(continueResponse?.status()).toBe(200);
    await expect(page.locator('[data-library-continue-list]')).toBeVisible();
    await expect(page.getByRole('link', { name: /^Продолжить/ })).toBeVisible();
    await expect(page.getByText('Открыть тайтл', { exact: true })).toHaveCount(0);
    expect(await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth)).toBeLessThanOrEqual(1);
    expect(browserErrors).toEqual([]);
});
