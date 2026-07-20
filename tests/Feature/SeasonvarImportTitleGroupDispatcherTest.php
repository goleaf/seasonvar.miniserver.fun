<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Exceptions\Seasonvar\SeasonvarSourceRequestException;
use App\Jobs\FinalizeSeasonvarImportTitleGroup;
use App\Jobs\PrepareSeasonvarImportTitlePage;
use App\Models\CatalogTitle;
use App\Models\Season;
use App\Models\SeasonvarImportPreparedPage;
use App\Models\Source;
use App\Services\Seasonvar\SeasonvarImportTitleGroupDispatcher;
use App\Services\Seasonvar\SeasonvarPageClaimManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\TestCase;

class SeasonvarImportTitleGroupDispatcherTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['seasonvar.queue.lock_store' => 'array']);
        Cache::store('array')->flush();
    }

    public function test_nine_urls_dispatch_nine_independent_jobs_without_chunking(): void
    {
        Queue::fake();
        $title = $this->titleWithSeasonUrls(range(1, 9));

        $group = app(SeasonvarImportTitleGroupDispatcher::class)
            ->start($title, 'seasonvar-title-refresh');

        Queue::assertPushed(PrepareSeasonvarImportTitlePage::class, 9);
        Queue::assertPushed(FinalizeSeasonvarImportTitleGroup::class, 1);
        $this->assertSame(9, $group->fresh()->expected_pages);
        $this->assertSame(9, $group->preparedPages()->count());
    }

    public function test_fifty_urls_are_dispatched_without_an_application_limit(): void
    {
        Queue::fake();
        $title = $this->titleWithSeasonUrls(range(1, 50));

        $group = app(SeasonvarImportTitleGroupDispatcher::class)
            ->start($title, 'seasonvar-title-refresh');

        Queue::assertPushed(PrepareSeasonvarImportTitlePage::class, 50);
        $this->assertSame(50, $group->fresh()->expected_pages);
    }

    public function test_duplicate_and_invalid_urls_do_not_create_more_prepared_pages(): void
    {
        Queue::fake();
        $title = $this->titleWithSeasonUrls([1]);
        $dispatcher = app(SeasonvarImportTitleGroupDispatcher::class);
        $group = $dispatcher->start($title, 'seasonvar-title-refresh');
        $validUrl = $this->seasonUrl(1);

        $created = $dispatcher->addUrls($group, [
            $validUrl,
            $validUrl,
            'https://example.com/serial-1-invalid-2-season.html',
            'javascript:alert(1)',
        ]);

        $this->assertSame(0, $created);
        $this->assertSame(1, $group->fresh()->expected_pages);
        $this->assertSame(1, $group->preparedPages()->count());
        Queue::assertPushed(PrepareSeasonvarImportTitlePage::class, 1);
    }

    public function test_discovered_url_from_another_title_family_is_not_attached_to_the_group(): void
    {
        Queue::fake();
        $title = $this->titleWithSeasonUrls([1]);
        $dispatcher = app(SeasonvarImportTitleGroupDispatcher::class);
        $group = $dispatcher->start($title, 'seasonvar-title-refresh');

        $created = $dispatcher->addUrls($group, [
            'https://seasonvar.ru/serial-99999-Drugoj_serial_psother-2-season.html',
        ]);

        $this->assertSame(0, $created);
        $this->assertSame(1, $group->fresh()->expected_pages);
        $this->assertSame(1, $group->preparedPages()->count());
        Queue::assertPushed(PrepareSeasonvarImportTitlePage::class, 1);
    }

    public function test_preparation_job_dispatches_a_newly_discovered_season_before_releasing_parent(): void
    {
        Queue::fake();
        Http::preventStrayRequests();
        config([
            'seasonvar.media_check.enabled' => false,
            'security.external_playlist_enforce_public_dns' => false,
        ]);
        $title = $this->titleWithSeasonUrls([1]);
        $seasonOneUrl = $this->seasonUrl(1);
        $seasonTwoUrl = $this->seasonUrl(2);
        Http::fake([
            $seasonOneUrl => Http::response(<<<HTML
                <html>
                    <head><title>Рыжая 1 сезон</title></head>
                    <body>
                        <h1>Рыжая 1 сезон</h1>
                        <div class="pgs-sinfo_list">Год: 2026 Жанр: Драма Страна: Россия</div>
                        <div class="pgs-seaslist">
                            <a href="{$seasonOneUrl}">1 сезон</a>
                            <a href="{$seasonTwoUrl}">2 сезон</a>
                        </div>
                        <script>var arEpisodes = [{"1_seriya":{"n":"1"}}];</script>
                    </body>
                </html>
                HTML),
        ]);
        $group = app(SeasonvarImportTitleGroupDispatcher::class)
            ->start($title, 'seasonvar-title-refresh');
        $parent = $group->preparedPages()->firstOrFail();

        $this->app->call([new PrepareSeasonvarImportTitlePage($parent->id), 'handle']);

        $this->assertSame('prepared', $parent->fresh()->status->value);
        $this->assertSame(2, $group->fresh()->expected_pages);
        $this->assertSame(1, $group->fresh()->prepared_pages);
        $this->assertSame(1, $group->run->fresh()->parsed);
        $this->assertDatabaseHas('seasonvar_import_prepared_pages', [
            'seasonvar_import_title_group_id' => $group->id,
            'source_page_id' => SeasonvarImportPreparedPage::query()
                ->whereHas('sourcePage', fn ($query) => $query->where('url_hash', hash('sha256', $seasonTwoUrl)))
                ->value('source_page_id'),
            'status' => 'queued',
        ]);
        Queue::assertPushed(PrepareSeasonvarImportTitlePage::class, 2);
        Queue::assertPushed(FinalizeSeasonvarImportTitleGroup::class, 1);
    }

    public function test_failed_preparation_counts_page_and_run_once(): void
    {
        Queue::fake();
        $title = $this->titleWithSeasonUrls([1]);
        $group = app(SeasonvarImportTitleGroupDispatcher::class)
            ->start($title, 'seasonvar-title-refresh');
        $job = new PrepareSeasonvarImportTitlePage($group->preparedPages()->value('id'));

        $job->failed(new RuntimeException('HTTP 503'));
        $job->failed(new RuntimeException('HTTP 503'));

        $this->assertSame(1, $group->fresh()->failed_pages);
        $this->assertSame(1, $group->run->fresh()->failed);
    }

    public function test_permanent_preparation_failure_finishes_the_page_without_retrying(): void
    {
        Queue::fake();
        Http::preventStrayRequests();
        $title = $this->titleWithSeasonUrls([1]);
        $url = $this->seasonUrl(1);
        Http::fake([$url => Http::response('', 404)]);
        $group = app(SeasonvarImportTitleGroupDispatcher::class)
            ->start($title, 'seasonvar-title-refresh');
        $prepared = $group->preparedPages()->with('sourcePage')->firstOrFail();

        $this->app->call([new PrepareSeasonvarImportTitlePage($prepared->id), 'handle']);

        $this->assertSame('failed', $prepared->fresh()->status->value);
        $this->assertSame(1, $group->fresh()->failed_pages);
        $this->assertSame(1, $group->run->fresh()->failed);
        $this->assertSame('gone', $prepared->sourcePage->fresh()->import_status);
        $this->assertNull($prepared->sourcePage->fresh()->import_claim_token);
    }

    public function test_transient_preparation_failure_is_recorded_and_rethrown_for_independent_retry(): void
    {
        Queue::fake();
        Http::preventStrayRequests();
        $title = $this->titleWithSeasonUrls([1]);
        $url = $this->seasonUrl(1);
        Http::fake([$url => Http::response('', 503)]);
        $group = app(SeasonvarImportTitleGroupDispatcher::class)
            ->start($title, 'seasonvar-title-refresh');
        $prepared = $group->preparedPages()->with('sourcePage')->firstOrFail();
        $exception = null;

        try {
            $this->app->call([new PrepareSeasonvarImportTitlePage($prepared->id), 'handle']);
        } catch (SeasonvarSourceRequestException $caught) {
            $exception = $caught;
        }

        $this->assertInstanceOf(SeasonvarSourceRequestException::class, $exception);
        $this->assertSame(503, $exception->status);
        $this->assertSame('preparing', $prepared->fresh()->status->value);
        $this->assertSame(0, $group->fresh()->failed_pages);
        $this->assertSame('failed', $prepared->sourcePage->fresh()->import_status);
        $this->assertSame(1, $prepared->sourcePage->fresh()->failure_count);
        $this->assertNull($prepared->sourcePage->fresh()->import_claim_token);
    }

    public function test_retry_of_prepared_page_releases_its_orphaned_claim_before_finalization(): void
    {
        Queue::fake();
        $title = $this->titleWithSeasonUrls([1]);
        $group = app(SeasonvarImportTitleGroupDispatcher::class)
            ->start($title, 'seasonvar-title-refresh');
        $prepared = $group->preparedPages()->with('sourcePage')->firstOrFail();
        $claims = app(SeasonvarPageClaimManager::class);
        $token = $claims->claim($prepared->sourcePage, $group->seasonvar_import_run_id, 3600);
        $this->assertNotNull($token);
        $prepared->markPrepared([], [], hash('sha256', 'prepared'), 1);

        $this->app->call([new PrepareSeasonvarImportTitlePage($prepared->id), 'handle']);

        $this->assertFalse($claims->owns($prepared->source_page_id, $group->seasonvar_import_run_id, $token));
        Queue::assertPushed(FinalizeSeasonvarImportTitleGroup::class, 1);
    }

    /** @param list<int> $seasonNumbers */
    private function titleWithSeasonUrls(array $seasonNumbers): CatalogTitle
    {
        $source = Source::factory()->create([
            'code' => 'seasonvar',
            'base_url' => 'https://seasonvar.ru',
            'crawl_delay_seconds' => 0,
        ]);
        $title = CatalogTitle::factory()->for($source)->create([
            'external_id' => '24212',
            'source_url' => $this->seasonUrl($seasonNumbers[0]),
            'source_url_hash' => hash('sha256', $this->seasonUrl($seasonNumbers[0])),
        ]);

        foreach ($seasonNumbers as $seasonNumber) {
            $url = $this->seasonUrl($seasonNumber);
            Season::factory()->for($title)->create([
                'number' => $seasonNumber,
                'sort_order' => $seasonNumber,
                'source_url' => $url,
                'source_url_hash' => hash('sha256', $url),
            ]);
        }

        return $title;
    }

    private function seasonUrl(int $seasonNumber): string
    {
        return sprintf(
            'https://seasonvar.ru/serial-24212-Ryzhaya_psbdtie-%d-season.html',
            $seasonNumber,
        );
    }
}
