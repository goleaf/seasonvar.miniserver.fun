<?php

namespace Tests\Unit;

use Illuminate\Support\Facades\File;
use SplFileInfo;
use Tests\TestCase;

class ConfigurationEnvironmentTest extends TestCase
{
    public function test_google_integration_placeholders_are_documented(): void
    {
        $envExample = File::get(base_path('.env.example'));

        foreach ([
            'GOOGLE_APPLICATION_CREDENTIALS=',
            'GOOGLE_CLOUD_PROJECT=',
            'GOOGLE_PROJECT_ID=',
            'GOOGLE_SEARCH_CONSOLE_ENABLED=false',
            'GOOGLE_SEARCH_CONSOLE_SITE_URL=https://seasonvar.miniserver.fun/',
            'GOOGLE_SEARCH_CONSOLE_READONLY=true',
            'GOOGLE_ANALYTICS_ENABLED=false',
            'GOOGLE_ANALYTICS_PROPERTY_ID=',
        ] as $placeholder) {
            $this->assertStringContainsString($placeholder, $envExample);
        }

        $this->assertIsArray(config('services.google'));
        $this->assertIsArray(config('services.google.search_console'));
        $this->assertIsArray(config('services.google.analytics'));
    }

    public function test_env_helper_is_only_used_in_configuration_files(): void
    {
        $directories = [
            app_path(),
            base_path('bootstrap'),
            database_path(),
            resource_path('views'),
            base_path('routes'),
        ];

        $offendingFiles = collect($directories)
            ->filter(fn (string $directory): bool => File::isDirectory($directory))
            ->flatMap(fn (string $directory) => File::allFiles($directory))
            ->filter(fn (SplFileInfo $file): bool => in_array($file->getExtension(), ['php'], true) || str_ends_with($file->getFilename(), '.blade.php'))
            ->filter(fn (SplFileInfo $file): bool => preg_match('/\benv\s*\(/', (string) file_get_contents($file->getPathname())) === 1)
            ->map(fn (SplFileInfo $file): string => str_replace(base_path().'/', '', $file->getPathname()))
            ->values()
            ->all();

        $this->assertSame([], $offendingFiles, 'Use the environment helper only in config/*.php; application code must read values through config().');
    }
}
