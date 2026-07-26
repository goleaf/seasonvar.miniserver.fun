<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\DTOs\Seasonvar\SeasonvarCatalogData;
use App\Enums\CatalogMetadataConflictStatus;
use App\Models\CatalogFieldVersion;
use App\Models\CatalogMetadataConflict;
use App\Models\CatalogMetadataObservation;
use App\Models\CatalogTitle;
use App\Models\Genre;
use App\Models\User;
use App\Services\Catalog\Quality\CatalogMetadataProvenanceRecorder;
use App\Services\Seasonvar\SeasonvarCatalogMetadataProvenance;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class CatalogMetadataProvenanceRecorderTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function provider_confirmation_is_idempotent_and_normalizes_taxonomy_values(): void
    {
        Carbon::setTestNow('2026-07-25 12:00:00');
        $title = CatalogTitle::factory()->create([
            'title' => 'Цветок зла',
            'year' => 2020,
        ]);
        $page = $title->sourcePage;
        $recorder = app(CatalogMetadataProvenanceRecorder::class);

        $recorder->recordProviderSnapshot($title, $page, [
            'title' => '  Цветок   зла ',
            'year' => 2020,
            'genres' => ['Криминал', 'Дорама', 'Криминал'],
            'countries' => ['Республика Корея'],
        ], completeTaxonomySnapshot: true);

        $yearObservation = CatalogMetadataObservation::query()
            ->where('catalog_title_id', $title->id)
            ->where('field_key', 'year')
            ->sole();
        $genreObservation = CatalogMetadataObservation::query()
            ->where('catalog_title_id', $title->id)
            ->where('field_key', 'genres')
            ->sole();

        self::assertSame(98, $yearObservation->confidence);
        self::assertTrue($yearObservation->is_publication_eligible);
        self::assertSame(['Дорама', 'Криминал'], $genreObservation->value);
        self::assertSame(96, $genreObservation->confidence);
        self::assertSame(1, CatalogFieldVersion::query()
            ->where('catalog_title_id', $title->id)
            ->where('field_key', 'year')
            ->count());

        Carbon::setTestNow('2026-07-26 09:30:00');
        $recorder->recordProviderSnapshot($title->fresh(), $page, [
            'title' => 'Цветок зла',
            'year' => 2020,
            'genres' => ['Дорама', 'Криминал'],
            'countries' => ['Республика Корея'],
        ], completeTaxonomySnapshot: true);

        self::assertSame(1, CatalogMetadataObservation::query()
            ->where('catalog_title_id', $title->id)
            ->where('field_key', 'year')
            ->count());
        self::assertSame('2026-07-26 09:30:00', $yearObservation->fresh()->last_confirmed_at->format('Y-m-d H:i:s'));
        self::assertSame(1, CatalogFieldVersion::query()
            ->where('catalog_title_id', $title->id)
            ->where('field_key', 'year')
            ->count());

        Carbon::setTestNow();
    }

    #[Test]
    public function editorial_selection_creates_a_version_and_provider_disagreement_opens_then_resolves_a_conflict(): void
    {
        $title = CatalogTitle::factory()->create([
            'title' => 'Цветок зла',
            'year' => 2020,
        ]);
        $page = $title->sourcePage;
        $actor = User::factory()->create();
        $recorder = app(CatalogMetadataProvenanceRecorder::class);
        $recorder->recordProviderSnapshot($title, $page, ['year' => 2020]);

        $title->forceFill(['year' => 2021])->save();
        $recorder->recordEditorialSelection($title->fresh(), $actor, ['year']);
        $recorder->recordProviderSnapshot($title->fresh(), $page, ['year' => 2020]);

        $versions = CatalogFieldVersion::query()
            ->where('catalog_title_id', $title->id)
            ->where('field_key', 'year')
            ->orderBy('version')
            ->get();
        $conflict = CatalogMetadataConflict::query()
            ->where('catalog_title_id', $title->id)
            ->where('field_key', 'year')
            ->sole();

        self::assertSame([1, 2], $versions->pluck('version')->all());
        self::assertSame(2021, $versions->last()->value);
        self::assertSame($actor->id, $versions->last()->actor_id);
        self::assertNotNull($versions->first()->superseded_at);
        self::assertSame(CatalogMetadataConflictStatus::Open, $conflict->status);

        $recorder->recordProviderSnapshot($title->fresh(), $page, ['year' => 2021]);

        self::assertSame(CatalogMetadataConflictStatus::Resolved, $conflict->fresh()->status);
        self::assertNotNull($conflict->fresh()->resolved_at);
        self::assertSame(2, CatalogFieldVersion::query()
            ->where('catalog_title_id', $title->id)
            ->where('field_key', 'year')
            ->count());
    }

    #[Test]
    public function missing_and_incomplete_provider_evidence_is_not_publication_eligible(): void
    {
        $title = CatalogTitle::factory()->create([
            'poster_url' => null,
        ]);
        $recorder = app(CatalogMetadataProvenanceRecorder::class);

        $recorder->recordProviderSnapshot($title, $title->sourcePage, [
            'poster_url' => null,
            'genres' => ['Дорама'],
        ], completeTaxonomySnapshot: false);

        $poster = CatalogMetadataObservation::query()
            ->where('catalog_title_id', $title->id)
            ->where('field_key', 'poster_url')
            ->sole();
        $genres = CatalogMetadataObservation::query()
            ->where('catalog_title_id', $title->id)
            ->where('field_key', 'genres')
            ->sole();

        self::assertSame(35, $poster->confidence);
        self::assertFalse($poster->is_publication_eligible);
        self::assertSame(70, $genres->confidence);
        self::assertFalse($genres->is_publication_eligible);
    }

    #[Test]
    public function incomplete_taxonomy_observation_does_not_replace_the_selected_catalog_value(): void
    {
        $title = CatalogTitle::factory()->create();
        $drama = Genre::query()->create([
            'name' => 'Дорама',
            'slug' => 'dorama-provenance-test',
        ]);
        $crime = Genre::query()->create([
            'name' => 'Криминал',
            'slug' => 'crime-provenance-test',
        ]);
        $title->genres()->attach([$drama->id, $crime->id]);

        app(CatalogMetadataProvenanceRecorder::class)->recordProviderSnapshot(
            $title,
            $title->sourcePage,
            ['genres' => ['Криминал']],
            completeTaxonomySnapshot: false,
        );

        $observation = CatalogMetadataObservation::query()
            ->whereBelongsTo($title)
            ->where('field_key', 'genres')
            ->sole();
        $version = CatalogFieldVersion::query()
            ->whereBelongsTo($title)
            ->where('field_key', 'genres')
            ->sole();

        self::assertSame(['Криминал'], $observation->value);
        self::assertSame(['Дорама', 'Криминал'], $version->value);
        self::assertNull($version->observation_id);
        self::assertSame('legacy', $version->source_kind->value);
        self::assertDatabaseHas('catalog_metadata_conflicts', [
            'catalog_title_id' => $title->id,
            'field_key' => 'genres',
            'status' => 'open',
        ]);
    }

    #[Test]
    public function seasonvar_adapter_records_the_validated_catalog_dto(): void
    {
        $title = CatalogTitle::factory()->create([
            'title' => 'Цветок зла',
            'year' => 2020,
        ]);
        $data = new SeasonvarCatalogData(
            title: 'Цветок зла',
            originalTitle: 'Flower of Evil',
            type: 'serial',
            year: 2020,
            description: 'Криминальная дорама.',
            posterUrl: 'https://seasonvar.ru/poster.jpg',
            externalId: '42',
            currentSeasonNumber: 1,
            seasons: [],
            episodes: [],
            media: [],
            taxonomies: [
                ['type' => 'genre', 'name' => 'Дорама'],
                ['type' => 'country', 'name' => 'Республика Корея'],
            ],
            ratings: [],
            recommendationSignals: [],
            aliases: [],
            reviews: [],
            parseMeta: [
                'has_info_list' => true,
                'has_season_list' => false,
                'has_episode_script' => false,
            ],
        );

        app(SeasonvarCatalogMetadataProvenance::class)->record(
            $title,
            $title->sourcePage,
            $data,
        );

        self::assertDatabaseHas('catalog_metadata_observations', [
            'catalog_title_id' => $title->id,
            'field_key' => 'year',
            'confidence' => 98,
        ]);
    }

    #[Test]
    public function repeated_snapshots_reuse_the_schema_capability_check(): void
    {
        $title = CatalogTitle::factory()->create(['year' => 2020]);
        $recorder = app(CatalogMetadataProvenanceRecorder::class);

        DB::enableQueryLog();
        $recorder->recordProviderSnapshot($title, $title->sourcePage, ['year' => 2020]);
        $recorder->recordProviderSnapshot($title->fresh(), $title->sourcePage, ['year' => 2020]);
        $schemaQueryCount = collect(DB::getQueryLog())
            ->filter(
                static fn (array $query): bool => str_contains(
                    (string) ($query['query'] ?? ''),
                    'sqlite_master',
                ),
            )
            ->count();
        DB::disableQueryLog();

        self::assertLessThanOrEqual(3, $schemaQueryCount);
    }
}
