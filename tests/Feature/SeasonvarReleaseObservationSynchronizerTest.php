<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ReleaseDatePrecision;
use App\Enums\ReleaseScheduleEntryType;
use App\Enums\ReleaseScheduleSource;
use App\Enums\ReleaseScheduleStatus;
use App\Models\CatalogTitle;
use App\Models\Episode;
use App\Models\LicensedMedia;
use App\Models\ReleaseScheduleEntry;
use App\Models\Season;
use App\Models\Source;
use App\Models\SourcePage;
use App\Services\Seasonvar\SeasonvarReleaseObservationSynchronizer;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class SeasonvarReleaseObservationSynchronizerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow('2026-07-20 12:00:00 UTC');
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_it_creates_an_exact_provider_translation_event_for_the_named_episode(): void
    {
        [$title, $season, $episode, $sourcePage] = $this->catalogContext(
            translationName: 'Coldfilm',
            releaseStatusText: '19.07.2026 3 серия (Coldfilm) из 8',
        );

        $entry = app(SeasonvarReleaseObservationSynchronizer::class)
            ->synchronize($title, $season, $sourcePage);

        $this->assertNotNull($entry);
        $this->assertSame(ReleaseScheduleEntryType::TranslationRelease, $entry->entry_type);
        $this->assertSame(ReleaseScheduleSource::Provider, $entry->source);
        $this->assertSame(ReleaseDatePrecision::ExactDate, $entry->precision);
        $this->assertSame(ReleaseScheduleStatus::Released, $entry->status);
        $this->assertSame('2026-07-19', $entry->date_value?->toDateString());
        $this->assertSame('2026-07-19', $entry->released_at?->toDateString());
        $this->assertSame($title->id, $entry->catalog_title_id);
        $this->assertSame($season->id, $entry->season_id);
        $this->assertSame($episode->id, $entry->episode_id);
        $this->assertSame(3, $entry->episode_number);
        $this->assertSame('Coldfilm', $entry->translation_name);
        $this->assertSame('seasonvar:source-page:'.$sourcePage->id, $entry->source_reference);
        $this->assertNull($episode->fresh()->released_at);
        $this->assertSame(1, $entry->corrections()->count());
    }

    public function test_it_distinguishes_subtitles_and_translationless_episode_observations(): void
    {
        [$title, $season, $episode, $sourcePage] = $this->catalogContext(
            translationName: 'Субтитры',
            releaseStatusText: '19.07.2026 3 серия (Субтитры) из 8',
        );
        $synchronizer = app(SeasonvarReleaseObservationSynchronizer::class);

        $subtitle = $synchronizer->synchronize($title, $season, $sourcePage);

        $this->assertSame(ReleaseScheduleEntryType::SubtitleRelease, $subtitle?->entry_type);
        $this->assertNull($subtitle?->translation_name);
        $this->assertSame($episode->id, $subtitle?->episode_id);

        $season->forceFill([
            'translation_name' => null,
            'release_status_text' => '19.07.2026 3 серия из 8',
        ])->save();

        $episodeRelease = $synchronizer->synchronize($title, $season->fresh(), $sourcePage);

        $this->assertSame(ReleaseScheduleEntryType::EpisodeRelease, $episodeRelease?->entry_type);
        $this->assertNull($episodeRelease?->translation_name);
        $this->assertSame(2, ReleaseScheduleEntry::query()->count());
    }

    public function test_repeat_is_a_noop_date_change_is_corrected_and_stronger_sources_are_preserved(): void
    {
        [$title, $season, $episode, $sourcePage] = $this->catalogContext(
            translationName: 'RuDub',
            releaseStatusText: '19.07.2026 3 серия (RuDub) из 8',
        );
        $synchronizer = app(SeasonvarReleaseObservationSynchronizer::class);

        $first = $synchronizer->synchronize($title, $season, $sourcePage);
        $second = $synchronizer->synchronize($title, $season->fresh(), $sourcePage);

        $this->assertSame($first?->id, $second?->id);
        $this->assertSame(1, $first?->fresh()->revision);
        $this->assertSame(1, $first?->corrections()->count());

        $season->forceFill([
            'latest_episode_released_at' => '2026-07-20',
            'release_status_text' => '20.07.2026 3 серия (RuDub) из 8',
        ])->save();
        $corrected = $synchronizer->synchronize($title, $season->fresh(), $sourcePage);

        $this->assertSame($first?->id, $corrected?->id);
        $this->assertSame(2, $corrected?->revision);
        $this->assertSame('2026-07-20', $corrected?->date_value?->toDateString());
        $this->assertSame(2, $corrected?->corrections()->count());

        $corrected?->forceFill([
            'source' => ReleaseScheduleSource::Editorial,
            'is_locked' => true,
            'date_value' => '2026-07-18',
        ])->save();
        $season->forceFill([
            'latest_episode_released_at' => '2026-07-21',
            'release_status_text' => '21.07.2026 3 серия (RuDub) из 8',
        ])->save();

        $preserved = $synchronizer->synchronize($title, $season->fresh(), $sourcePage);

        $this->assertNull($preserved);
        $this->assertSame('2026-07-18', $corrected->fresh()->date_value?->toDateString());
        $this->assertSame(ReleaseScheduleSource::Editorial, $corrected->fresh()->source);
        $this->assertSame($episode->id, $corrected->fresh()->episode_id);
    }

    public function test_it_skips_incomplete_observations_and_missing_episode_targets(): void
    {
        [$title, $season, , $sourcePage] = $this->catalogContext(
            translationName: 'Coldfilm',
            releaseStatusText: null,
        );
        $synchronizer = app(SeasonvarReleaseObservationSynchronizer::class);

        $this->assertNull($synchronizer->synchronize($title, $season, $sourcePage));

        $season->forceFill([
            'episodes_released' => 4,
            'release_status_text' => '19.07.2026 4 серия (Coldfilm) из 8',
        ])->save();

        $this->assertNull($synchronizer->synchronize($title, $season->fresh(), $sourcePage));
        $this->assertSame(0, ReleaseScheduleEntry::query()->count());
    }

    public function test_a_future_observation_is_confirmed_without_a_fake_release_timestamp(): void
    {
        [$title, $season, , $sourcePage] = $this->catalogContext(
            translationName: 'Coldfilm',
            releaseStatusText: '21.07.2026 3 серия (Coldfilm) из 8',
            releaseDate: '2026-07-21',
        );

        $entry = app(SeasonvarReleaseObservationSynchronizer::class)
            ->synchronize($title, $season, $sourcePage);

        $this->assertSame(ReleaseScheduleStatus::Confirmed, $entry?->status);
        $this->assertSame('2026-07-21', $entry?->date_value?->toDateString());
        $this->assertNull($entry?->released_at);
    }

    public function test_a_portal_publication_upgrades_provider_precision_without_stale_date_fields(): void
    {
        [$title, $season, $episode, $sourcePage] = $this->catalogContext(
            translationName: 'RuDub',
            releaseStatusText: '19.07.2026 3 серия (RuDub) из 8',
        );
        $providerEntry = app(SeasonvarReleaseObservationSynchronizer::class)
            ->synchronize($title, $season, $sourcePage);

        $media = LicensedMedia::factory()->create([
            'catalog_title_id' => $title->id,
            'season_id' => $season->id,
            'episode_id' => $episode->id,
            'status' => 'published',
            'published_at' => '2026-07-19 15:30:00',
            'translation_name' => 'RuDub',
            'path' => 'licensed/provider-upgrade.mp4',
        ]);
        $translationEntry = ReleaseScheduleEntry::query()
            ->where('logical_key', $providerEntry?->logical_key)
            ->firstOrFail();

        $this->assertSame(ReleaseScheduleSource::Portal, $translationEntry->source);
        $this->assertSame(ReleaseDatePrecision::ExactDateTime, $translationEntry->precision);
        $this->assertSame($media->id, $translationEntry->licensed_media_id);
        $this->assertNotNull($translationEntry->starts_at);
        $this->assertNull($translationEntry->date_value);
        $this->assertNull($translationEntry->date_end);
        $this->assertNull($translationEntry->release_year);
        $this->assertNull($translationEntry->release_month);
        $this->assertNull($translationEntry->release_quarter);
    }

    /** @return array{CatalogTitle, Season, Episode, SourcePage} */
    private function catalogContext(
        ?string $translationName,
        ?string $releaseStatusText,
        string $releaseDate = '2026-07-19',
    ): array {
        $source = Source::factory()->create([
            'code' => 'seasonvar',
            'base_url' => 'https://seasonvar.ru',
            'crawl_delay_seconds' => 0,
        ]);
        $url = 'https://seasonvar.ru/serial-49406-Vestis.html';
        $sourcePage = SourcePage::factory()->for($source)->create([
            'url' => $url,
            'url_hash' => hash('sha256', $url),
            'page_type' => 'serial',
            'parse_status' => 'parsed',
        ]);
        $title = CatalogTitle::factory()->for($source)->create([
            'source_page_id' => $sourcePage->id,
            'external_id' => '49406',
            'source_url' => $url,
            'source_url_hash' => hash('sha256', $url),
        ]);
        $season = Season::factory()->for($title)->create([
            'source_page_id' => $sourcePage->id,
            'number' => 1,
            'latest_episode_released_at' => $releaseDate,
            'episodes_released' => 3,
            'episodes_total' => 8,
            'translation_name' => $translationName,
            'release_status_text' => $releaseStatusText,
        ]);
        $episode = Episode::factory()->for($season)->create([
            'source_page_id' => $sourcePage->id,
            'number' => 3,
            'sort_order' => 3,
            'released_at' => null,
        ]);

        return [$title, $season, $episode, $sourcePage];
    }
}
