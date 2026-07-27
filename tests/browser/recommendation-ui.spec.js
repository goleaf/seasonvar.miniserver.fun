import { expect, test } from '@playwright/test';

test('title recommendations stay compact, explain similarity and reveal six more', async ({ page, baseURL }, testInfo) => {
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
        expect(await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth)).toBeLessThanOrEqual(1);
    }

    await page.goto('/titles/browser-smoke');
    await expect(page.locator('[data-title-card-details]').first()).toBeVisible();
    const primaryRecommendationRows = page.locator('[data-recommendation-primary-list] > [data-recommendation-row]');
    const additionalRecommendationRows = page.locator('[data-recommendation-additional-list] > [data-recommendation-row]');

    await expect(primaryRecommendationRows).toHaveCount(6);
    await expect(additionalRecommendationRows).toHaveCount(6);
    await expect(primaryRecommendationRows.first().getByRole('link', { name: 'Рекомендованный браузерный сериал' })).toBeVisible();
    await expect(primaryRecommendationRows.first().getByRole('link', { name: 'Подробнее' })).toBeVisible();
    await expect(primaryRecommendationRows.first().getByText('Почему похож:', { exact: true })).toBeVisible();
    await expect(
        primaryRecommendationRows.first().locator('[data-recommendation-reasons] p:last-child > span:not([aria-hidden])'),
    ).toHaveCount(3);
    await expect(primaryRecommendationRows.first().getByText('2015', { exact: true })).toBeVisible();
    await expect(primaryRecommendationRows.first().getByText('1 сезон', { exact: true })).toBeVisible();
    await expect(primaryRecommendationRows.first().getByText('IMDb 7,7', { exact: true })).toBeVisible();

    const recommendationDescription = primaryRecommendationRows.first().locator('[data-title-card-description]');

    await expect(recommendationDescription).toHaveClass(/line-clamp-2/);
    expect(await recommendationDescription.evaluate((element) => {
        const lineHeight = Number.parseFloat(getComputedStyle(element).lineHeight);

        return element.clientHeight <= Math.ceil(lineHeight * 2) + 1;
    })).toBe(true);

    const firstRecommendationBox = await primaryRecommendationRows.first().boundingBox();

    expect(firstRecommendationBox?.height).toBeLessThan(440);
    await expect(additionalRecommendationRows.first()).toBeHidden();
    await page.getByText(/^Показать ещё 6 рекомендаций$/).click();
    await expect(additionalRecommendationRows.first()).toBeVisible();
    const recommendationHrefs = await page.locator('[data-recommendation-row] a[href*="/titles/"]').evaluateAll(
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

    await page.goto('/titles/browser-smoke');
    const compactFeedback = page.locator('[data-recommendation-feedback-compact]').first();
    const notSimilar = compactFeedback.getByRole('button', { name: 'Не похоже', exact: true });

    await expect(compactFeedback).toBeVisible();
    await expect(notSimilar).toBeVisible();
    expect((await notSimilar.boundingBox())?.height).toBeGreaterThanOrEqual(44);
    await notSimilar.focus();
    await expect(notSimilar).toBeFocused();
    await notSimilar.click();
    await expect(page.getByText('Причина учтена. Этот сериал скрыт, а следующие рекомендации будут точнее.', { exact: true })).toBeVisible();

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
    await expect(
        personalizedRows.first().getByRole('link', { name: /^Рекомендованный браузерный сериал \d{2}$/ }),
    ).toBeVisible();
    const personalizedReasonGroup = personalizedRows.first().locator('[aria-label="Почему это показано"]');

    await expect(personalizedReasonGroup.getByText('Почему это показано:', { exact: true })).toBeVisible();
    const personalizedReasons = personalizedReasonGroup.locator('p:last-child > span:not([aria-hidden])');
    const personalizedReasonCount = await personalizedReasons.count();

    expect(personalizedReasonCount).toBeGreaterThanOrEqual(1);
    expect(personalizedReasonCount).toBeLessThanOrEqual(3);
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
