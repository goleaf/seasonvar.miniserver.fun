import AxeBuilder from '@axe-core/playwright';
import { expect, test } from '@playwright/test';

const loginEmail = 'browser@example.com';
const loginPassword = 'Browser-Strong-Password-42!';

const projectRating = (projectName) => ({
    'Desktop Chromium': 7,
    'Mobile Chromium': 8,
    'Tablet Chromium': 9,
}[projectName] || 6);

const waitForStoredSnapshots = async (page) => {
    await page.waitForFunction(() => new Promise((resolve) => {
        const request = indexedDB.open('seasonvar-pwa', 1);

        request.onerror = () => resolve(false);
        request.onsuccess = () => {
            const database = request.result;

            if (!database.objectStoreNames.contains('snapshots')) {
                database.close();
                resolve(false);
                return;
            }

            const transaction = database.transaction('snapshots', 'readonly');
            const records = transaction.objectStore('snapshots').getAll();

            records.onerror = () => resolve(false);
            records.onsuccess = () => {
                const snapshots = records.result || [];

                database.close();
                resolve(
                    snapshots.some((record) => record.key?.startsWith('library:') && record.items?.length > 0)
                    && snapshots.some((record) => record.key === 'help:ru' && record.items?.length > 0),
                );
            };
        };
    }), null, { timeout: 20_000 });
};

const storedAccountRecords = async (page) => page.evaluate(() => new Promise((resolve) => {
    const request = indexedDB.open('seasonvar-pwa', 1);

    request.onerror = () => resolve({ snapshots: [], actions: [] });
    request.onsuccess = () => {
        const database = request.result;
        const transaction = database.transaction(['snapshots', 'actions'], 'readonly');
        const snapshots = transaction.objectStore('snapshots').getAll();
        const actions = transaction.objectStore('actions').getAll();

        transaction.oncomplete = () => {
            database.close();
            resolve({
                snapshots: snapshots.result || [],
                actions: actions.result || [],
            });
        };
    };
}));

test('installable PWA keeps only safe offline data and flushes owner actions', async ({ page, context }, testInfo) => {
    const consoleErrors = [];
    const pageErrors = [];

    page.on('console', (message) => {
        if (message.type() === 'error' && !message.text().startsWith('Failed to load resource:')) {
            consoleErrors.push(message.text());
        }
    });
    page.on('pageerror', (error) => pageErrors.push(error.message));

    await page.goto('/login');
    await page.getByLabel('Электронная почта').fill(loginEmail);
    await page.getByLabel('Пароль', { exact: true }).fill(loginPassword);
    await page.getByRole('button', { name: 'Войти' }).click();
    await expect(page).toHaveURL(/\/library(?:\?|$)/);

    const manifest = await page.evaluate(async () => {
        const response = await fetch('/manifest.webmanifest');

        return response.json();
    });

    expect(manifest.display).toBe('standalone');
    expect(manifest.icons.map((icon) => icon.sizes)).toEqual(expect.arrayContaining(['192x192', '512x512']));

    await page.evaluate(async () => {
        await navigator.serviceWorker.ready;

        return true;
    });

    if (!await page.evaluate(() => Boolean(navigator.serviceWorker.controller))) {
        await page.reload();
    }

    await page.waitForFunction(() => Boolean(navigator.serviceWorker.controller));
    await waitForStoredSnapshots(page);

    const cachedPaths = await page.evaluate(async () => {
        const paths = [];

        for (const cacheName of await caches.keys()) {
            const cache = await caches.open(cacheName);

            for (const request of await cache.keys()) {
                paths.push(new URL(request.url).pathname);
            }
        }

        return paths;
    });

    expect(cachedPaths).toEqual(expect.arrayContaining([
        '/offline',
        '/manifest.webmanifest',
        '/icons/pwa-192.png',
        '/icons/pwa-512.png',
        '/icons/pwa-maskable-512.png',
    ]));
    expect(cachedPaths.some((path) => (
        path.startsWith('/playback/')
        || path.startsWith('/download')
        || path.endsWith('.m3u8')
        || path.endsWith('.mp4')
        || path === '/pwa/library-snapshot'
        || path === '/pwa/session'
        || path === '/pwa/actions'
    ))).toBe(false);

    await page.goto('/settings/notifications');
    await expect(page.getByText('Push-уведомления сейчас недоступны.', { exact: true })).toBeVisible();
    await expect(page.locator('[data-pwa-push-enable]')).toHaveCount(0);

    await context.setOffline(true);
    await page.goto('/pwa-browser-offline-probe');
    await expect(page.getByRole('heading', { level: 1, name: 'Сохранённая копия' })).toBeVisible();
    await expect(page.getByRole('heading', { level: 2, name: 'Видео без сети недоступно' })).toBeVisible();
    await expect(page.getByText('Browser Smoke', { exact: true })).toBeVisible();
    await expect(page.getByText('Как работает сохранённая библиотека', { exact: true })).toBeVisible();

    const rating = projectRating(testInfo.project.name);
    const ratingSelect = page.locator('select[id="pwa-rating-browser-smoke"]');
    await ratingSelect.selectOption(String(rating));
    await expect(page.getByRole('status')).toContainText('Изменение сохранено');

    const queued = await storedAccountRecords(page);
    expect(queued.actions).toHaveLength(1);
    expect(queued.actions[0]).toMatchObject({
        type: 'rating.set',
        title_slug: 'browser-smoke',
        value: rating,
        sync_status: 'pending',
    });
    expect(JSON.stringify(queued)).not.toContain('playback_session');
    expect(JSON.stringify(queued)).not.toContain('source_url');

    const accessibility = await new AxeBuilder({ page })
        .withTags(['wcag2a', 'wcag2aa'])
        .analyze();
    expect(
        accessibility.violations.filter((violation) => ['critical', 'serious'].includes(violation.impact)),
    ).toEqual([]);

    const geometry = await page.evaluate(() => ({
        overflow: document.documentElement.scrollWidth - window.innerWidth,
    }));
    expect(geometry.overflow).toBeLessThanOrEqual(1);

    const actionResponse = page.waitForResponse((response) => (
        response.url().endsWith('/pwa/actions') && response.request().method() === 'POST'
    ));
    await context.setOffline(false);
    expect((await actionResponse).status()).toBe(200);
    await page.waitForFunction(() => new Promise((resolve) => {
        const request = indexedDB.open('seasonvar-pwa', 1);

        request.onerror = () => resolve(false);
        request.onsuccess = () => {
            const database = request.result;
            const transaction = database.transaction('actions', 'readonly');
            const records = transaction.objectStore('actions').getAll();

            records.onsuccess = () => {
                database.close();
                resolve(records.result.length === 0);
            };
        };
    }));

    await page.goto('/');
    await page.locator('[data-header-account-menu] > summary').click();
    await page.locator('[data-pwa-logout]').click();
    await expect(page).toHaveURL(/\/$/);

    const afterLogout = await storedAccountRecords(page);
    expect(afterLogout.actions).toEqual([]);
    expect(afterLogout.snapshots.filter((record) => record.key?.startsWith('library:'))).toEqual([]);
    expect(afterLogout.snapshots.some((record) => record.key === 'help:ru')).toBe(true);
    expect(consoleErrors).toEqual([]);
    expect(pageErrors).toEqual([]);
});
