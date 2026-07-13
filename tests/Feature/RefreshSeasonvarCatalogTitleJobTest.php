<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\FinalizeSeasonvarImportTitleGroup;
use App\Jobs\PrepareSeasonvarImportTitlePage;
use App\Jobs\RefreshSeasonvarCatalogTitle;
use App\Models\CatalogTitle;
use App\Models\Season;
use App\Services\Seasonvar\CatalogTitleRefreshStateStore;
use App\Services\Seasonvar\SeasonvarImportTitleGroupDispatcher;
use App\Services\Seasonvar\SeasonvarUrl;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use InvalidArgumentException;
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
        Queue::fake();
        Http::preventStrayRequests();
    }

    public function test_it_is_unique_per_title_and_dispatches_every_known_page_without_http_or_catalog_mutation(): void
    {
        $this->freezeTime();
        $url = 'https://seasonvar.ru/serial-42-Test-1-season.html';
        $title = $this->refreshableTitle($url);
        $secondUrl = 'https://seasonvar.ru/serial-42-Test-2-season.html';
        Season::factory()->for($title)->create([
            'number' => 1,
            'source_url' => $url,
            'source_url_hash' => hash('sha256', $url),
        ]);
        Season::factory()->for($title)->create([
            'number' => 2,
            'source_url' => $secondUrl,
            'source_url_hash' => hash('sha256', $secondUrl),
        ]);

        $job = new RefreshSeasonvarCatalogTitle($title->id);
        $job->handle(
            app(SeasonvarImportTitleGroupDispatcher::class),
            app(SeasonvarUrl::class),
            app(CatalogTitleRefreshStateStore::class),
        );

        $state = app(CatalogTitleRefreshStateStore::class)->read($title->id);

        $this->assertSame(0, $job->tries);
        $this->assertSame(900, $job->timeout);
        $this->assertSame('redis', $job->connection);
        $this->assertSame('seasonvar-title-refresh', $job->queue);
        $this->assertSame('catalog-title-refresh:'.$title->id, $job->uniqueId());
        $this->assertSame(now()->addSeconds(21_600)->getTimestamp(), $job->retryUntil()->getTimestamp());
        $this->assertSame([60, 300, 900], $job->backoff());
        $this->assertSame('running', $state->status?->value);
        $this->assertNotNull($state->importRunId);
        $this->assertSame(1, CatalogTitle::query()->count());
        $this->assertSame(2, $title->seasons()->count());
        Queue::assertPushed(PrepareSeasonvarImportTitlePage::class, 2);
        Queue::assertPushed(FinalizeSeasonvarImportTitleGroup::class, 1);
    }

    public function test_it_rejects_a_non_seasonvar_source_url_before_dispatching_pages(): void
    {
        $title = $this->refreshableTitle('https://example.com/serial-42-Test-1-season.html');

        $this->expectException(InvalidArgumentException::class);

        (new RefreshSeasonvarCatalogTitle($title->id))->handle(
            app(SeasonvarImportTitleGroupDispatcher::class),
            app(SeasonvarUrl::class),
            app(CatalogTitleRefreshStateStore::class),
        );
    }

    public function test_it_forgets_refresh_state_and_finishes_when_the_title_was_deleted(): void
    {
        $title = $this->refreshableTitle('https://seasonvar.ru/serial-43-Test-1-season.html');
        $titleId = $title->id;
        $states = app(CatalogTitleRefreshStateStore::class);
        $states->queued($titleId);
        $title->delete();

        (new RefreshSeasonvarCatalogTitle($titleId))->handle(
            app(SeasonvarImportTitleGroupDispatcher::class),
            app(SeasonvarUrl::class),
            $states,
        );

        $this->assertNull($states->read($titleId)->status);
        Queue::assertNothingPushed();
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
