import { defineConfig } from '@playwright/test';
import path from 'node:path';

const browserPort = process.env.PLAYWRIGHT_PORT || '8013';
const runtimeName = process.env.PLAYWRIGHT_RUNTIME_NAME || 'browser';
const databasePath = path.resolve(`output/playwright/${runtimeName}.sqlite`);
const configCachePath = path.resolve(`output/playwright/${runtimeName}-config.php`);
const routesCachePath = path.resolve(`output/playwright/${runtimeName}-routes-v7.php`);
const baseURL = `http://127.0.0.1:${browserPort}`;
const deviceMatrix = process.env.PLAYWRIGHT_DEVICE_MATRIX || 'default';

const defaultProjects = [
    {
        name: 'Desktop Chromium',
        use: {
            browserName: 'chromium',
            viewport: { width: 1440, height: 1200 },
        },
    },
    {
        name: 'Desktop Firefox',
        testMatch: /player-lifecycle\.spec\.js/,
        use: {
            browserName: 'firefox',
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
];

const extendedProjects = [
    {
        name: 'Narrow Phone Chromium',
        use: {
            browserName: 'chromium',
            viewport: { width: 320, height: 720 },
            hasTouch: true,
            isMobile: true,
        },
    },
    {
        name: 'Phone Landscape Chromium',
        use: {
            browserName: 'chromium',
            viewport: { width: 844, height: 390 },
            hasTouch: true,
            isMobile: true,
        },
    },
    {
        name: 'Tablet Landscape Chromium',
        use: {
            browserName: 'chromium',
            viewport: { width: 1024, height: 768 },
            hasTouch: true,
        },
    },
    {
        name: 'TV-like Chromium',
        use: {
            browserName: 'chromium',
            viewport: { width: 1920, height: 1080 },
        },
    },
];

export default defineConfig({
    testDir: './tests/browser',
    fullyParallel: false,
    forbidOnly: Boolean(process.env.CI),
    retries: process.env.CI ? 1 : 0,
    workers: 1,
    timeout: 60_000,
    expect: {
        timeout: 15_000,
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
            CACHE_DOMAIN_STORE: 'array',
            CACHE_HOT_STORE: 'array',
            CACHE_LOCK_STORE: 'array',
            CACHE_METRICS_STORE: 'array',
            CACHE_STORE: 'array',
            CACHE_VERSION_STORE: 'array',
            DB_CONNECTION: 'sqlite',
            DB_DATABASE: databasePath,
            BROWSER_TEST_DATABASE: databasePath,
            MAIL_MAILER: 'array',
            PLAYBACK_ALLOWED_HOSTS: 'media.example.com',
            PLAYBACK_ENFORCE_PUBLIC_DNS: 'false',
            QUEUE_CONNECTION: 'sync',
            SECURITY_CSP_CONNECT_SOURCES: 'https://media.example.com',
            SECURITY_CSP_MEDIA_SOURCES: 'https://media.example.com,data:',
            SESSION_DRIVER: 'database',
        },
    },
    projects: deviceMatrix === 'extended'
        ? [...defaultProjects, ...extendedProjects]
        : defaultProjects,
});
