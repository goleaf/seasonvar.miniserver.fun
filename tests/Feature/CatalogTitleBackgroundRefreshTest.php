<?php

namespace Tests\Feature;

use App\Jobs\RefreshSeasonvarCatalogTitle;
use App\Models\CatalogTitle;
use App\Services\Seasonvar\CatalogTitleRefreshCoordinator;
use App\Services\Seasonvar\CatalogTitleRefreshStateStore;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class CatalogTitleBackgroundRefreshTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'cache-architecture.stores.domain' => 'array',
            'seasonvar.queue.lock_store' => 'array',
            'seasonvar.title_refresh.fresh_minutes' => 15,
            'seasonvar.title_refresh.state_ttl_seconds' => 86_400,
            'seasonvar.title_refresh.active_seconds' => 21_900,
            'seasonvar.title_refresh.dispatch_lock_seconds' => 10,
        ]);

        Cache::store('array')->flush();
        Queue::fake();
    }

    public function test_it_queues_one_refresh_for_concurrent_visits_and_respects_the_success_window(): void
    {
        $title = $this->refreshableTitle();
        $dispatcher = Mockery::mock(Dispatcher::class);
        $dispatcher->shouldReceive('dispatch')->twice()->andReturn(1);
        $this->app->instance(Dispatcher::class, $dispatcher);
        $coordinator = app(CatalogTitleRefreshCoordinator::class);
        $states = app(CatalogTitleRefreshStateStore::class);

        $this->assertSame('queued', $coordinator->request($title)->status?->value);
        $this->assertSame('queued', $coordinator->request($title)->status?->value);

        $states->completed($title->id, 91);
        $this->travel(14)->minutes();
        $this->assertSame('completed', $coordinator->request($title)->status?->value);

        $this->travel(2)->minutes();
        $this->assertSame('queued', $coordinator->request($title)->status?->value);
    }

    public function test_it_recovers_expired_active_state_but_does_not_queue_a_title_without_a_source_url(): void
    {
        $title = $this->refreshableTitle();
        $states = app(CatalogTitleRefreshStateStore::class);
        $coordinator = app(CatalogTitleRefreshCoordinator::class);

        $states->queued($title->id);
        $this->travel(21_901)->seconds();
        $this->assertSame('queued', $coordinator->request($title)->status?->value);

        $title->update(['source_url' => '', 'source_url_hash' => hash('sha256', '')]);
        $states->forget($title->id);

        $this->assertNull($coordinator->request($title->fresh())->status);
        Queue::assertPushed(RefreshSeasonvarCatalogTitle::class, 1);
    }

    public function test_it_returns_sanitized_failed_state_when_dispatch_throws(): void
    {
        $title = $this->refreshableTitle();
        $dispatcher = Mockery::mock(Dispatcher::class);
        $dispatcher->shouldReceive('dispatch')->once()->andThrow(new RuntimeException('queue unavailable'));
        $this->app->instance(Dispatcher::class, $dispatcher);

        $state = app(CatalogTitleRefreshCoordinator::class)->request($title);

        $this->assertSame('failed', $state->status?->value);
        $this->assertArrayNotHasKey('error', $state->toArray());
        $this->assertStringNotContainsString('queue unavailable', json_encode($state->toArray(), JSON_THROW_ON_ERROR));
    }

    public function test_different_titles_queue_independent_refresh_jobs_with_separate_unique_keys(): void
    {
        $titles = CatalogTitle::factory()->count(5)->create()->each(function (CatalogTitle $title): void {
            $url = 'https://seasonvar.ru/serial-'.$title->id.'-Test-'.$title->id.'-season.html';

            $title->update([
                'source_url' => $url,
                'source_url_hash' => hash('sha256', $url),
            ]);
        });

        foreach ($titles as $title) {
            app(CatalogTitleRefreshCoordinator::class)->request($title->fresh());
        }

        Queue::assertPushed(RefreshSeasonvarCatalogTitle::class, 5);

        $jobs = Queue::pushed(RefreshSeasonvarCatalogTitle::class);

        $this->assertSame(
            $titles->modelKeys(),
            $jobs->pluck('catalogTitleId')->sort()->values()->all(),
        );
        $this->assertCount(5, $jobs->map->uniqueId()->unique());
    }

    private function refreshableTitle(array $attributes = []): CatalogTitle
    {
        $url = 'https://seasonvar.ru/serial-42-Test-1-season.html';

        return CatalogTitle::factory()->create([
            'source_url' => $url,
            'source_url_hash' => hash('sha256', $url),
            ...$attributes,
        ]);
    }
}
