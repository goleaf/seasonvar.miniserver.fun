<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\CatalogTitle;
use App\Services\Catalog\PublicPageCacheManifest;
use App\Services\Catalog\PublicPageCacheWarmer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use LogicException;
use RuntimeException;
use Tests\TestCase;

final class PublicPageCacheWarmerTest extends TestCase
{
    use RefreshDatabase;

    public function test_manifest_keeps_only_recent_normalized_public_urls(): void
    {
        config(['cache-architecture.page_cache.manifest_limit' => 2]);
        $manifest = app(PublicPageCacheManifest::class);

        $manifest->record('/?utm_source=ignored');
        $manifest->record('/titles?year[1]=2026&year[0]=2025&page=2');
        $this->travel(1)->second();
        $manifest->record('/stats');

        $manifest->record('https://localhost/titles');
        $manifest->record('/profile');
        $manifest->record('/titles?q=private-search');
        $manifest->record('/missing-page');

        $this->assertSame([
            '/stats',
            '/titles?page=2&year%5B0%5D=2025&year%5B1%5D=2026',
        ], $manifest->recent(10));
    }

    public function test_warmer_deduplicates_and_bounds_critical_recent_and_changed_title_urls(): void
    {
        config([
            'app.url' => 'https://seasonvar.test',
            'cache-architecture.page_cache.warming_enabled' => true,
            'cache-architecture.page_cache.warm_base_url' => 'https://seasonvar.test',
            'cache-architecture.page_cache.warm_url_limit' => 50,
        ]);
        $title = CatalogTitle::factory()->create(['slug' => 'izmenennyi-serial']);
        $manifest = app(PublicPageCacheManifest::class);
        $manifest->record('/');
        $manifest->record('/titles?page=2');
        $requested = [];
        Http::preventStrayRequests();
        Http::fake(function (Request $request) use (&$requested) {
            $requested[] = $request->url();

            return Http::response('<html></html>');
        });

        $result = app(PublicPageCacheWarmer::class)->warm([$title->id, $title->id]);

        $this->assertSame(count($requested), $result['attempted']);
        $this->assertSame($result['attempted'], $result['succeeded']);
        $this->assertSame($requested, array_values(array_unique($requested)));
        $this->assertContains('https://seasonvar.test/', $requested);
        $this->assertContains('https://seasonvar.test/stats', $requested);
        $this->assertContains('https://seasonvar.test/titles', $requested);
        $this->assertContains('https://seasonvar.test/genres', $requested);
        $this->assertContains('https://seasonvar.test/titles/izmenennyi-serial', $requested);
        $this->assertContains('https://seasonvar.test/titles?page=2', $requested);
        $this->assertLessThanOrEqual(50, count($requested));
    }

    public function test_warmer_rejects_a_base_url_from_another_origin(): void
    {
        config([
            'app.url' => 'https://seasonvar.test',
            'cache-architecture.page_cache.warming_enabled' => true,
            'cache-architecture.page_cache.warm_base_url' => 'https://evil.test',
        ]);
        Http::preventStrayRequests();
        Http::fake();

        $this->expectException(LogicException::class);

        app(PublicPageCacheWarmer::class)->warm();
    }

    public function test_manifest_rejects_network_path_urls(): void
    {
        $manifest = app(PublicPageCacheManifest::class);

        $manifest->record('//evil.test/titles');

        $this->assertSame([], $manifest->recent(10));
    }

    public function test_warmer_rejects_base_urls_with_query_or_fragment_components(): void
    {
        config([
            'app.url' => 'https://seasonvar.test',
            'cache-architecture.page_cache.warming_enabled' => true,
            'cache-architecture.page_cache.warm_base_url' => 'https://seasonvar.test?redirect=evil.test',
        ]);
        Http::preventStrayRequests();
        Http::fake();

        $this->expectException(LogicException::class);

        app(PublicPageCacheWarmer::class)->warm();
    }

    public function test_warmer_retries_transient_http_failures_within_the_url_limit(): void
    {
        config([
            'app.url' => 'https://seasonvar.test',
            'cache-architecture.page_cache.warming_enabled' => true,
            'cache-architecture.page_cache.warm_base_url' => 'https://seasonvar.test',
            'cache-architecture.page_cache.warm_url_limit' => 1,
            'cache-architecture.page_cache.warm_retry_times' => 2,
            'cache-architecture.page_cache.warm_retry_milliseconds' => 1,
        ]);
        Http::preventStrayRequests();
        Http::fakeSequence()
            ->push('', 503)
            ->push('<html></html>', 200);

        $result = app(PublicPageCacheWarmer::class)->warm();

        $this->assertSame(['attempted' => 1, 'succeeded' => 1], $result);
        Http::assertSentCount(2);
    }

    public function test_warmer_fails_the_batch_after_a_final_non_success_response(): void
    {
        config([
            'app.url' => 'https://seasonvar.test',
            'cache-architecture.page_cache.warming_enabled' => true,
            'cache-architecture.page_cache.warm_base_url' => 'https://seasonvar.test',
            'cache-architecture.page_cache.warm_url_limit' => 1,
            'cache-architecture.page_cache.warm_retry_times' => 1,
        ]);
        Http::preventStrayRequests();
        Http::fake(fn () => Http::response('', 503));

        $this->expectException(RuntimeException::class);

        app(PublicPageCacheWarmer::class)->warm();
    }
}
