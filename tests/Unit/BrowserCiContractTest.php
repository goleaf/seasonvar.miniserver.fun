<?php

declare(strict_types=1);

namespace Tests\Unit;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

final class BrowserCiContractTest extends TestCase
{
    public function test_repository_defines_a_deterministic_playwright_and_axe_matrix(): void
    {
        $package = json_decode(File::get(base_path('package.json')), true, flags: JSON_THROW_ON_ERROR);
        $config = File::get(base_path('playwright.config.js'));
        $fixtures = File::get(base_path('tests/browser/prepare-fixtures.php'));
        $suite = File::get(base_path('tests/browser/catalog.spec.js'));
        $authSuite = File::get(base_path('tests/browser/auth-portal.spec.js'));

        $this->assertSame('playwright test', $package['scripts']['test:browser']);
        $this->assertSame('playwright install chromium', $package['scripts']['test:browser:install']);
        $this->assertArrayHasKey('@playwright/test', $package['devDependencies']);
        $this->assertArrayHasKey('@axe-core/playwright', $package['devDependencies']);

        foreach (['Desktop Chromium', 'Tablet Chromium', 'Mobile Chromium', '1440', '768', '390', 'output/playwright'] as $contract) {
            $this->assertStringContainsString($contract, $config);
        }

        $this->assertStringContainsString('DB_DATABASE', $config);
        $this->assertStringContainsString("process.env.PLAYWRIGHT_RUNTIME_NAME || 'browser'", $config);
        $this->assertStringContainsString('output/playwright/${runtimeName}.sqlite', $config);
        $this->assertStringContainsString('BROWSER_TEST_DATABASE', $config);
        $this->assertStringContainsString('APP_CONFIG_CACHE', $config);
        $this->assertStringContainsString('output/playwright/${runtimeName}-config.php', $config);
        $this->assertStringContainsString('APP_ROUTES_CACHE', $config);
        $this->assertStringContainsString('output/playwright/${runtimeName}-routes-v7.php', $config);
        $this->assertStringContainsString("SESSION_DRIVER: 'database'", $config);
        $this->assertStringContainsString('CatalogTitle::factory()', $fixtures);
        $this->assertStringContainsString('LicensedMedia::factory()', $fixtures);
        $this->assertStringContainsString('browser@example.com', $fixtures);
        $this->assertStringContainsString('AxeBuilder', $suite);
        $this->assertStringContainsString("withTags(['wcag2a', 'wcag2aa'])", $suite);
        $this->assertStringContainsString("['critical', 'serious'].includes(violation.impact)", $suite);
        $this->assertStringContainsString("route('**/*'", $suite);
        $this->assertStringContainsString('isExternalRequest', $suite);
        $this->assertStringContainsString('scrollWidth', $suite);
        $this->assertStringContainsString('min-height', $suite);
        $this->assertStringContainsString('Browser-Strong-Password-42!', $authSuite);
        $this->assertStringContainsString('data-progress-position', $authSuite);
        $this->assertStringContainsString('sameOriginFailures', $authSuite);
    }

    public function test_ci_installs_chromium_and_runs_the_browser_suite_against_local_fixtures(): void
    {
        $workflow = File::get(base_path('.github/workflows/ci.yml'));

        foreach ([
            'browser:',
            'npm run test:browser:install',
            'tests/browser/prepare-fixtures.php',
            'npm run test:browser',
            'output/playwright/browser.sqlite',
            'playwright-report',
        ] as $contract) {
            $this->assertStringContainsString($contract, $workflow);
        }
    }
}
