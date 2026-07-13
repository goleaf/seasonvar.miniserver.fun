<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\DTOs\Seasonvar\SeasonvarCatalogData;
use App\DTOs\Seasonvar\SeasonvarPreparedCatalogPage;
use App\Jobs\FinalizeSeasonvarImportTitleGroup;
use App\Models\CatalogTitle;
use App\Models\Season;
use App\Models\SeasonvarImportPreparedPage;
use App\Models\Source;
use App\Services\Seasonvar\CatalogTitleRefreshStateStore;
use App\Services\Seasonvar\SeasonvarCatalogParser;
use App\Services\Seasonvar\SeasonvarImportTitleGroupDispatcher;
use App\Services\Seasonvar\SeasonvarPageClaimManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

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

    public function test_finalizer_releases_while_a_page_is_not_terminal(): void
    {
        $title = $this->titleWithSeasonUrls([1, 2]);
        $group = app(SeasonvarImportTitleGroupDispatcher::class)
            ->start($title, 'seasonvar-title-refresh');
        $job = (new FinalizeSeasonvarImportTitleGroup($group->id))->withFakeQueueInteractions();

        $this->app->call([$job, 'handle']);

        $job->assertReleased(delay: 7);
        $this->assertSame('running', $group->fresh()->status->value);
        $this->assertSame(0, $group->fresh()->applied_pages);
    }

    public function test_finalizer_releases_while_a_terminal_page_still_has_a_live_claim(): void
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
        $job = (new FinalizeSeasonvarImportTitleGroup($group->id))->withFakeQueueInteractions();

        $this->app->call([$job, 'handle']);

        $job->assertReleased(delay: 7);
        $this->assertSame('running', $group->fresh()->status->value);
        $this->assertSame(0, $group->fresh()->applied_pages);
    }

    public function test_finalizer_applies_shuffled_pages_to_one_title_and_completes_manifest(): void
    {
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
        $this->assertSame(
            'completed',
            app(CatalogTitleRefreshStateStore::class)->read($title->id)->status?->value,
        );
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
