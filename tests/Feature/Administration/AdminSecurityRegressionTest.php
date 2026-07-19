<?php

declare(strict_types=1);

namespace Tests\Feature\Administration;

use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Tests\TestCase;

final class AdminSecurityRegressionTest extends TestCase
{
    #[Test]
    public function administration_page_routes_are_read_only_and_have_an_action_permission_boundary(): void
    {
        $routes = collect(Route::getRoutes()->getRoutes())
            ->filter(fn (RoutingRoute $route): bool => str_starts_with((string) $route->getName(), 'admin.'));

        self::assertNotEmpty($routes);

        foreach ($routes as $route) {
            self::assertSame(['GET', 'HEAD'], $route->methods(), (string) $route->getName());
            self::assertTrue(
                collect($route->gatherMiddleware())->contains(
                    fn (string $middleware): bool => str_starts_with($middleware, 'Illuminate\\Auth\\Middleware\\Authorize:')
                        || str_starts_with($middleware, 'can:'),
                ),
                (string) $route->getName(),
            );
            self::assertDoesNotMatchRegularExpression('/(?:delete|destroy|revoke|invalidate|reindex)$/i', $route->uri());
        }
    }

    #[Test]
    public function canonical_administration_blade_has_no_forbidden_execution_or_inline_asset_patterns(): void
    {
        foreach ($this->administrationBladeFiles() as $path) {
            $contents = file_get_contents($path);
            self::assertIsString($contents, $path);

            foreach (['@php', '::query(', 'DB::', '{!!', '<style', '<script', 'onclick='] as $forbidden) {
                self::assertStringNotContainsString($forbidden, $contents, $path.':'.$forbidden);
            }

            self::assertDoesNotMatchRegularExpression('/(?:href|action)=["\']\/admin(?:\/|["\'])/', $contents, $path);
        }
    }

    /** @return list<string> */
    private function administrationBladeFiles(): array
    {
        $files = [];

        foreach ([resource_path('views/livewire/administration'), resource_path('views/components/administration')] as $directory) {
            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory));

            foreach ($iterator as $file) {
                if ($file instanceof SplFileInfo && $file->isFile() && $file->getExtension() === 'php') {
                    $files[] = $file->getPathname();
                }
            }
        }

        sort($files);

        return $files;
    }
}
