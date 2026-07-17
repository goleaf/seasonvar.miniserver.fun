<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Enums\CatalogTopListCategory;
use App\Models\CatalogTitle;
use App\Models\User;
use App\Support\Cache\CacheDomain;
use App\Support\Cache\CacheVersionRegistry;
use App\Support\Cache\PublicPageCachePolicy;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Tests\TestCase;

final class PublicPageCachePolicyTest extends TestCase
{
    public function test_it_maps_profiles_to_bounded_canonical_contexts(): void
    {
        $homepage = app(PublicPageCachePolicy::class)->context(
            $this->request('GET', '/', 'home'),
            'homepage',
        );

        $this->assertNotNull($homepage);
        $this->assertSame(CacheDomain::Homepage, $homepage->domain);
        $this->assertSame('public', $homepage->versionScope);
        $this->assertSame('home', $homepage->dimensions['route']);

        $first = app(PublicPageCachePolicy::class)->context(
            $this->request('GET', '/titles?genre[0]=drama&genre[1]=comedy&year[0]=2026&year[1]=2025&page=2', 'titles.index'),
            'catalog',
        );
        $second = app(PublicPageCachePolicy::class)->context(
            $this->request('GET', '/titles?page=2&year[0]=2025&year[1]=2026&genre[0]=comedy&genre[1]=drama', 'titles.index'),
            'catalog',
        );

        $this->assertNotNull($first);
        $this->assertNotNull($second);
        $this->assertSame(CacheDomain::CatalogPages, $first->domain);
        $this->assertSame($first->dimensions, $second->dimensions);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $first->dimensions['query']);
    }

    public function test_title_context_uses_scoped_and_global_generations(): void
    {
        $title = new CatalogTitle;
        $title->forceFill(['id' => 73, 'slug' => 'cache-title']);
        $request = $this->request('GET', '/titles/cache-title', 'titles.show', [
            'catalogTitle' => $title,
        ]);
        $globalVersion = app(CacheVersionRegistry::class)->version(CacheDomain::TitleDetail);

        $context = app(PublicPageCachePolicy::class)->context($request, 'title');

        $this->assertNotNull($context);
        $this->assertSame(CacheDomain::TitleDetail, $context->domain);
        $this->assertSame('title:73', $context->versionScope);
        $this->assertSame($globalVersion, $context->dimensions['global_title_version']);
        $this->assertSame(['catalogTitle' => 'cache-title'], $context->dimensions['parameters']);
    }

    public function test_top_list_filter_queries_have_stable_and_distinct_cache_dimensions(): void
    {
        $policy = app(PublicPageCachePolicy::class);
        $parameters = ['category' => CatalogTopListCategory::Movies];
        $first = $policy->context($this->request(
            'GET',
            '/top/movies?year_from=2010&year_to=2020&country=litva&genre=dramy',
            'top.show',
            $parameters,
        ), 'catalog');
        $reordered = $policy->context($this->request(
            'GET',
            '/top/movies?genre=dramy&country=litva&year_to=2020&year_from=2010',
            'top.show',
            $parameters,
        ), 'catalog');
        $otherCountry = $policy->context($this->request(
            'GET',
            '/top/movies?year_from=2010&year_to=2020&country=latviya&genre=dramy',
            'top.show',
            $parameters,
        ), 'catalog');
        $otherRange = $policy->context($this->request(
            'GET',
            '/top/movies?year_from=2011&year_to=2020&country=litva&genre=dramy',
            'top.show',
            $parameters,
        ), 'catalog');
        $otherGenre = $policy->context($this->request(
            'GET',
            '/top/movies?year_from=2010&year_to=2020&country=litva&genre=komedii',
            'top.show',
            $parameters,
        ), 'catalog');

        $this->assertNotNull($first);
        $this->assertNotNull($reordered);
        $this->assertNotNull($otherCountry);
        $this->assertNotNull($otherRange);
        $this->assertNotNull($otherGenre);
        $this->assertSame(['category' => 'movies'], $first->dimensions['parameters']);
        $this->assertSame($first->dimensions['query'], $reordered->dimensions['query']);
        $this->assertNotSame($first->dimensions['query'], $otherCountry->dimensions['query']);
        $this->assertNotSame($first->dimensions['query'], $otherRange->dimensions['query']);
        $this->assertNotSame($first->dimensions['query'], $otherGenre->dimensions['query']);
    }

    public function test_it_bypasses_private_dynamic_or_unbounded_requests(): void
    {
        $policy = app(PublicPageCachePolicy::class);
        $authenticated = $this->request('GET', '/', 'home');
        $authenticated->setUserResolver(fn (): User => User::factory()->make(['id' => 9]));

        $this->assertNull($policy->context($authenticated, 'homepage'));
        $this->assertNull($policy->context(
            $this->request('GET', '/titles?q=secret', 'titles.index'),
            'catalog',
        ));
        $this->assertNull($policy->context(
            $this->request('GET', '/titles?title=some-title', 'titles.index'),
            'catalog',
        ));
        $this->assertNull($policy->context(
            $this->request('GET', '/titles?unknown=value', 'titles.index'),
            'catalog',
        ));
        $this->assertNull($policy->context(
            $this->request('POST', '/titles', 'titles.index'),
            'catalog',
        ));

        $authorization = $this->request('GET', '/', 'home');
        $authorization->headers->set('Authorization', 'Bearer token');
        $this->assertNull($policy->context($authorization, 'homepage'));

        $livewire = $this->request('GET', '/', 'home');
        $livewire->headers->set('X-Livewire', 'true');
        $this->assertNull($policy->context($livewire, 'homepage'));

        $oversized = $this->request(
            'GET',
            '/titles?genre='.str_repeat('a', 200),
            'titles.index',
        );
        $this->assertNull($policy->context($oversized, 'catalog'));
        $this->assertNull($policy->context($this->request('GET', '/', 'home'), 'unknown'));
    }

    /** @param array<string, mixed> $parameters */
    private function request(string $method, string $uri, string $name, array $parameters = []): Request
    {
        $request = Request::create($uri, $method);
        $route = new Route([$method], parse_url($uri, PHP_URL_PATH) ?: '/', fn () => null);
        $route->name($name);
        $route->bind($request);

        foreach ($parameters as $key => $value) {
            $route->setParameter($key, $value);
        }

        $request->setRouteResolver(fn (): Route => $route);
        $request->setUserResolver(fn (): null => null);

        return $request;
    }
}
