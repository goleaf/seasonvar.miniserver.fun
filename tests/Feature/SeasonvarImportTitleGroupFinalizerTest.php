<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\DTOs\Seasonvar\SeasonvarCatalogData;
use App\DTOs\Seasonvar\SeasonvarPreparedCatalogPage;
use App\Jobs\FinalizeSeasonvarImportTitleGroup;
use App\Jobs\WarmCatalogCaches;
use App\Models\ApiSyncChange;
use App\Models\CatalogTitle;
use App\Models\Season;
use App\Models\SeasonvarImportPreparedPage;
use App\Models\Source;
use App\Services\Catalog\CatalogCacheWarmRequestStore;
use App\Services\Seasonvar\CatalogTitleRefreshStateStore;
use App\Services\Seasonvar\SeasonvarCatalogParser;
use App\Services\Seasonvar\SeasonvarImportTitleGroupDispatcher;
use App\Services\Seasonvar\SeasonvarPageClaimManager;
use App\Services\Seasonvar\SeasonvarTitleMerger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Mockery\MockInterface;
use RuntimeException;
use Tests\TestCase;
use Throwable;

class SeasonvarImportTitleGroupFinalizerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'seasonvar.queue.lock_store' => 'array',
            'cache-architecture.stores.domain' => 'array',
            'seasonvar.title_refresh.finalizer_delay_seconds' => 7,
        ]);
        Cache::store('array')->flush();
        Queue::fake();
        Http::preventStrayRequests();
    }

    public function test_finalizer_returns_without_polling_while_a_page_is_not_terminal(): void
    {
        $title = $this->titleWithSeasonUrls([1, 2]);
        $group = app(SeasonvarImportTitleGroupDispatcher::class)
            ->start($title, 'seasonvar-title-refresh');
        $job = (new FinalizeSeasonvarImportTitleGroup($group->id))->withFakeQueueInteractions();

        $this->app->call([$job, 'handle']);

        $job->assertNotReleased();
        $this->assertSame('running', $group->fresh()->status->value);
        $this->assertSame(0, $group->fresh()->applied_pages);
    }

    public function test_finalizer_returns_without_polling_while_a_terminal_page_still_has_a_live_claim(): void
    {
        $title = $this->titleWithSeasonUrls([1]);
        $group = app(SeasonvarImportTitleGroupDispatcher::class)
            ->start($title, 'seasonvar-title-refresh');
        $row = $group->preparedPages()->with('sourcePage')->firstOrFail();
        $this->prepareRow($row, 1, 2);
        $this->assertNotNull(app(SeasonvarPageClaimManager::class)->claim(
            $row->sourcePage,
            $group->seasonvar_import_run_id,
            3600,
        ));
        DB::table($group->getTable())
            ->where('id', $group->id)
            ->update(['updated_at' => now()->subDays(2)]);
        $job = (new FinalizeSeasonvarImportTitleGroup($group->id))->withFakeQueueInteractions();

        $this->app->call([$job, 'handle']);

        $job->assertNotReleased();
        $this->assertSame('running', $group->fresh()->status->value);
        $this->assertSame(0, $group->fresh()->applied_pages);
    }

    public function test_finalizer_fails_a_stale_group_with_a_structurally_incomplete_page_set(): void
    {
        $title = $this->titleWithSeasonUrls([1]);
        $group = app(SeasonvarImportTitleGroupDispatcher::class)
            ->start($title, 'seasonvar-title-refresh');
        DB::table($group->getTable())->where('id', $group->id)->update([
            'expected_pages' => 2,
            'updated_at' => now()->subDays(2),
        ]);

        $this->app->call([
            (new FinalizeSeasonvarImportTitleGroup($group->id))->withFakeQueueInteractions(),
            'handle',
        ]);

        $freshGroup = $group->fresh();
        $this->assertSame('failed', $freshGroup->status->value);
        $this->assertSame('page_set_mismatch', $freshGroup->terminal_reason_code?->value);
        $this->assertSame('Набор страниц группы импорта неполон.', $freshGroup->last_error);
        $this->assertNotNull($freshGroup->finished_at);
        $this->assertSame('failed', $group->run->fresh()->status);
        $this->assertSame(1, $group->run->fresh()->failed);
        $this->assertSame(
            'failed',
            app(CatalogTitleRefreshStateStore::class)->read($title->id)->status?->value,
        );
    }

    public function test_finalizer_salvages_prepared_siblings_after_a_stale_page_deadline(): void
    {
        $title = $this->titleWithSeasonUrls([1, 2]);
        $group = app(SeasonvarImportTitleGroupDispatcher::class)
            ->start($title, 'seasonvar-title-refresh');
        $rows = $group->preparedPages()->with('sourcePage')->get();
        $prepared = $rows->first();
        $expired = $rows->last();
        $this->prepareRow($prepared, 1, 2);
        DB::table($expired->getTable())
            ->where('id', $expired->id)
            ->update(['updated_at' => now()->subDays(2)]);
        DB::table($group->getTable())
            ->where('id', $group->id)
            ->update(['updated_at' => now()->subDays(2)]);

        $this->app->call([
            (new FinalizeSeasonvarImportTitleGroup($group->id))->withFakeQueueInteractions(),
            'handle',
        ]);

        $freshGroup = $group->fresh();
        $this->assertSame('partial', $freshGroup->status->value);
        $this->assertSame('preparation_deadline_exceeded', $freshGroup->terminal_reason_code?->value);
        $this->assertSame(1, $freshGroup->failed_pages);
        $this->assertSame(1, $freshGroup->applied_pages);
        $this->assertSame('failed', $expired->fresh()->status->value);
        $this->assertSame(
            'Подготовка страницы не завершилась в допустимое время.',
            $expired->fresh()->last_error,
        );
        $this->assertSame('partial', $group->run->fresh()->status);
        $this->assertSame(1, $group->run->fresh()->failed);
        $this->assertSame(
            'partial',
            app(CatalogTitleRefreshStateStore::class)->read($title->id)->status?->value,
        );
    }

    public function test_finalizer_records_a_stable_reason_when_no_page_can_be_applied(): void
    {
        $title = $this->titleWithSeasonUrls([1]);
        $group = app(SeasonvarImportTitleGroupDispatcher::class)
            ->start($title, 'seasonvar-title-refresh');
        $row = $group->preparedPages()->firstOrFail();
        $row->markFailed('Seasonvar вернул HTTP 404.');
        $group->update(['failed_pages' => 1]);

        $this->app->call([
            (new FinalizeSeasonvarImportTitleGroup($group->id))->withFakeQueueInteractions(),
            'handle',
        ]);

        $freshGroup = $group->fresh();
        $this->assertSame('failed', $freshGroup->status->value);
        $this->assertSame('no_prepared_pages', $freshGroup->terminal_reason_code?->value);
        $this->assertSame('Ни одна страница сезона не подготовлена.', $freshGroup->last_error);
    }

    public function test_failed_finalizer_records_a_stable_reason_without_private_exception_text(): void
    {
        $title = $this->titleWithSeasonUrls([1]);
        $group = app(SeasonvarImportTitleGroupDispatcher::class)
            ->start($title, 'seasonvar-title-refresh');

        (new FinalizeSeasonvarImportTitleGroup($group->id))
            ->failed(new RuntimeException('private payload https://seasonvar.ru/private?token=secret'));

        $freshGroup = $group->fresh();
        $this->assertSame('failed', $freshGroup->status->value);
        $this->assertSame('finalizer_deadline_exceeded', $freshGroup->terminal_reason_code?->value);
        $this->assertSame('Группа сезонов не финализирована в допустимое время.', $freshGroup->last_error);
        $this->assertStringNotContainsString('secret', (string) $freshGroup->last_error);
        $this->assertSame($freshGroup->last_error, $group->run->fresh()->last_error);
    }

    public function test_finalizer_applies_shuffled_pages_to_one_title_and_completes_manifest(): void
    {
        config(['cache-architecture.warming.enabled' => true]);
        $title = $this->titleWithSeasonUrls([9, 1, 2]);
        $group = app(SeasonvarImportTitleGroupDispatcher::class)
            ->start($title, 'seasonvar-title-refresh');
        $this->prepareAllRows($group->preparedPages()->with('sourcePage')->get(), [9 => 2, 1 => 3, 2 => 4]);
        $job = (new FinalizeSeasonvarImportTitleGroup($group->id))->withFakeQueueInteractions();

        $this->app->call([$job, 'handle']);

        $job->assertNotReleased();
        $this->assertSame('completed', $group->fresh()->status->value);
        $this->assertSame(3, $group->fresh()->applied_pages);
        $this->assertSame('completed', $group->run->fresh()->status);
        $this->assertSame(1, CatalogTitle::query()->count());
        $this->assertSame([1, 2, 9], $title->seasons()->pluck('number')->all());
        $this->assertSame(9, $title->episodes()->count());
        $this->assertSame(0, data_get($group->run->fresh()->summary, 'title_manifest.missing_local'));
        $this->assertSame(3, data_get($group->run->fresh()->summary, 'title_manifest.source_seasons'));
        $this->assertSame(0, data_get($group->run->fresh()->summary, 'title_manifest.local_episodes_before'));
        $this->assertSame(9, data_get($group->run->fresh()->summary, 'title_manifest.local_episodes_after'));
        $this->assertSame(9, data_get($group->run->fresh()->summary, 'title_manifest.added'));
        $this->assertSame(3, data_get($group->run->fresh()->summary, 'title_manifest.unchanged'));
        $this->assertSame(0, data_get($group->run->fresh()->summary, 'title_manifest.failed'));
        $this->assertSame(1, ApiSyncChange::query()
            ->where('operation', ApiSyncChange::OPERATION_UPSERT)
            ->where('resource_key', 'ryzaia-8')
            ->count());
        $this->assertSame(
            'completed',
            app(CatalogTitleRefreshStateStore::class)->read($title->id)->status?->value,
        );
        $warmWork = app(CatalogCacheWarmRequestStore::class)->claim(10);
        $this->assertNotNull($warmWork);
        $this->assertSame([$title->id], $warmWork->titleIds);
        Queue::assertPushed(WarmCatalogCaches::class, 1);
    }

    public function test_finalizer_publishes_one_invalidation_when_merge_fails_after_pages_commit(): void
    {
        $title = $this->titleWithSeasonUrls([1, 2]);
        $group = app(SeasonvarImportTitleGroupDispatcher::class)
            ->start($title, 'seasonvar-title-refresh');
        $this->prepareAllRows($group->preparedPages()->with('sourcePage')->get(), [1 => 2, 2 => 2]);
        $this->mock(SeasonvarTitleMerger::class, function (MockInterface $mock): void {
            $mock->shouldReceive('mergeForCanonicalSlug')
                ->once()
                ->with('ryzaia-8')
                ->andThrow(new RuntimeException('Merge failed after prepared pages committed.'));
        });

        try {
            $this->app->call([
                (new FinalizeSeasonvarImportTitleGroup($group->id))->withFakeQueueInteractions(),
                'handle',
            ]);
            $this->fail('Finalizer accepted a failed title merge.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Merge failed after prepared pages committed.', $exception->getMessage());
        }

        $this->assertSame(4, $title->episodes()->count());
        $this->assertSame(1, ApiSyncChange::query()
            ->where('operation', ApiSyncChange::OPERATION_UPSERT)
            ->where('resource_key', 'ryzaia-8')
            ->count());
    }

    public function test_finalizer_publishes_one_invalidation_when_the_first_page_fails_after_catalog_commit(): void
    {
        $title = $this->titleWithSeasonUrls([1, 2]);
        $group = app(SeasonvarImportTitleGroupDispatcher::class)
            ->start($title, 'seasonvar-title-refresh');
        $this->prepareAllRows($group->preparedPages()->with('sourcePage')->get(), [1 => 2, 2 => 2]);
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER fail_relation_metadata_after_catalog_commit
            BEFORE UPDATE OF relation_metadata_version ON catalog_titles
            BEGIN
                SELECT RAISE(FAIL, 'forced post-commit failure');
            END
            SQL);

        try {
            try {
                $this->app->call([
                    (new FinalizeSeasonvarImportTitleGroup($group->id))->withFakeQueueInteractions(),
                    'handle',
                ]);
                $this->fail('Finalizer accepted a post-commit importer failure.');
            } catch (Throwable $exception) {
                $this->assertStringContainsString('forced post-commit failure', $exception->getMessage());
            }
        } finally {
            DB::unprepared('DROP TRIGGER IF EXISTS fail_relation_metadata_after_catalog_commit');
        }

        $this->assertGreaterThan(0, $title->episodes()->count());
        $this->assertSame(1, ApiSyncChange::query()
            ->where('operation', ApiSyncChange::OPERATION_UPSERT)
            ->where('resource_key', 'ryzaia-8')
            ->count());
    }

    public function test_finalizer_marks_group_and_refresh_partial_when_one_page_failed(): void
    {
        $title = $this->titleWithSeasonUrls([1, 2]);
        $group = app(SeasonvarImportTitleGroupDispatcher::class)
            ->start($title, 'seasonvar-title-refresh');
        $rows = $group->preparedPages()->with('sourcePage')->get();
        $this->prepareRow($rows->first(), 1, 2);
        $failed = $rows->last();
        $failed->markFailed('HTTP 503');
        $group->update(['failed_pages' => 1]);
        $job = (new FinalizeSeasonvarImportTitleGroup($group->id))->withFakeQueueInteractions();

        $this->app->call([$job, 'handle']);

        $this->assertSame('partial', $group->fresh()->status->value);
        $this->assertSame('partial', $group->run->fresh()->status);
        $this->assertSame(1, $group->fresh()->applied_pages);
        $this->assertSame(
            'partial',
            app(CatalogTitleRefreshStateStore::class)->read($title->id)->status?->value,
        );
    }

    public function test_finalizer_counts_an_invalid_prepared_payload_once(): void
    {
        $title = $this->titleWithSeasonUrls([1, 2]);
        $group = app(SeasonvarImportTitleGroupDispatcher::class)
            ->start($title, 'seasonvar-title-refresh');
        $rows = $group->preparedPages()->with('sourcePage')->get();
        $this->prepareRow($rows->first(), 1, 2);
        $invalid = $rows->last();
        $invalid->markPrepared(
            ['catalog_data' => ['title' => null]],
            [],
            hash('sha256', 'invalid-prepared-page'),
            1,
        );
        $group->increment('prepared_pages');
        $job = (new FinalizeSeasonvarImportTitleGroup($group->id))->withFakeQueueInteractions();

        $this->app->call([$job, 'handle']);

        $this->assertSame('partial', $group->fresh()->status->value);
        $this->assertSame(1, $group->fresh()->failed_pages);
        $this->assertSame(1, $group->fresh()->applied_pages);
    }

    public function test_finalizer_rejects_a_payload_from_an_outdated_parser_version(): void
    {
        $title = $this->titleWithSeasonUrls([1, 2]);
        $group = app(SeasonvarImportTitleGroupDispatcher::class)
            ->start($title, 'seasonvar-title-refresh');
        $rows = $group->preparedPages()->with('sourcePage')->get();
        $this->prepareRow($rows->first(), 1, 2);
        $outdated = $rows->last();
        $this->prepareRow($outdated, 2, 2);
        $payload = $outdated->payload;
        $payload['parser_version'] = SeasonvarCatalogParser::METADATA_VERSION - 1;
        $outdated->update([
            'parser_version' => SeasonvarCatalogParser::METADATA_VERSION - 1,
            'payload' => $payload,
        ]);

        $this->app->call([(new FinalizeSeasonvarImportTitleGroup($group->id))->withFakeQueueInteractions(), 'handle']);

        $this->assertSame('partial', $group->fresh()->status->value);
        $this->assertSame(1, $group->fresh()->failed_pages);
        $this->assertSame(1, $group->fresh()->applied_pages);
        $this->assertSame(1, $group->run->fresh()->failed);
        $this->assertSame('failed', $outdated->fresh()->status->value);
    }

    public function test_finalizer_rejects_a_payload_with_mismatched_staging_hash(): void
    {
        $title = $this->titleWithSeasonUrls([1, 2]);
        $group = app(SeasonvarImportTitleGroupDispatcher::class)
            ->start($title, 'seasonvar-title-refresh');
        $rows = $group->preparedPages()->with('sourcePage')->get();
        $this->prepareRow($rows->first(), 1, 2);
        $mismatched = $rows->last();
        $this->prepareRow($mismatched, 2, 2);
        $mismatched->update(['content_hash' => hash('sha256', 'different-staging-content')]);

        $this->app->call([(new FinalizeSeasonvarImportTitleGroup($group->id))->withFakeQueueInteractions(), 'handle']);

        $this->assertSame('partial', $group->fresh()->status->value);
        $this->assertSame(1, $group->fresh()->failed_pages);
        $this->assertSame(1, $group->fresh()->applied_pages);
        $this->assertSame('failed', $mismatched->fresh()->status->value);
    }

    public function test_finalizer_revalidates_the_persisted_source_page_family_before_apply(): void
    {
        $title = $this->titleWithSeasonUrls([1, 2]);
        $group = app(SeasonvarImportTitleGroupDispatcher::class)
            ->start($title, 'seasonvar-title-refresh');
        $rows = $group->preparedPages()->with('sourcePage')->get();
        $this->prepareRow($rows->first(), 1, 2);
        $unrelated = $rows->last();
        $this->prepareRow($unrelated, 2, 2);
        $unrelatedUrl = 'https://seasonvar.ru/serial-99999-Drugoj_serial_psother-2-season.html';
        $unrelated->sourcePage->update([
            'url' => $unrelatedUrl,
            'url_hash' => hash('sha256', $unrelatedUrl),
        ]);

        $this->app->call([(new FinalizeSeasonvarImportTitleGroup($group->id))->withFakeQueueInteractions(), 'handle']);

        $this->assertSame('partial', $group->fresh()->status->value);
        $this->assertSame(1, $group->fresh()->failed_pages);
        $this->assertSame(1, $group->fresh()->applied_pages);
        $this->assertSame('failed', $unrelated->fresh()->status->value);
    }

    private function prepareAllRows($rows, array $episodesBySeason): void
    {
        foreach ($rows->shuffle() as $row) {
            $seasonNumber = $this->seasonNumber($row->sourcePage->url);
            $this->prepareRow($row, $seasonNumber, $episodesBySeason[$seasonNumber]);
        }
    }

    private function prepareRow(SeasonvarImportPreparedPage $row, int $seasonNumber, int $episodeCount): void
    {
        $url = $row->sourcePage->url;
        $episodes = collect(range(1, $episodeCount))->map(fn (int $number): array => [
            'season_number' => $seasonNumber,
            'number' => $number,
            'title' => $number.' серия',
            'source_url' => $url.'#'.$number.'_seriya',
        ])->all();
        $data = SeasonvarCatalogData::fromParsed([
            'title' => 'Рыжая',
            'original_title' => null,
            'type' => 'serial',
            'year' => 2026,
            'description' => null,
            'poster_url' => null,
            'external_id' => '24212',
            'current_season_number' => $seasonNumber,
            'seasons' => [[
                'number' => $seasonNumber,
                'title' => 'Сезон '.$seasonNumber,
                'source_url' => $url,
                'latest_episode_released_at' => null,
                'episodes_released' => $episodeCount,
                'episodes_total' => $episodeCount,
                'translation_name' => null,
                'release_status_text' => null,
            ]],
            'episodes' => $episodes,
            'media' => [],
            'taxonomies' => [],
            'ratings' => [],
            'recommendation_signals' => [],
            'aliases' => [],
            'reviews' => [],
            'parse_meta' => [
                'has_info_list' => true,
                'has_season_list' => true,
                'has_episode_script' => true,
            ],
        ]);
        $prepared = new SeasonvarPreparedCatalogPage(
            sourcePageId: $row->source_page_id,
            contentHash: hash('sha256', 'season-'.$seasonNumber),
            parserVersion: SeasonvarCatalogParser::METADATA_VERSION,
            catalogData: $data,
            discoveredSeasonUrls: [$url],
        );
        $row->markPrepared(
            $prepared->toPayload(),
            [],
            $prepared->contentHash,
            SeasonvarCatalogParser::METADATA_VERSION,
        );
        $row->group()->increment('prepared_pages');
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
            'slug' => 'ryzaia-8',
            'title' => 'Рыжая',
            'source_url' => $this->seasonUrl($seasonNumbers[0]),
            'source_url_hash' => hash('sha256', $this->seasonUrl($seasonNumbers[0])),
        ]);

        foreach ($seasonNumbers as $number) {
            $url = $this->seasonUrl($number);
            Season::factory()->for($title)->create([
                'number' => $number,
                'sort_order' => $number,
                'source_url' => $url,
                'source_url_hash' => hash('sha256', $url),
            ]);
        }

        return $title;
    }

    private function seasonUrl(int $seasonNumber): string
    {
        return sprintf('https://seasonvar.ru/serial-24212-Ryzhaya_psbdtie-%d-season.html', $seasonNumber);
    }

    private function seasonNumber(string $url): int
    {
        preg_match('/-(\d+)-season\.html$/', $url, $matches);

        return (int) ($matches[1] ?? 0);
    }
}
