<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\CatalogTitle;
use App\Support\Cache\CacheKeyFactory;
use App\Support\Cache\CacheVersionRegistry;
use App\Support\Cache\PublicPageCachePolicy;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Tests\TestCase;

final class PublicPageCachePolicyTitleContextTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['app.url' => 'http://localhost']);
    }

    public function test_canonical_context_matches_the_queryless_guest_request_key(): void
    {
        $title = new CatalogTitle;
        $title->forceFill(['id' => 84, 'slug' => 'canonical-cache-title']);
        $policy = app(PublicPageCachePolicy::class);
        $requestContext = $policy->context($this->request(
            '/titles/canonical-cache-title',
            $title,
        ), 'title');
        $variantContext = $policy->context($this->request(
            '/titles/canonical-cache-title?season=1',
            $title,
        ), 'title');
        $canonicalContext = $policy->canonicalTitleContext($title);

        $this->assertNotNull($requestContext);
        $this->assertNotNull($variantContext);
        $this->assertNotNull($canonicalContext);
        $this->assertEquals($requestContext, $canonicalContext);
        $this->assertNotSame($canonicalContext->dimensions['query'], $variantContext->dimensions['query']);

        $keys = app(CacheKeyFactory::class);
        $versions = app(CacheVersionRegistry::class);
        $canonicalKey = $keys->data(
            $canonicalContext->domain,
            'response-html',
            $canonicalContext->dimensions,
            $versions->version($canonicalContext->domain, $canonicalContext->versionScope),
        );
        $requestKey = $keys->data(
            $requestContext->domain,
            'response-html',
            $requestContext->dimensions,
            $versions->version($requestContext->domain, $requestContext->versionScope),
        );

        $this->assertSame($requestKey, $canonicalKey);
    }

    public function test_canonical_context_uses_the_configured_default_locale_in_a_contaminated_worker(): void
    {
        config(['cache-architecture.page_cache.canonical_locale' => 'ru']);
        app()->setLocale('en');
        $title = new CatalogTitle;
        $title->forceFill(['id' => 85, 'slug' => 'default-locale-cache-title']);
        $policy = app(PublicPageCachePolicy::class);

        $requestContext = $policy->context($this->request(
            '/titles/default-locale-cache-title',
            $title,
        ), 'title');
        $canonicalContext = $policy->canonicalTitleContext($title);

        $this->assertNotNull($requestContext);
        $this->assertNotNull($canonicalContext);
        $this->assertSame('en', $requestContext->dimensions['locale']);
        $this->assertSame('ru', $canonicalContext->dimensions['locale']);
    }

    private function request(string $uri, CatalogTitle $title): Request
    {
        $request = Request::create($uri);
        $route = new Route(['GET'], parse_url($uri, PHP_URL_PATH) ?: '/', fn () => null);
        $route->name('titles.show');
        $route->bind($request);
        $route->setParameter('catalogTitle', $title);
        $request->setRouteResolver(fn (): Route => $route);
        $request->setUserResolver(fn (): null => null);

        return $request;
    }
}
