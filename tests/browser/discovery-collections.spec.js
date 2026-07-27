import { expect, test } from '@playwright/test';

const installBrowserGuard = (page, baseURL) => {
    const errors = [];
    const localOrigin = new URL(baseURL).origin;

    page.on('console', (message) => {
        if (message.type() === 'error' && !message.text().startsWith('Failed to load resource:')) {
            errors.push(`console: ${message.text()}`);
        }
    });
    page.on('pageerror', (error) => errors.push(`page: ${error.message}`));
    page.on('response', (response) => {
        if (new URL(response.url()).origin === localOrigin && response.status() >= 400) {
            errors.push(`${response.status()} ${response.url()}`);
        }
    });

    return errors;
};

const assertResponsivePage = async (page) => {
    expect(await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth)).toBeLessThanOrEqual(1);
};

const login = async (page, email) => {
    await page.goto('/ru/login');
    await page.getByLabel('Электронная почта').fill(email);
    await page.getByLabel('Пароль', { exact: true }).fill('Browser-Strong-Password-42!');
    await page.getByRole('button', { name: 'Войти' }).click();
    await expect(page).toHaveURL(/\/library(?:\?|$)/);
};

test('discovery and collection taxonomy stay text-only and responsive', async ({ page, baseURL }, testInfo) => {
    test.setTimeout(150_000);

    const browserErrors = installBrowserGuard(page, baseURL);
    const popularResponse = await page.goto('/discover/popular');

    expect(popularResponse?.status()).toBe(200);
    await expect(page.getByRole('heading', { level: 1, name: 'Популярные' })).toBeVisible();
    await expect(page.locator('[data-discovery-type-link]')).toHaveCount(9);
    await expect(page.locator('[data-discovery-title-results]')).toBeVisible();
    await expect(page.locator('[data-discovery-collection-results]')).toBeVisible();
    await expect(page.locator('[data-discovery-filters]')).not.toHaveAttribute('open', '');
    await expect(page.getByRole('heading', { level: 2, name: 'Подборки сериалов' })).toBeVisible();
    await expect(page.locator('[data-collection-explorer] img')).toHaveCount(0);
    const categoryTree = page.locator('[data-collection-category-tree]');

    await expect(categoryTree).toBeVisible();
    await expect(categoryTree.locator('[data-collection-category]')).toHaveCount(5);
    await expect(categoryTree.locator('[data-collection-subcategory]')).toHaveCount(31);
    await expect(categoryTree.getByText('Формат', { exact: true })).toBeVisible();
    await expect(categoryTree.getByText('Мини-сериалы', { exact: true })).toBeVisible();
    await expect(page.getByText('Браузерная подборка детективов', { exact: true })).toBeVisible();
    const detectiveCollectionCard = page.locator('[data-collection-explorer] article').filter({
        hasText: 'Браузерная подборка детективов',
    });
    await expect(detectiveCollectionCard.locator('[data-collection-quality-score="82"]')).toBeVisible();
    await expect(detectiveCollectionCard.getByText('Качество: 82/100', { exact: true })).toBeVisible();

    const headerCollectionHrefs = await page
        .locator('[data-site-header-primary-navigation] a')
        .filter({ hasText: 'Подборки' })
        .evaluateAll((links) => links.map((link) => link.getAttribute('href')));

    expect(headerCollectionHrefs.length).toBeGreaterThan(0);
    expect(headerCollectionHrefs.every((href) => href?.endsWith('/discover/popular#collections'))).toBe(true);

    const sectionNavigation = page.locator('[data-discovery-section-navigation]');
    const collectionSectionLink = sectionNavigation.getByRole('link', { name: 'Подборки', exact: true });
    const titleSectionLink = sectionNavigation.getByRole('link', { name: 'Популярные сериалы', exact: true });

    await expect(sectionNavigation).toBeVisible();
    await expect(collectionSectionLink).toBeVisible();
    await expect(titleSectionLink).toBeVisible();

    const collectionDocumentY = await page
        .locator('[data-discovery-collection-results]')
        .evaluate((element) => element.getBoundingClientRect().top + window.scrollY);
    const titleDocumentY = await page
        .locator('[data-discovery-title-results]')
        .evaluate((element) => element.getBoundingClientRect().top + window.scrollY);

    expect(collectionDocumentY).toBeLessThan(titleDocumentY);

    await titleSectionLink.click();
    await expect(page).toHaveURL(/#popular-titles$/);
    await collectionSectionLink.click();
    await expect(page).toHaveURL(/#collections$/);

    if ((page.viewportSize()?.width ?? 0) < 640) {
        const recommendationTitleBox = await page.locator('[data-recommendation-row] h3').first().boundingBox();

        expect(recommendationTitleBox?.width).toBeGreaterThanOrEqual(150);
    }

    await categoryTree.getByRole('button', { name: /Темы и жанры/ }).click();
    await categoryTree.getByRole('button', { name: /Детективы и криминал/ }).click();

    await expect(page).toHaveURL(/collections_category=themes-and-genres/);
    await expect(page).toHaveURL(/collections_subcategory=detective-and-crime/);
    await expect(page.getByText('Браузерная подборка детективов', { exact: true })).toBeVisible();
    await assertResponsivePage(page);

    const refreshBox = await page.locator('[data-discovery-refresh-secondary]').boundingBox();

    expect(refreshBox?.height).toBeGreaterThanOrEqual(44);
    await page.screenshot({
        path: `output/playwright/discovery-popular-${testInfo.project.name.toLowerCase().replaceAll(' ', '-')}.png`,
        fullPage: true,
    });

    const randomResponse = await page.goto('/discover/random');

    expect(randomResponse?.status()).toBe(200);
    await expect(page.getByRole('heading', { level: 1, name: 'Случайная находка' })).toBeVisible();
    await expect(page.locator('[data-discovery-collection-results]')).toBeVisible();
    await expect(page.locator('[data-collection-category-tree] [data-collection-category]')).toHaveCount(5);
    await expect(page.locator('[data-collection-category-tree] [data-collection-subcategory]')).toHaveCount(31);
    await expect(page.locator('[data-discovery-section-navigation] a[href="#discovery-titles"]')).toBeVisible();
    await expect(page.locator('#discovery-titles')).toBeVisible();
    await assertResponsivePage(page);

    const editorialResponse = await page.goto('/discover/editorial');

    expect(editorialResponse?.status()).toBe(200);
    await expect(page.getByRole('heading', { level: 1, name: 'Выбор редакции' })).toBeVisible();
    await expect(page.locator('[data-discovery-collection-results]')).toBeVisible();
    expect(await page.locator('[data-recommendation-row]').count()).toBeGreaterThan(0);
    await assertResponsivePage(page);

    const englishResponse = await page.goto('/en/discover/random');

    expect(englishResponse?.status()).toBe(200);
    await expect(page.getByRole('heading', { level: 1, name: 'Random discovery' })).toBeVisible();
    await expect(page.getByRole('heading', { level: 2, name: 'Series collections' })).toBeVisible();
    await expect(page.locator('[data-collection-category-tree]').getByText('Format', { exact: true })).toBeVisible();
    await assertResponsivePage(page);

    await login(page, 'browser@example.com');
    await page.goto('/collections/browser-detective-collection');
    await expect(page.getByRole('heading', { level: 1, name: 'Браузерная подборка детективов' })).toBeVisible();
    await expect(page.getByText('Темы и жанры › Детективы и криминал', { exact: true }).first()).toBeVisible();
    await expect(page.locator('[data-collection-quality-score="82"]')).toBeVisible();
    await expect(page.locator('[data-collection-theme-match="80"]')).toBeVisible();
    await expect(page.getByText('название или описание соответствует теме', { exact: true })).toBeVisible();
    await expect(page.locator('article').first().locator('img')).toHaveCount(0);
    await page.getByRole('link', { name: 'Управлять' }).click();
    await expect(page.locator('#collection-edit-category-root option:checked')).toHaveText('Темы и жанры');
    await expect(page.locator('#collection-edit-category-child option:checked')).toHaveText('Детективы и криминал');
    await assertResponsivePage(page);

    await page.locator('[data-header-account-menu] summary').click();
    await page.getByRole('button', { name: 'Выйти', exact: true }).click();
    await expect(page).toHaveURL(/\/(?:ru\/?)?$/);
    await login(page, 'browser-admin@example.com');
    await page.goto('/admin/catalog?section=collections');
    const classification = page.locator('[data-collection-classification]');

    await expect(classification.getByRole('heading', { level: 2, name: 'Классификация подборок' })).toBeVisible();
    await expect(classification.locator('[data-classification-summary]')).toBeVisible();
    await expect(classification.locator('#classification-visibility')).toHaveValue('public');
    await expect(classification.locator('#classification-moderation')).toHaveValue('approved');
    await expect(classification.getByText('Браузерная подборка Netflix', { exact: true })).toBeVisible();
    await expect(
        classification.locator('p').filter({ hasText: 'Платформы и студии — Netflix' }),
    ).toBeVisible();
    await expect(classification.getByText('Высокая уверенность', { exact: true })).toBeVisible();
    await expect(classification.locator('img')).toHaveCount(0);
    await classification.getByRole('button', { name: 'Выбрать страницу' }).click();
    await expect(classification.getByText('Выбрано: 1', { exact: true })).toBeVisible();

    const batchCategory = classification.locator('#classification-batch-category');

    await batchCategory.selectOption({ label: 'Темы и жанры — Детективы и криминал' });
    const stagedBatchCategory = await batchCategory.inputValue();
    await classification.getByRole('button', { name: 'Применить к выбранным' }).click();
    await expect(classification.locator('select[id^="classification-category-"]')).toHaveValue(stagedBatchCategory);
    await expect(classification.locator('[data-classification-preview]')).toHaveCount(0);

    await classification.getByRole('button', { name: 'Очистить выбор' }).click();
    await expect(classification.getByText('Выбрано: 0', { exact: true })).toBeVisible();
    await classification.getByRole('button', { name: 'Принять рекомендацию' }).click();
    await expect(classification.getByText('Выбрано: 1', { exact: true })).toBeVisible();
    await expect(classification.locator('select[id^="classification-category-"] option:checked')).toHaveText(
        'Платформы и студии — Netflix',
    );
    await classification.getByRole('button', { name: 'Проверить выбранное' }).click();
    await expect(classification.locator('[data-classification-preview]')).toBeVisible();
    await expect(classification.getByRole('button', { name: 'Подтвердить назначения' })).toBeVisible();
    await classification.getByRole('button', { name: 'Вернуться к выбору' }).click();
    await expect(classification.locator('[data-classification-preview]')).toHaveCount(0);
    await expect(page.getByText('Категории и подкатегории', { exact: true })).toBeVisible();

    const moderationManager = page.locator('[data-livewire-catalog-collection-administration-manager]');
    const readyEditorialCard = moderationManager.locator('article').filter({
        hasText: 'Готовая браузерная редакционная подборка',
    });
    const thinEditorialCard = moderationManager.locator('article').filter({
        hasText: 'Тонкая браузерная редакционная подборка',
    });

    await expect(readyEditorialCard.locator('[data-collection-readiness]')).toHaveAttribute(
        'data-collection-readiness-state',
        'ready',
    );
    await expect(readyEditorialCard.getByText('Готова к редакционному показу', { exact: true })).toBeVisible();
    await expect(readyEditorialCard.getByText('Доступно гостю: 12 из 12 · минимум: 12', { exact: true })).toBeVisible();
    await expect(readyEditorialCard.locator('[data-collection-quality-components]')).toBeVisible();
    await expect(readyEditorialCard.locator('[data-collection-quality-signals]')).toBeVisible();
    await expect(readyEditorialCard.getByText('Проверено редакцией', { exact: true })).toBeVisible();
    await expect(readyEditorialCard.locator('[data-collection-feature-action]')).toHaveCount(1);
    await expect(thinEditorialCard.locator('[data-collection-readiness]')).toHaveAttribute(
        'data-collection-readiness-state',
        'not-ready',
    );
    await expect(thinEditorialCard.getByText('Не готова к редакционному показу', { exact: true })).toBeVisible();
    await expect(thinEditorialCard.getByText('Недостаточно доступных для просмотра сериалов.', { exact: true })).toBeVisible();
    await expect(thinEditorialCard.locator('[data-collection-feature-action]')).toHaveCount(0);
    await expect(moderationManager.locator('img')).toHaveCount(0);

    const createDisclosure = page.locator('[data-category-create-disclosure]');
    const categoryDisclosures = page.locator('[data-category-root-disclosure]');

    await expect(createDisclosure).not.toHaveAttribute('open', '');
    await expect(page.locator('[data-category-create-form]')).not.toBeVisible();
    await expect(categoryDisclosures).toHaveCount(5);
    await expect(categoryDisclosures.first()).not.toHaveAttribute('open', '');
    await categoryDisclosures.first().locator('summary').click();
    await expect(categoryDisclosures.first()).toHaveAttribute('open', '');
    await expect(categoryDisclosures.first().getByText('Детективы и криминал', { exact: true })).toBeVisible();
    await assertResponsivePage(page);
    await page.screenshot({
        path: `output/playwright/collection-classification-${testInfo.project.name.toLowerCase().replaceAll(' ', '-')}.png`,
        fullPage: true,
    });

    expect(browserErrors).toEqual([]);
});
