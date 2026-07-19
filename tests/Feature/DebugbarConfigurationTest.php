<?php

declare(strict_types=1);

namespace Tests\Feature;

use Fruitcake\LaravelDebugbar\LaravelDebugbar;
use Tests\TestCase;

final class DebugbarConfigurationTest extends TestCase
{
    public function test_project_configuration_uses_app_debug_without_force_enable(): void
    {
        self::assertTrue(class_exists(LaravelDebugbar::class));
        self::assertSame(config('app.debug'), config('debugbar.enabled'));
        self::assertFalse(config('debugbar.force_allow_enable'));
    }

    public function test_debugbar_can_boot_only_for_local_debug_mode(): void
    {
        self::assertTrue(class_exists(LaravelDebugbar::class));
        $this->app->detectEnvironment(static fn (): string => 'local');

        config([
            'app.env' => 'local',
            'app.debug' => true,
            'debugbar.enabled' => true,
            'debugbar.force_allow_enable' => false,
        ]);

        self::assertTrue(LaravelDebugbar::canBeEnabled());

        config([
            'app.debug' => false,
            'debugbar.enabled' => false,
        ]);

        self::assertFalse(LaravelDebugbar::canBeEnabled());
    }

    public function test_debugbar_remains_blocked_in_production_and_testing(): void
    {
        self::assertTrue(class_exists(LaravelDebugbar::class));
        $this->app->detectEnvironment(static fn (): string => 'production');

        config([
            'app.env' => 'production',
            'app.debug' => true,
            'debugbar.enabled' => true,
            'debugbar.force_allow_enable' => false,
        ]);

        self::assertFalse(LaravelDebugbar::canBeEnabled());

        $this->app->detectEnvironment(static fn (): string => 'testing');
        config(['app.env' => 'testing']);

        self::assertFalse(LaravelDebugbar::canBeEnabled());
    }
}
