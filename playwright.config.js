import { defineConfig } from '@playwright/test';
import path from 'node:path';

const browserPort = process.env.PLAYWRIGHT_PORT || '8013';
const runtimeName = process.env.PLAYWRIGHT_RUNTIME_NAME || 'browser';
const databasePath = path.resolve(`output/playwright/${runtimeName}.sqlite`);
const configCachePath = path.resolve(`output/playwright/${runtimeName}-config.php`);
const routesCachePath = path.resolve(`output/playwright/${runtimeName}-routes-v7.php`);
const baseURL = `http://127.0.0.1:${browserPort}`;

export default defineConfig({
    testDir: './tests/browser',
    fullyParallel: false,
    forbidOnly: Boolean(process.env.CI),
    retries: process.env.CI ? 1 : 0,
    workers: 1,
    timeout: 30_000,
    expect: {
        timeout: 8_000,
    },
    outputDir: `output/playwright/${runtimeName}-test-results`,
    reporter: [
        ['line'],
        ['html', { outputFolder: `output/playwright/${runtimeName}-report`, open: 'never' }],
    ],
    use: {
        baseURL,
        screenshot: 'only-on-failure',
        trace: 'retain-on-failure',
        video: 'retain-on-failure',
    },
    webServer: {
        command: `php tests/browser/prepare-fixtures.php && php artisan serve --host=127.0.0.1 --port=${browserPort}`,
        url: baseURL,
        reuseExistingServer: false,
        timeout: 120_000,
        env: {
            ...process.env,
            APP_ENV: 'testing',
            APP_DEBUG: 'false',
            APP_KEY: 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=',
            APP_URL: baseURL,
            APP_CONFIG_CACHE: configCachePath,
            APP_ROUTES_CACHE: routesCachePath,
            CACHE_STORE: 'array',
            DB_CONNECTION: 'sqlite',
            DB_DATABASE: databasePath,
            BROWSER_TEST_DATABASE: databasePath,
            MAIL_MAILER: 'array',
            PLAYBACK_ALLOWED_HOSTS: 'media.example.com',
            PLAYBACK_ENFORCE_PUBLIC_DNS: 'false',
            QUEUE_CONNECTION: 'sync',
            SESSION_DRIVER: 'database',
        },
    },
    projects: [
        {
            name: 'Desktop Chromium',
            use: {
                browserName: 'chromium',
                viewport: { width: 1440, height: 1200 },
            },
        },
        {
            name: 'Mobile Chromium',
            use: {
                browserName: 'chromium',
                viewport: { width: 390, height: 844 },
                hasTouch: true,
                isMobile: true,
            },
        },
        {
            name: 'Tablet Chromium',
            use: {
                browserName: 'chromium',
                viewport: { width: 768, height: 1024 },
                hasTouch: true,
            },
        },
    ],
});
