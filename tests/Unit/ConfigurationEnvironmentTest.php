<?php

namespace Tests\Unit;

use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Support\Facades\File;
use SplFileInfo;
use Tests\TestCase;

class ConfigurationEnvironmentTest extends TestCase
{
    public function test_laravel_13_security_defaults_are_explicit(): void
    {
        $this->assertFalse(config('cache.serializable_classes'));
        $this->assertSame(
            PreventRequestForgery::class,
            config('sanctum.middleware.validate_csrf_token'),
        );
    }

    public function test_remote_http_response_limit_is_documented(): void
    {
        $envExample = File::get(base_path('.env.example'));

        $this->assertStringContainsString('SEASONVAR_HTTP_MAX_RESPONSE_BYTES=8388608', $envExample);
        $this->assertSame(8_388_608, config('seasonvar.http.max_response_bytes'));
    }

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

    public function test_private_upload_storage_configuration_is_documented(): void
    {
        $envExample = File::get(base_path('.env.example'));

        foreach ([
            'UPLOADS_DISK=uploads',
            'UPLOADS_MAX_IMAGE_KILOBYTES=2048',
        ] as $placeholder) {
            $this->assertStringContainsString($placeholder, $envExample);
        }

        $this->assertSame('uploads', config('uploads.disk'));
        $this->assertSame('private', config('uploads.visibility'));
        $this->assertSame('private', config('filesystems.disks.uploads.visibility'));
        $this->assertFalse(config('filesystems.disks.uploads.serve'));
    }

    public function test_notification_configuration_is_documented(): void
    {
        $envExample = File::get(base_path('.env.example'));

        foreach ([
            'NOTIFICATIONS_MAIL_QUEUE=default',
            'SEASONVAR_IMPORT_FAILURE_MAIL_TO=',
            'SEASONVAR_IMPORT_FAILURE_MAIL_TO_NAME=',
        ] as $placeholder) {
            $this->assertStringContainsString($placeholder, $envExample);
        }

        $this->assertSame('default', config('notifications.mail_queue'));
        $this->assertNull(config('notifications.seasonvar_import_failed.mail_to'));
        $this->assertNull(config('notifications.seasonvar_import_failed.mail_to_name'));
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
