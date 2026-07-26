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
    public function test_layout_prepares_the_new_desktop_and_mobile_navigation_groups(): void
    {
        $homeRoute = $this->app->make(Router::class)->getRoutes()->getByName('home');
        $this->app->make(Request::class)->setRouteResolver(static fn () => $homeRoute);

        $header = $this->app->make(AppLayoutData::class)->from([])['layoutHeader'];
        $primaryLabels = collect($header['primary_navigation'])
            ->map(fn (LayoutNavigationItem $item): string => $item->label)
            ->all();
        $moreLabels = collect($header['more_navigation'])
            ->map(fn (LayoutNavigationItem $item): string => $item->label)
            ->all();

        $this->assertSame([
            __('catalog.navigation.catalog'),
            __('recommendations.navigation.discover'),
            __('calendar.short_title'),
            __('top_lists.navigation'),
        ], $primaryLabels);
        $this->assertSame([
            __('requests.directory.title'),
            __('help.navigation'),
        ], $moreLabels);
        $this->assertSame(
            ['home', 'catalog', 'calendar', 'library'],
            array_keys($header['mobile_navigation']),
        );
        $this->assertSame(route('titles.index'), $header['catalog_search_url']);
        $this->assertSame(route('requests.create'), $header['request_create_url']);
        $this->assertNull($header['notification_action']);
        $this->assertCount(2, $header['account_navigation']);
    }

    public function test_default_locale_collection_navigation_opens_the_embedded_directory(): void
    {
        $this->assertCollectionNavigationUrl(
            routeName: 'discover.index',
            locale: 'ru',
            expectedUrl: route('discover.index', ['type' => 'popular']).'#collections',
        );
    }

    public function test_localized_collection_navigation_opens_the_embedded_directory(): void
    {
        $this->assertCollectionNavigationUrl(
            routeName: 'localized.discover.index',
            locale: 'en',
            expectedUrl: route('localized.discover.index', [
                'locale' => 'en',
                'type' => 'popular',
            ]).'#collections',
        );
    }

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

    private function assertCollectionNavigationUrl(
        string $routeName,
        string $locale,
        string $expectedUrl,
    ): void {
        $route = $this->app->make(Router::class)->getRoutes()->getByName($routeName);
        $this->app->make(Request::class)->setRouteResolver(static fn () => $route);
        $this->app->make('translator')->setLocale($locale);

        $layout = $this->app->make(AppLayoutData::class)->from([]);
        $headerItem = collect($layout['layoutHeader']['navigation'])
            ->firstWhere('label', __('recommendations.navigation.discover'));
        $footerItem = collect($layout['layoutFooter']['navigation'])
            ->firstWhere('label', __('recommendations.navigation.discover'));

        $this->assertInstanceOf(LayoutNavigationItem::class, $headerItem);
        $this->assertInstanceOf(LayoutNavigationItem::class, $footerItem);
        $this->assertSame($expectedUrl, $headerItem->url);
        $this->assertSame($expectedUrl, $footerItem->url);
    }
}
