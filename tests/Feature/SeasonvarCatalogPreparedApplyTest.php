<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\DTOs\Seasonvar\SeasonvarCatalogData;
use App\DTOs\Seasonvar\SeasonvarPreparedCatalogPage;
use App\Enums\ReleaseScheduleEntryType;
use App\Enums\ReleaseScheduleSource;
use App\Models\ApiSyncChange;
use App\Models\CatalogTitle;
use App\Models\Episode;
use App\Models\ReleaseScheduleEntry;
use App\Models\Source;
use App\Models\SourcePage;
use App\Services\Seasonvar\SeasonvarCatalogImporter;
use App\Services\Seasonvar\SeasonvarTitleManifestBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SeasonvarCatalogPreparedApplyTest extends TestCase
{
    use RefreshDatabase;

    public function test_prepared_pages_apply_to_one_canonical_title_without_http(): void
    {
        Http::preventStrayRequests();
        $source = $this->seasonvarSource();
        $preparedPages = collect([
            $this->preparedSeason($source, 1, 20),
            $this->preparedSeason($source, 2, 20),
            $this->preparedSeason($source, 9, 11),
        ]);
        $firstPage = $preparedPages->first()[0];
        $canonical = CatalogTitle::factory()->for($source)->create([
            'source_page_id' => $firstPage->id,
            'external_id' => '24212',
            'slug' => 'ryzaia-8',
            'title' => 'Рыжая',
            'source_url' => $firstPage->url,
            'source_url_hash' => hash('sha256', $firstPage->url),
        ]);

        foreach ($preparedPages as [$page, $prepared]) {
            app(SeasonvarCatalogImporter::class)->applyPreparedPage(
                $page,
                $prepared,
                $canonical,
            );
        }

        $this->assertSame(3, $canonical->seasons()->count());
        $this->assertSame(51, $canonical->episodes()->count());
        $this->assertDatabaseCount('catalog_titles', 1);
        $this->assertSame([1, 2, 9], $canonical->seasons()->pluck('number')->all());
        $this->assertSame(3, ApiSyncChange::query()
            ->where('operation', ApiSyncChange::OPERATION_UPSERT)
            ->where('resource_key', 'ryzaia-8')
            ->count());
    }

    public function test_local_only_episode_survives_apply_and_is_reported_by_manifest(): void
    {
        Http::preventStrayRequests();
        $source = $this->seasonvarSource();
        [$page, $prepared] = $this->preparedSeason($source, 9, 2);
        $canonical = CatalogTitle::factory()->for($source)->create([
            'source_page_id' => $page->id,
            'external_id' => '24212',
            'slug' => 'ryzaia-8',
            'title' => 'Рыжая',
            'source_url' => $page->url,
            'source_url_hash' => hash('sha256', $page->url),
        ]);
        $importer = app(SeasonvarCatalogImporter::class);
        $importer->applyPreparedPage($page, $prepared, $canonical);
        $season = $canonical->seasons()->where('number', 9)->firstOrFail();
        Episode::factory()->for($season)->create([
            'number' => 99,
            'sort_order' => 99,
            'title' => 'Локальная серия',
            'source_page_id' => null,
            'source_url' => null,
            'source_url_hash' => null,
        ]);

        $importer->applyPreparedPage($page, $prepared, $canonical);

        $manifests = app(SeasonvarTitleManifestBuilder::class);
        $sourceManifest = $manifests->fromPrepared(new Collection([$prepared]));
        $localManifest = $manifests->fromCatalog($canonical->fresh());
        $comparison = $sourceManifest->comparison($localManifest);

        $this->assertDatabaseHas('episodes', [
            'season_id' => $season->id,
            'number' => 99,
            'deleted_at' => null,
        ]);
        $this->assertSame(2, $comparison['source_episodes']);
        $this->assertSame(3, $comparison['local_episodes']);
        $this->assertSame(0, $comparison['missing_local']);
        $this->assertSame(1, $comparison['local_only']);
    }

    public function test_alias_import_keeps_one_highest_priority_row_across_types(): void
    {
        Http::preventStrayRequests();
        $source = $this->seasonvarSource();
        [$page, $prepared] = $this->preparedSeason($source, 1, 1, [
            ['name' => 'Альфа', 'type' => 'source_title', 'source' => 'title'],
            ['name' => ' альфа ', 'type' => 'alternative', 'source' => 'info'],
            ['name' => 'АЛЬФА', 'type' => 'original', 'source' => 'info'],
        ]);
        $canonical = CatalogTitle::factory()->for($source)->create([
            'source_page_id' => $page->id,
            'external_id' => '24212',
            'slug' => 'ryzaia-8',
            'title' => 'Рыжая',
            'source_url' => $page->url,
            'source_url_hash' => hash('sha256', $page->url),
        ]);

        app(SeasonvarCatalogImporter::class)->applyPreparedPage($page, $prepared, $canonical);

        $alias = $canonical->aliases()->sole();

        $this->assertSame('original', $alias->type);
        $this->assertSame('АЛЬФА', $alias->name);
    }

    public function test_prepared_current_season_release_observation_creates_a_calendar_event(): void
    {
        Http::preventStrayRequests();
        $source = $this->seasonvarSource();
        [$page, $prepared] = $this->preparedSeason($source, 1, 3, releaseStatus: [
            'latest_episode_released_at' => '2026-07-19',
            'episodes_released' => 3,
            'episodes_total' => 8,
            'translation_name' => 'Coldfilm',
            'release_status_text' => '19.07.2026 3 серия (Coldfilm) из 8',
        ]);
        $canonical = CatalogTitle::factory()->for($source)->create([
            'source_page_id' => $page->id,
            'external_id' => '24212',
            'slug' => 'ryzaia-8',
            'title' => 'Рыжая',
            'source_url' => $page->url,
            'source_url_hash' => hash('sha256', $page->url),
        ]);

        app(SeasonvarCatalogImporter::class)->applyPreparedPage($page, $prepared, $canonical);

        $episode = $canonical->episodes()->where('episodes.number', 3)->firstOrFail();
        $entry = ReleaseScheduleEntry::query()->sole();
        $this->assertSame(ReleaseScheduleEntryType::TranslationRelease, $entry->entry_type);
        $this->assertSame(ReleaseScheduleSource::Provider, $entry->source);
        $this->assertSame($episode->id, $entry->episode_id);
        $this->assertSame('2026-07-19', $entry->date_value?->toDateString());
        $this->assertSame('Coldfilm', $entry->translation_name);
        $this->assertNull($episode->released_at);
    }

    /** @return array{SourcePage, SeasonvarPreparedCatalogPage} */
    private function preparedSeason(
        Source $source,
        int $seasonNumber,
        int $episodeCount,
        array $aliases = [],
        array $releaseStatus = [],
    ): array {
        $url = sprintf(
            'https://seasonvar.ru/serial-24212-Ryzhaya_psbdtie-%d-season.html',
            $seasonNumber,
        );
        $page = SourcePage::factory()->for($source)->create([
            'url' => $url,
            'url_hash' => hash('sha256', $url),
            'page_type' => 'serial',
            'content_hash' => hash('sha256', 'season-'.$seasonNumber),
            'parse_status' => 'pending',
        ]);
        $episodes = collect(range(1, $episodeCount))
            ->map(fn (int $episode): array => [
                'season_number' => $seasonNumber,
                'number' => $episode,
                'title' => $episode.' серия',
                'source_url' => $url.'#'.$episode.'_seriya',
            ])
            ->all();
        $catalogData = SeasonvarCatalogData::fromParsed([
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
                ...$releaseStatus,
            ]],
            'episodes' => $episodes,
            'media' => [],
            'taxonomies' => [],
            'ratings' => [],
            'recommendation_signals' => [],
            'aliases' => $aliases,
            'reviews' => [],
            'parse_meta' => [
                'has_info_list' => true,
                'has_season_list' => true,
                'has_episode_script' => true,
            ],
        ]);

        return [$page, new SeasonvarPreparedCatalogPage(
            sourcePageId: $page->id,
            contentHash: $page->content_hash,
            parserVersion: 1,
            catalogData: $catalogData,
            discoveredSeasonUrls: [$url],
        )];
    }

    private function seasonvarSource(): Source
    {
        return Source::factory()->create([
            'code' => 'seasonvar',
            'base_url' => 'https://seasonvar.ru',
            'crawl_delay_seconds' => 0,
        ]);
    }
}
