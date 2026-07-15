<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Jobs\WarmCatalogCaches;
use App\Services\Catalog\CatalogCacheInvalidator;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

final class CacheWarmQueueFallbackTest extends TestCase
{
    public function test_missing_queue_configuration_still_dispatches_to_the_versioned_queue(): void
    {
        config([
            'cache-architecture.stores.locks' => 'array',
            'cache-architecture.stores.versions' => 'array',
            'cache-architecture.warming.enabled' => true,
        ]);
        $warming = (array) config('cache-architecture.warming');
        unset($warming['queue']);
        config(['cache-architecture.warming' => $warming]);
        Queue::fake();

        $this->assertSame('cache-warm-v2', (new WarmCatalogCaches)->queue);

        app(CatalogCacheInvalidator::class)->catalogChanged();

        Queue::assertPushed(
            WarmCatalogCaches::class,
            fn (WarmCatalogCaches $job): bool => $job->queue === 'cache-warm-v2',
        );
    }
}
