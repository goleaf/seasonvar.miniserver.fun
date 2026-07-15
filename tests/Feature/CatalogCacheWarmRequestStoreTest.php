<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\Catalog\CatalogCacheWarmRequestStore;
use Tests\TestCase;

final class CatalogCacheWarmRequestStoreTest extends TestCase
{
    public function test_requests_merge_ids_refresh_intent_and_generations(): void
    {
        $store = app(CatalogCacheWarmRequestStore::class);

        $firstGeneration = $store->request([3, 2, 3, 'invalid']);
        $secondGeneration = $store->request([2, 4], refresh: true);
        $work = $store->claim(10);

        $this->assertGreaterThan($firstGeneration, $secondGeneration);
        $this->assertNotNull($work);
        $this->assertSame($secondGeneration, $work->generation);
        $this->assertTrue($work->refresh);
        $this->assertEqualsCanonicalizing([2, 3, 4], $work->titleIds);
    }

    public function test_claim_is_stable_and_unacknowledged_work_remains_pending(): void
    {
        $store = app(CatalogCacheWarmRequestStore::class);
        $store->request([1, 2, 3, 4]);

        $first = $store->claim(2);
        $second = $store->claim(2);

        $this->assertNotNull($first);
        $this->assertEquals($first, $second);
        $this->assertCount(2, $first->titleIds);
    }

    public function test_newer_requests_survive_completion_of_an_older_batch(): void
    {
        $store = app(CatalogCacheWarmRequestStore::class);
        $store->request([1], refresh: true);
        $oldWork = $store->claim(10);
        $newGeneration = $store->request([1, 2], refresh: true);

        $this->assertNotNull($oldWork);
        $this->assertTrue($store->complete($oldWork));

        $newWork = $store->claim(10);

        $this->assertNotNull($newWork);
        $this->assertSame($newGeneration, $newWork->generation);
        $this->assertTrue($newWork->refresh);
        $this->assertEqualsCanonicalizing([1, 2], $newWork->titleIds);
        $this->assertFalse($store->complete($newWork));
        $this->assertNull($store->claim(10));
    }

    public function test_request_and_claim_limits_bound_persisted_work(): void
    {
        config(['cache-architecture.warming.request_title_limit' => 3]);
        $store = app(CatalogCacheWarmRequestStore::class);

        $store->request(range(1, 20));
        $work = $store->claim(50);

        $this->assertNotNull($work);
        $this->assertSame([1, 2, 3], $work->titleIds);
    }
}
