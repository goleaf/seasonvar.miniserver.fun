<?php

namespace Tests\Unit;

use Illuminate\Support\Facades\File;
use SplFileInfo;
use Tests\TestCase;

class ConfigurationEnvironmentTest extends TestCase
{
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
