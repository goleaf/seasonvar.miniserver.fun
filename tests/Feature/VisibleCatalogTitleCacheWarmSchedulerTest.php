<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\WarmCatalogTitlePage;
use App\Models\CatalogTitle;
use App\Services\Catalog\VisibleCatalogTitleCacheWarmScheduler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

final class VisibleCatalogTitleCacheWarmSchedulerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'cache-architecture.warming.enabled' => true,
            'cache-architecture.page_cache.enabled' => true,
            'cache-architecture.page_cache.warming_enabled' => true,
            'cache-architecture.warming.visible_titles.enabled' => true,
            'cache-architecture.warming.visible_titles.max_titles' => 3,
        ]);
    }

    public function test_scheduler_normalizes_bounds_and_dispatches_one_job_per_title(): void
    {
        Queue::fake();
        $scheduler = app(VisibleCatalogTitleCacheWarmScheduler::class);

        $this->assertSame([2, 3, 4], $scheduler->normalize([
            0,
            '2',
            2,
            'bad',
            [],
            new \stdClass,
            3,
            4,
            5,
        ]));

        $scheduler->schedule([2, 3, 4, 5]);

        Queue::assertPushed(WarmCatalogTitlePage::class, 3);
        Queue::assertPushed(fn (WarmCatalogTitlePage $job): bool => $job->titleId === 2);
        Queue::assertPushed(fn (WarmCatalogTitlePage $job): bool => $job->titleId === 3);
        Queue::assertPushed(fn (WarmCatalogTitlePage $job): bool => $job->titleId === 4);
    }

    public function test_catalog_miss_and_hit_defer_visible_title_jobs_and_honour_disable_flag(): void
    {
        Queue::fake();
        $titles = CatalogTitle::factory()->count(2)->create();

        $this->get(route('titles.index'))->assertOk();

        Queue::assertPushed(WarmCatalogTitlePage::class, 2);
        foreach ($titles as $title) {
            Queue::assertPushed(fn (WarmCatalogTitlePage $job): bool => $job->titleId === $title->id);
        }

        Queue::fake();
        $this->get(route('titles.index'))->assertOk();
        Queue::assertPushed(WarmCatalogTitlePage::class, 2);

        Queue::fake();
        config(['cache-architecture.warming.visible_titles.enabled' => false]);
        $this->get(route('titles.index'))->assertOk();
        Queue::assertNothingPushed();
    }
}
