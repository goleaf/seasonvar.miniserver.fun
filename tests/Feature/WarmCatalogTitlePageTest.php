<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\WarmCatalogTitlePage;
use App\Models\CatalogTitle;
use App\Models\SeasonvarImportRun;
use App\Services\Catalog\PublicPageCacheWarmer;
use App\Services\Seasonvar\SeasonvarImportActivity;
use App\Support\Cache\CacheTtlPolicy;
use App\Support\Cache\PublicPageCachePolicy;
use App\Support\Cache\TieredCache;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class WarmCatalogTitlePageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'app.url' => 'https://seasonvar.test',
            'cache.stores.title-warm-hot-test' => ['driver' => 'array', 'serialize' => true],
            'cache.stores.title-warm-domain-test' => ['driver' => 'array', 'serialize' => true],
            'cache.stores.title-warm-lock-test' => ['driver' => 'array', 'serialize' => true],
            'cache.stores.title-warm-metrics-test' => ['driver' => 'array', 'serialize' => true],
            'cache-architecture.stores.hot' => 'title-warm-hot-test',
            'cache-architecture.stores.domain' => 'title-warm-domain-test',
            'cache-architecture.stores.locks' => 'title-warm-lock-test',
            'cache-architecture.stores.versions' => 'title-warm-lock-test',
            'cache-architecture.stores.metrics' => 'title-warm-metrics-test',
            'cache-architecture.page_cache.enabled' => true,
            'cache-architecture.page_cache.warming_enabled' => true,
            'cache-architecture.page_cache.warm_base_url' => 'https://seasonvar.test',
            'cache-architecture.page_cache.warm_retry_times' => 1,
            'cache-architecture.warming.queue' => 'cache-warm-v2',
            'cache-architecture.warming.visible_titles.import_pause_seconds' => 300,
            'cache-architecture.warming.visible_titles.unavailable_pause_seconds' => 60,
            'cache-architecture.domains.title-detail' => [
                'fresh' => 60, 'stale' => 600, 'hot' => 30, 'negative' => 10,
                'lock' => 10, 'wait_milliseconds' => 50, 'jitter_percent' => 0,
            ],
        ]);
        Http::preventStrayRequests();
    }

    public function test_job_contract_is_unique_and_overlap_protected(): void
    {
        $this->travelTo('2026-07-19 12:00:00');
        config([
            'cache-architecture.warming.visible_titles.retry_window_seconds' => 86_400,
            'cache-architecture.warming.visible_titles.unique_seconds' => 600,
        ]);
        $job = new WarmCatalogTitlePage(73);
        $this->assertInstanceOf(ShouldQueue::class, $job);
        $this->assertInstanceOf(ShouldBeUnique::class, $job);
        $this->assertTrue($job->afterCommit);
        $this->assertSame(0, $job->tries);
        $this->assertSame(now()->addDay()->getTimestamp(), $job->retryUntil()->getTimestamp());
        $this->assertSame(86_700, $job->uniqueFor);
        $this->assertSame('catalog-title-page:73', $job->uniqueId());
        $this->assertCount(1, $job->middleware());
        $this->assertSame(WithoutOverlapping::class, get_class($job->middleware()[0]));
    }

    public function test_missing_and_stale_warm_one_url_while_fresh_does_not(): void
    {
        $missing = CatalogTitle::factory()->create(['slug' => 'missing-cache-title']);
        Http::fake(fn () => Http::response('<html></html>'));
        $this->handle(new WarmCatalogTitlePage($missing->id));
        Http::assertSentCount(1);
        Http::assertSent(fn ($request): bool => $request->url() === 'https://seasonvar.test/titles/missing-cache-title');

        Http::fake();
        $fresh = CatalogTitle::factory()->create(['slug' => 'fresh-cache-title']);
        $this->seedTitleCache($fresh);
        $this->handle(new WarmCatalogTitlePage($fresh->id));
        Http::assertNothingSent();

        $this->travel(61)->seconds();
        app()->forgetInstance('cache.__memoized:title-warm-domain-test');
        Http::fake(fn () => Http::response('<html></html>'));
        $this->handle(new WarmCatalogTitlePage($fresh->id));
        Http::assertSentCount(1);
    }

    public function test_missing_warm_populates_the_canonical_cache_for_the_next_request(): void
    {
        $title = CatalogTitle::factory()->create(['slug' => 'canonical-warmed-title']);
        $url = 'https://seasonvar.test/titles/canonical-warmed-title';
        Http::fake(function () use ($url) {
            $response = $this->get($url)
                ->assertOk()
                ->assertHeader('X-Seasonvar-Page-Cache', 'MISS');

            return Http::response(
                $response->getContent(),
                $response->getStatusCode(),
                ['Content-Type' => 'text/html; charset=UTF-8'],
            );
        });

        $this->handle(new WarmCatalogTitlePage($title->id));

        Http::assertSentCount(1);
        $this->get($url)
            ->assertOk()
            ->assertHeader('X-Seasonvar-Page-Cache', 'HIT');
    }

    public function test_long_canonical_slug_uses_a_bounded_title_identity_and_warms_once(): void
    {
        $slug = str_repeat('long-cache-title-', 12).'tail';
        $this->assertGreaterThan((int) config('cache-architecture.max_dimension_length'), mb_strlen($slug));
        $title = CatalogTitle::factory()->create(['slug' => $slug]);
        Http::fake(fn () => Http::response('<html></html>'));
        $job = (new WarmCatalogTitlePage($title->id))->withFakeQueueInteractions();

        $this->handle($job);

        $job->assertNotReleased();
        Http::assertSentCount(1);
        Http::assertSent(fn ($request): bool => $request->url() === 'https://seasonvar.test/titles/'.$slug);
    }

    public function test_hidden_title_import_and_cache_outage_never_send_http(): void
    {
        $hidden = CatalogTitle::factory()->create(['is_published' => false]);
        Http::fake();
        $this->handle(new WarmCatalogTitlePage($hidden->id));
        Http::assertNothingSent();

        $title = CatalogTitle::factory()->create();
        SeasonvarImportRun::query()->create(['mode' => 'all', 'status' => 'running']);
        $importJob = (new WarmCatalogTitlePage($title->id))->withFakeQueueInteractions();
        $this->handle($importJob);
        $importJob->assertReleased(300);

        SeasonvarImportRun::query()->delete();
        config([
            'cache.stores.unavailable-title-warm-test' => ['driver' => 'unsupported-title-warm-test'],
            'cache-architecture.stores.domain' => 'unavailable-title-warm-test',
        ]);
        $outageJob = (new WarmCatalogTitlePage($title->id))->withFakeQueueInteractions();
        $this->handle($outageJob);
        $outageJob->assertReleased(60);
        Http::assertNothingSent();
    }

    public function test_version_registry_outage_releases_without_sending_http(): void
    {
        $title = CatalogTitle::factory()->create();
        config([
            'cache.stores.unavailable-title-version-test' => ['driver' => 'unsupported-title-version-test'],
            'cache-architecture.stores.versions' => 'unavailable-title-version-test',
        ]);
        app()->forgetInstance('cache.__memoized:unavailable-title-version-test');
        Http::fake();

        $job = (new WarmCatalogTitlePage($title->id))->withFakeQueueInteractions();
        $this->handle($job);

        $job->assertReleased(60);
        Http::assertNothingSent();
    }

    private function handle(WarmCatalogTitlePage $job): void
    {
        $job->handle(
            app(SeasonvarImportActivity::class),
            app(PublicPageCachePolicy::class),
            app(TieredCache::class),
            app(PublicPageCacheWarmer::class),
        );
    }

    private function seedTitleCache(CatalogTitle $title): void
    {
        $context = app(PublicPageCachePolicy::class)->canonicalTitleContext($title);
        $this->assertNotNull($context);
        app(TieredCache::class)->remember(
            $context->domain,
            'response-html',
            $context->dimensions,
            app(CacheTtlPolicy::class)->for($context->domain),
            fn (): array => ['body' => 'cached'],
            versionScope: $context->versionScope,
        );
    }
}
