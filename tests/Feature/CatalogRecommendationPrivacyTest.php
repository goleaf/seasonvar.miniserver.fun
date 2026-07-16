<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\DTOs\CatalogRecommendationContext;
use App\Enums\CatalogCollectionModerationStatus;
use App\Enums\CatalogCollectionSort;
use App\Enums\CatalogCollectionType;
use App\Enums\CatalogCollectionVisibility;
use App\Enums\CatalogRecommendationReason;
use App\Enums\CatalogRecommendationSource;
use App\Enums\CatalogRecommendationType;
use App\Enums\CatalogWatchStatus;
use App\Models\CatalogCollection;
use App\Models\CatalogCollectionItem;
use App\Models\CatalogRecommendationBuild;
use App\Models\CatalogTitle;
use App\Models\CatalogTitleRecommendation;
use App\Models\CatalogTitleUserState;
use App\Models\Episode;
use App\Models\EpisodeViewProgress;
use App\Models\Genre;
use App\Models\LicensedMedia;
use App\Models\Season;
use App\Models\User;
use App\Models\UserTag;
use App\Services\Catalog\CatalogRecommendationCache;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class CatalogRecommendationPrivacyTest extends TestCase
{
    use RefreshDatabase;

    public function test_personalized_candidates_bypass_the_shared_tiered_cache(): void
    {
        Cache::spy();
        $context = new CatalogRecommendationContext(
            type: CatalogRecommendationType::Personalized,
            user: (new User)->forceFill(['id' => 765_432]),
            locale: 'ru',
            seed: 'request-local-seed',
        );
        $expected = [[
            'id' => 10,
            'score' => 20,
            'source' => CatalogRecommendationSource::UserHistory->value,
            'reason' => CatalogRecommendationReason::BecauseHistory->value,
        ]];

        $actual = app(CatalogRecommendationCache::class)->rememberPublic(
            $context,
            static fn (): array => $expected,
        );

        $this->assertSame($expected, $actual);
        Cache::shouldNotHaveReceived('store');
    }

    public function test_rendered_personalized_page_and_public_api_do_not_expose_private_profile_inputs(): void
    {
        config([
            'recommendations.personalized_v2.enabled' => true,
            'recommendations.personalized_v2.rollout_percent' => 100,
        ]);
        $user = User::factory()->create(['id' => 765_432]);
        $source = CatalogTitle::factory()->create([
            'id' => 876_543,
            'title' => 'СЕКРЕТНЫЙ ИСТОЧНИК ПРОФИЛЯ',
        ]);
        $candidate = CatalogTitle::factory()->create([
            'id' => 345_678,
            'title' => 'Безопасная рекомендация',
            'slug' => 'bezopasnaia-rekomendatsiia-privacy',
        ]);
        LicensedMedia::factory()->for($candidate)->create([
            'status' => 'published',
            'published_at' => now(),
        ]);
        CatalogTitleUserState::query()->create([
            'user_id' => $user->id,
            'catalog_title_id' => $source->id,
            'watch_status' => CatalogWatchStatus::Completed,
            'watch_status_updated_at' => now(),
            'rating' => 10,
            'rating_updated_at' => now(),
        ]);
        $season = Season::factory()->for($source)->create();
        $episode = Episode::factory()->for($season)->create(['number' => 1]);
        EpisodeViewProgress::query()->create([
            'user_id' => $user->id,
            'catalog_title_id' => $source->id,
            'episode_id' => $episode->id,
            'position_seconds' => 54_321,
            'duration_seconds' => 60_000,
            'progress_percent' => 91,
            'last_watched_at' => now(),
        ]);
        $this->attachPrivateCollection($user, $source);
        $this->attachPrivateTag($user, $source);
        $this->createNegativeFeatureEvidence($user);
        CatalogRecommendationBuild::query()->create([
            'algorithm_version' => 'v6',
            'feature_version' => 'tokens-v2',
            'status' => 'active',
            'metrics' => ['score_min' => 600, 'score_median' => 1_000, 'score_p95' => 1_600],
            'started_at' => now(),
            'activated_at' => now(),
        ]);
        CatalogTitleRecommendation::query()->create([
            'catalog_title_id' => $source->id,
            'recommended_title_id' => $candidate->id,
            'score' => 1_400,
            'rank' => 1,
            'algorithm_version' => 'v6',
            'reasons' => ['genre' => ['score' => 1_400]],
            'computed_at' => now(),
        ]);

        $url = route('discover.index', ['type' => CatalogRecommendationType::Personalized->value]);
        $response = $this->actingAs($user)->get($url)->assertOk()->assertSee('Безопасная рекомендация');
        $html = $response->getContent();
        $privateMarkers = [
            '765432',
            '876543',
            'СЕКРЕТНЫЙ ИСТОЧНИК ПРОФИЛЯ',
            'СЕКРЕТНАЯ ЛИЧНАЯ КОЛЛЕКЦИЯ',
            'СЕКРЕТНЫЙ ЛИЧНЫЙ ТЕГ',
            '54321',
            'genre:424242',
            'featureDemotions',
            'sourceTitleIds',
        ];

        foreach ($privateMarkers as $marker) {
            $this->assertStringNotContainsString($marker, $url);
            $this->assertStringNotContainsString($marker, $html);
        }

        $api = $this->getJson("/api/v1/titles/{$candidate->slug}/recommendations")
            ->assertOk()
            ->getContent();

        foreach ($privateMarkers as $marker) {
            $this->assertStringNotContainsString($marker, $api);
        }
    }

    private function attachPrivateCollection(User $user, CatalogTitle $source): void
    {
        $collection = CatalogCollection::query()->forceCreate([
            'public_id' => (string) Str::uuid(),
            'owner_id' => $user->id,
            'name' => 'СЕКРЕТНАЯ ЛИЧНАЯ КОЛЛЕКЦИЯ',
            'slug' => 'sekretnaia-lichnaia-kollektsiia-privacy',
            'type' => CatalogCollectionType::User,
            'visibility' => CatalogCollectionVisibility::Private,
            'moderation_status' => CatalogCollectionModerationStatus::Approved,
            'sort_mode' => CatalogCollectionSort::Manual,
        ]);
        CatalogCollectionItem::query()->create([
            'catalog_collection_id' => $collection->id,
            'catalog_title_id' => $source->id,
            'added_by_id' => $user->id,
            'position' => 1,
        ]);
    }

    private function attachPrivateTag(User $user, CatalogTitle $source): void
    {
        $name = 'СЕКРЕТНЫЙ ЛИЧНЫЙ ТЕГ';
        $tag = UserTag::query()->forceCreate([
            'public_id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'name' => $name,
            'normalized_name' => Str::lower($name),
            'normalized_name_hash' => hash('sha256', Str::lower($name)),
        ]);
        DB::table('catalog_title_user_tag')->insert([
            'user_tag_id' => $tag->id,
            'catalog_title_id' => $source->id,
            'position' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createNegativeFeatureEvidence(User $user): void
    {
        $genre = Genre::query()->forceCreate([
            'id' => 424_242,
            'name' => 'Конфиденциальный жанр',
            'slug' => 'confidential-genre-privacy',
        ]);

        foreach ([910_001, 910_002, 910_003] as $id) {
            $title = CatalogTitle::factory()->create(['id' => $id]);
            $title->genres()->attach($genre);
            CatalogTitleUserState::query()->create([
                'user_id' => $user->id,
                'catalog_title_id' => $title->id,
                'watch_status' => CatalogWatchStatus::Dropped,
                'watch_status_updated_at' => now(),
            ]);
        }
    }
}
