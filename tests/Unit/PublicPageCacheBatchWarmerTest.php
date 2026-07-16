<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\DTOs\PublicCacheWarmTarget;
use App\Services\Catalog\PublicPageCacheWarmer;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class PublicPageCacheBatchWarmerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'app.url' => 'https://seasonvar.test',
            'cache-architecture.page_cache.warm_base_url' => 'https://seasonvar.test',
            'cache-architecture.page_cache.warming_enabled' => true,
            'cache-architecture.page_cache.warm_retry_times' => 1,
        ]);
        Http::preventStrayRequests();
    }

    public function test_batch_continues_after_one_failed_target(): void
    {
        Http::fake([
            'https://seasonvar.test/first' => Http::response('', 500),
            'https://seasonvar.test/second' => Http::response('<html></html>', 200),
        ]);

        $result = app(PublicPageCacheWarmer::class)->warmTargets([
            new PublicCacheWarmTarget('/first'),
            new PublicCacheWarmTarget('/second'),
        ]);

        $this->assertSame(2, $result['attempted']);
        $this->assertSame(1, $result['succeeded']);
        $this->assertSame(1, $result['failed']);
        $this->assertCount(1, $result['errors']);
        $this->assertSame(hash('sha256', '/first'), $result['errors'][0]['fingerprint']);
        $this->assertSame(500, $result['errors'][0]['status']);
        Http::assertSentCount(2);
    }

    public function test_batch_does_not_follow_external_redirects(): void
    {
        Http::fake([
            'https://seasonvar.test/redirect' => Http::response('', 302, ['Location' => 'https://example.com/private']),
        ]);

        $result = app(PublicPageCacheWarmer::class)->warmTargets([
            new PublicCacheWarmTarget('/redirect'),
        ]);

        $this->assertSame(1, $result['failed']);
        $this->assertSame(302, $result['errors'][0]['status']);
        Http::assertSent(fn ($request): bool => $request->url() === 'https://seasonvar.test/redirect');
        Http::assertSentCount(1);
    }

    public function test_batch_rejects_network_path_targets_without_http(): void
    {
        Http::fake();

        $result = app(PublicPageCacheWarmer::class)->warmTargets([
            new PublicCacheWarmTarget('//example.com/private'),
        ]);

        $this->assertSame(1, $result['attempted']);
        $this->assertSame(1, $result['failed']);
        $this->assertSame(0, $result['succeeded']);
        Http::assertNothingSent();
    }
}
