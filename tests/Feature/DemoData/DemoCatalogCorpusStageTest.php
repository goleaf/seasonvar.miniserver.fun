<?php

declare(strict_types=1);

namespace Tests\Feature\DemoData;

use App\DTOs\DemoData\DemoDataOptions;
use App\Enums\CatalogCollectionSort;
use App\Enums\CatalogCollectionVisibility;
use App\Models\CatalogCollection;
use App\Models\CatalogTitle;
use App\Models\CatalogTitleUserState;
use App\Models\Episode;
use App\Models\EpisodeViewProgress;
use App\Models\LicensedMedia;
use App\Models\Season;
use App\Models\Tag;
use App\Models\User;
use App\Models\UserTag;
use App\Services\DemoData\DemoTitleSelector;
use App\Services\DemoData\Stages\DemoAccountStage;
use App\Services\DemoData\Stages\DemoCatalogActivityStage;
use App\Services\DemoData\Stages\DemoOrganizationStage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

final class DemoCatalogCorpusStageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('uploads');
        config([
            'demo-data.user_count' => 4,
            'demo-data.coverage_numerator' => 1,
            'demo-data.coverage_denominator' => 2,
            'demo-data.chunk_size' => 100,
            'demo-data.asset_disk' => 'uploads',
            'demo-data.asset_prefix' => 'demo-tests',
            'demo-data.personal_tags.minimum' => 12,
            'demo-data.personal_tags.maximum' => 12,
            'demo-data.personal_tags.per_title_minimum' => 2,
            'demo-data.personal_tags.per_title_maximum' => 7,
            'demo-data.collections.minimum' => 8,
            'demo-data.collections.maximum' => 8,
            'demo-data.collections.per_title_minimum' => 1,
            'demo-data.collections.per_title_maximum' => 3,
            'demo-data.public_tag_target' => 12,
            'session.driver' => 'array',
        ]);
    }

    public function test_organization_stage_fills_tags_collections_and_exact_half_public_assignments_idempotently(): void
    {
        CatalogTitle::factory()->count(8)->create();
        $options = DemoDataOptions::fromConfig();
        app(DemoAccountStage::class)->run($options);
        $stage = app(DemoOrganizationStage::class);
        $firstReport = $stage->run($options);
        $firstCounts = $this->organizationCounts();
        $secondReport = $stage->run($options);

        $this->assertSame('organization', $stage->key());
        $this->assertSame($firstCounts, $this->organizationCounts());
        $this->assertSame(48, $firstReport->counters['personal_tags']);
        $this->assertSame(48, $secondReport->counters['personal_tags']);
        $this->assertGreaterThanOrEqual(12, Tag::query()->publiclyEligible()->count());
        $this->assertSame(4, DB::table('catalog_title_tag')->distinct()->count('catalog_title_id'));

        $users = User::query()->whereIn('email', [
            'user1@example.com', 'user2@example.com', 'user3@example.com', 'user4@example.com',
        ])->orderBy('email')->get();
        $selector = new DemoTitleSelector($options);

        foreach ($users->values() as $offset => $user) {
            $selectedIds = $selector->selectedIds($offset + 1)->all();
            $this->assertSame(12, UserTag::query()->where('user_id', $user->id)->count());
            $this->assertSame(12, UserTag::query()
                ->where('user_id', $user->id)
                ->distinct()
                ->count('normalized_name_hash'));
            $this->assertSame(8, CatalogCollection::query()->where('owner_id', $user->id)->count());

            foreach ($selectedIds as $titleId) {
                $personalAssignments = DB::table('catalog_title_user_tag')
                    ->join('user_tags', 'user_tags.id', '=', 'catalog_title_user_tag.user_tag_id')
                    ->where('user_tags.user_id', $user->id)
                    ->where('catalog_title_user_tag.catalog_title_id', $titleId)
                    ->count();
                $collectionAssignments = DB::table('catalog_collection_items')
                    ->join('catalog_collections', 'catalog_collections.id', '=', 'catalog_collection_items.catalog_collection_id')
                    ->where('catalog_collections.owner_id', $user->id)
                    ->where('catalog_collection_items.catalog_title_id', $titleId)
                    ->count();

                $this->assertGreaterThanOrEqual(2, $personalAssignments);
                $this->assertLessThanOrEqual(7, $personalAssignments);
                $this->assertGreaterThanOrEqual(1, $collectionAssignments);
                $this->assertLessThanOrEqual(3, $collectionAssignments);
            }
        }

        $this->assertEqualsCanonicalizing(
            array_column(CatalogCollectionVisibility::cases(), 'value'),
            CatalogCollection::query()->get(['visibility'])->pluck('visibility')
                ->map(static fn (CatalogCollectionVisibility $visibility): string => $visibility->value)
                ->unique()
                ->values()
                ->all(),
        );
        $this->assertEqualsCanonicalizing(
            array_column(CatalogCollectionSort::cases(), 'value'),
            CatalogCollection::query()->get(['sort_mode'])->pluck('sort_mode')
                ->map(static fn (CatalogCollectionSort $sort): string => $sort->value)
                ->unique()
                ->values()
                ->all(),
        );

        CatalogCollection::query()->each(function (CatalogCollection $collection): void {
            $this->assertNotNull($collection->cover_path);
            $this->assertSame('image/webp', $collection->cover_mime_type);
            $this->assertStringStartsWith('catalog-collections/'.$collection->public_id.'/', (string) $collection->cover_path);
            $this->assertStringEndsWith('.webp', (string) $collection->cover_path);
            Storage::disk('uploads')->assertExists($collection->cover_path);
            $this->assertSame(
                [960, 540],
                array_slice(getimagesize(Storage::disk('uploads')->path($collection->cover_path)) ?: [], 0, 2),
            );
        });

        $duplicatePersonalPivot = DB::table('catalog_title_user_tag')
            ->select(['user_tag_id', 'catalog_title_id'])
            ->groupBy(['user_tag_id', 'catalog_title_id'])
            ->havingRaw('count(*) > 1')
            ->exists();
        $duplicateCollectionPivot = DB::table('catalog_collection_items')
            ->select(['catalog_collection_id', 'catalog_title_id'])
            ->groupBy(['catalog_collection_id', 'catalog_title_id'])
            ->havingRaw('count(*) > 1')
            ->exists();

        $this->assertFalse($duplicatePersonalPivot);
        $this->assertFalse($duplicateCollectionPivot);
    }

    public function test_organization_stage_generates_public_tags_when_normalized_hashes_already_exist(): void
    {
        CatalogTitle::factory()->count(2)->create();
        $existingTag = Tag::query()->create([
            'name' => 'Существующая метка',
            'slug' => 'sushchestvuiushchaia-metka',
        ]);
        $options = DemoDataOptions::fromConfig();
        app(DemoAccountStage::class)->run($options);

        $report = app(DemoOrganizationStage::class)->run($options);

        $this->assertSame(12, $report->counters['public_tags']);
        $this->assertModelExists($existingTag);
    }

    public function test_title_contexts_hydrate_only_boundary_release_records(): void
    {
        $title = CatalogTitle::factory()->create();
        $season = Season::factory()->create([
            'catalog_title_id' => $title->id,
            'number' => 1,
        ]);
        $episodes = collect(range(1, 5))->map(fn (int $number): Episode => Episode::factory()->create([
            'season_id' => $season->id,
            'number' => $number,
            'sort_order' => $number,
        ]));
        $media = $episodes->map(fn (Episode $episode): LicensedMedia => LicensedMedia::factory()->create([
            'catalog_title_id' => $title->id,
            'season_id' => $season->id,
            'episode_id' => $episode->id,
            'status' => 'published',
            'published_at' => now()->subDay(),
            'duration_seconds' => 2_400,
        ]));
        $retrievedEpisodes = 0;
        $retrievedMedia = 0;
        Event::listen('eloquent.retrieved: '.Episode::class, function () use (&$retrievedEpisodes): void {
            $retrievedEpisodes++;
        });
        Event::listen('eloquent.retrieved: '.LicensedMedia::class, function () use (&$retrievedMedia): void {
            $retrievedMedia++;
        });

        $context = (new DemoTitleSelector(DemoDataOptions::fromConfig()))
            ->contexts([$title->id])
            ->get($title->id);

        $this->assertNotNull($context);
        $this->assertSame($episodes->first()?->id, $context->firstEpisodeId);
        $this->assertSame($episodes->last()?->id, $context->lastEpisodeId);
        $this->assertSame($media->first()?->id, $context->licensedMediaId);
        $this->assertLessThanOrEqual(2, $retrievedEpisodes);
        $this->assertSame(1, $retrievedMedia);
    }

    public function test_catalog_activity_stage_fills_exact_state_feedback_and_real_progress_idempotently(): void
    {
        CatalogTitle::factory()->count(100)->create()->each(function (CatalogTitle $title): void {
            $season = Season::factory()->create(['catalog_title_id' => $title->id, 'number' => 1]);
            $episode = Episode::factory()->create(['season_id' => $season->id, 'number' => 1]);
            LicensedMedia::factory()->create([
                'catalog_title_id' => $title->id,
                'season_id' => $season->id,
                'episode_id' => $episode->id,
                'status' => 'published',
                'published_at' => now()->subDay(),
                'duration_seconds' => 2_400,
            ]);
        });
        $options = DemoDataOptions::fromConfig();
        app(DemoAccountStage::class)->run($options);
        $stage = app(DemoCatalogActivityStage::class);
        $first = $stage->run($options);
        $counts = [
            CatalogTitleUserState::query()->count(),
            EpisodeViewProgress::query()->count(),
        ];
        $second = $stage->run($options);

        $this->assertSame('catalog_activity', $stage->key());
        $this->assertSame(200, $first->counters['states']);
        $this->assertSame(200, $first->counters['progress']);
        $this->assertSame($first->counters, $second->counters);
        $this->assertSame($counts, [
            CatalogTitleUserState::query()->count(),
            EpisodeViewProgress::query()->count(),
        ]);

        $users = User::query()->whereIn('email', [
            'user1@example.com', 'user2@example.com', 'user3@example.com', 'user4@example.com',
        ])->get();

        foreach ($users as $user) {
            $states = CatalogTitleUserState::query()->where('user_id', $user->id)->get();
            $this->assertCount(50, $states);
            $this->assertEqualsCanonicalizing(
                ['planned', 'watching', 'paused', 'completed', 'dropped'],
                $states->pluck('watch_status')->map->value->unique()->values()->all(),
            );
            $this->assertSame(2, $states->where('recommendation_feedback.value', 'not_interested')->count());
            $this->assertSame(1, $states->where('recommendation_feedback.value', 'blacklisted')->count());

            foreach ($states as $state) {
                $this->assertGreaterThanOrEqual(1, $state->rating);
                $this->assertLessThanOrEqual(10, $state->rating);
                $this->assertGreaterThan(0, $state->watchlist_version);
                $this->assertGreaterThan(0, $state->rating_version);
                $this->assertGreaterThan(0, $state->watch_status_version);
                $this->assertNotNull($state->watchlist_updated_at);
                $this->assertNotNull($state->rating_updated_at);
                $this->assertNotNull($state->watch_status_updated_at);
            }
        }

        $statesByPair = CatalogTitleUserState::query()->get()->keyBy(
            fn (CatalogTitleUserState $state): string => $state->user_id.':'.$state->catalog_title_id,
        );

        EpisodeViewProgress::query()
            ->with('licensedMedia:id,episode_id')
            ->each(function (EpisodeViewProgress $progress) use ($statesByPair): void {
                $state = $statesByPair->get($progress->user_id.':'.$progress->catalog_title_id);

                $this->assertNotNull($state);
                $this->assertNotNull($progress->licensedMedia);
                $this->assertSame($progress->episode_id, $progress->licensedMedia->episode_id);
                $this->assertGreaterThanOrEqual(0, $progress->position_seconds);
                $this->assertLessThanOrEqual($progress->duration_seconds, $progress->position_seconds);
                $this->assertGreaterThan(0, $progress->duration_seconds);
                $this->assertGreaterThanOrEqual(0, $progress->progress_percent);
                $this->assertLessThanOrEqual(100, $progress->progress_percent);
                $this->assertTrue(Str::isUlid((string) $progress->playback_session_id));
                $this->assertSame($state->watch_status->value === 'completed', $progress->completed_at !== null);
                $this->assertNotNull($progress->first_started_at);
                $this->assertNotNull($progress->last_watched_at);
            });

        $this->assertFalse(CatalogTitleUserState::query()
            ->select(['user_id', 'catalog_title_id'])
            ->groupBy(['user_id', 'catalog_title_id'])
            ->havingRaw('count(*) > 1')
            ->exists());
        $this->assertFalse(EpisodeViewProgress::query()
            ->select(['user_id', 'episode_id'])
            ->groupBy(['user_id', 'episode_id'])
            ->havingRaw('count(*) > 1')
            ->exists());
    }

    /** @return array<string, int> */
    private function organizationCounts(): array
    {
        return [
            'personal_tags' => DB::table('user_tags')->count(),
            'personal_assignments' => DB::table('catalog_title_user_tag')->count(),
            'collections' => DB::table('catalog_collections')->count(),
            'collection_items' => DB::table('catalog_collection_items')->count(),
            'public_tags' => DB::table('tags')->count(),
            'tag_translations' => DB::table('tag_translations')->count(),
            'public_assignments' => DB::table('catalog_title_tag')->count(),
        ];
    }
}
