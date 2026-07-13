<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\RefreshSeasonvarCatalogTitle;
use App\Models\CatalogTitle;
use App\Models\SeasonvarImportRun;
use App\Services\Seasonvar\CatalogTitleRefreshStateStore;
use App\Services\Seasonvar\SeasonvarImportGroupKey;
use App\Services\Seasonvar\SeasonvarImportPipeline;
use App\Services\Seasonvar\SeasonvarTitleMerger;
use App\Services\Seasonvar\SeasonvarUrl;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class RefreshSeasonvarCatalogTitleJobTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'cache-architecture.stores.domain' => 'array',
            'seasonvar.queue.connection' => 'redis',
            'seasonvar.queue.queue' => 'seasonvar-import',
            'seasonvar.queue.lock_store' => 'array',
            'seasonvar.queue.retry_window_seconds' => 21_600,
            'seasonvar.queue.worker_timeout' => 900,
            'seasonvar.title_refresh.active_seconds' => 21_900,
            'seasonvar.title_refresh.queue' => 'seasonvar-title-refresh',
            'seasonvar.title_refresh.state_ttl_seconds' => 86_400,
        ]);

        Cache::store('array')->flush();
    }

    public function test_it_is_unique_per_title_and_runs_the_forced_targeted_pipeline_on_the_import_queue(): void
    {
        $this->freezeTime();
        $url = 'https://seasonvar.ru/serial-42-Test-1-season.html';
        $title = $this->refreshableTitle($url);
        $run = SeasonvarImportRun::query()->create([
            'mode' => 'url',
            'status' => 'completed',
            'argument' => $url,
            'force' => true,
            'started_at' => now(),
            'finished_at' => now(),
        ]);
        $pipeline = Mockery::mock(SeasonvarImportPipeline::class);
        $pipeline->shouldReceive('run')->once()->withArgs(fn (
            ?string $argument,
            bool $force,
            bool $forever,
            ?int $sleepSeconds,
            bool $discover,
        ): bool => $argument === $url
            && $force
            && ! $forever
            && $sleepSeconds === null
            && ! $discover)->andReturn($run);
        $merger = Mockery::mock(SeasonvarTitleMerger::class);
        $merger->shouldReceive('mergeForCanonicalSlug')
            ->once()
            ->with($title->slug)
            ->andReturn([
                'groups' => 1,
                'titles' => 2,
                'seasons' => 3,
                'episodes' => 4,
            ]);

        $job = new RefreshSeasonvarCatalogTitle($title->id);
        $job->handle(
            $pipeline,
            app(SeasonvarUrl::class),
            app(SeasonvarImportGroupKey::class),
            app(CatalogTitleRefreshStateStore::class),
            $merger,
        );

        $state = app(CatalogTitleRefreshStateStore::class)->read($title->id);

        $this->assertSame(0, $job->tries);
        $this->assertSame(900, $job->timeout);
        $this->assertSame('redis', $job->connection);
        $this->assertSame('seasonvar-title-refresh', $job->queue);
        $this->assertSame('catalog-title-refresh:'.$title->id, $job->uniqueId());
        $this->assertSame(now()->addSeconds(21_600)->getTimestamp(), $job->retryUntil()->getTimestamp());
        $this->assertSame([60, 300, 900], $job->backoff());
        $this->assertSame('completed', $state->status?->value);
        $this->assertSame($run->id, $state->importRunId);
    }

    public function test_it_releases_when_the_title_group_is_already_being_imported(): void
    {
        $url = 'https://seasonvar.ru/serial-42-Test-1-season.html';
        $title = $this->refreshableTitle($url);
        $groupKeys = app(SeasonvarImportGroupKey::class);
        $lock = Cache::store('array')->lock($groupKeys->forUrl($url, hash('sha256', $url)), 1200);
        $this->assertTrue($lock->get());

        try {
            $states = app(CatalogTitleRefreshStateStore::class);
            $states->queued($title->id);
            $pipeline = Mockery::mock(SeasonvarImportPipeline::class);
            $pipeline->shouldNotReceive('run');
            $job = (new RefreshSeasonvarCatalogTitle($title->id))->withFakeQueueInteractions();

            $job->handle(
                $pipeline,
                app(SeasonvarUrl::class),
                $groupKeys,
                $states,
                app(SeasonvarTitleMerger::class),
            );

            $job->assertReleased(delay: 30);
            $this->assertSame('queued', $states->read($title->id)->status?->value);
        } finally {
            $lock->release();
        }
    }

    public function test_it_rejects_a_non_seasonvar_source_url_before_running_the_pipeline(): void
    {
        $title = $this->refreshableTitle('https://example.com/serial-42-Test-1-season.html');
        $pipeline = Mockery::mock(SeasonvarImportPipeline::class);
        $pipeline->shouldNotReceive('run');

        $this->expectException(InvalidArgumentException::class);

        (new RefreshSeasonvarCatalogTitle($title->id))->handle(
            $pipeline,
            app(SeasonvarUrl::class),
            app(SeasonvarImportGroupKey::class),
            app(CatalogTitleRefreshStateStore::class),
            app(SeasonvarTitleMerger::class),
        );
    }

    public function test_terminal_failure_updates_only_sanitized_refresh_state(): void
    {
        Log::spy();
        $title = $this->refreshableTitle('https://seasonvar.ru/serial-51-Test-1-season.html');
        $job = new RefreshSeasonvarCatalogTitle($title->id);

        $job->failed(new RuntimeException('private remote token'));

        $state = app(CatalogTitleRefreshStateStore::class)->read($title->id);
        $this->assertSame('failed', $state->status?->value);
        $this->assertStringNotContainsString('private remote token', json_encode($state->toArray(), JSON_THROW_ON_ERROR));
        Log::shouldHaveReceived('error')->once()->with(
            'Фоновое обновление страницы тайтла Seasonvar завершилось ошибкой.',
            [
                'catalog_title_id' => $title->id,
                'exception' => RuntimeException::class,
            ],
        );
    }

    private function refreshableTitle(string $url): CatalogTitle
    {
        return CatalogTitle::factory()->create([
            'source_url' => $url,
            'source_url_hash' => hash('sha256', $url),
        ]);
    }
}
