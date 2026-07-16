<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\View\ViewData\AppLayoutData;
use App\View\ViewModels\LayoutNavigationItem;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Tests\TestCase;

final class AppLayoutOptionalNavigationTest extends TestCase
{
    public function test_guest_layout_omits_navigation_for_unregistered_optional_routes(): void
    {
        $homeRoute = $this->app->make(Router::class)->getRoutes()->getByName('home');
        $this->app->make(Request::class)->setRouteResolver(static fn () => $homeRoute);

        $router = $this->createMock(Router::class);
        $router->method('has')->willReturn(false);
        $this->app->instance(Router::class, $router);

        $layout = $this->app->make(AppLayoutData::class)->from([]);

        $headerLabels = collect($layout['layoutHeader']['navigation'])
            ->map(fn (LayoutNavigationItem $item): string => $item->label)
            ->all();
        $footerLabels = collect($layout['layoutFooter']['navigation'])
            ->map(fn (LayoutNavigationItem $item): string => $item->label)
            ->all();

        $this->assertNotContains(__('collections.navigation.collections'), $headerLabels);
        $this->assertNotContains(__('collections.navigation.collections'), $footerLabels);
    }
}
