<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\DTOs\CatalogRecommendationContext;
use App\DTOs\CatalogTasteOnboardingData;
use App\Enums\CatalogPersonalEvidence;
use App\Enums\CatalogRecommendationCompletionPreference;
use App\Enums\CatalogRecommendationEpisodeLengthPreference;
use App\Enums\CatalogRecommendationOnboardingTitleKind;
use App\Enums\CatalogRecommendationPlaybackPreference;
use App\Enums\CatalogRecommendationReason;
use App\Enums\CatalogRecommendationType;
use App\Models\CatalogStatus;
use App\Models\CatalogTitle;
use App\Models\Country;
use App\Models\Genre;
use App\Models\LicensedMedia;
use App\Models\Translation;
use App\Models\User;
use App\Services\Auth\AccountDataExportService;
use App\Services\Catalog\CatalogPersonalPreferenceProfileBuilder;
use App\Services\Catalog\CatalogRecommendationExclusionService;
use App\Services\Catalog\CatalogRecommendationFeatureExtractor;
use App\Services\Catalog\CatalogRecommendationPreferenceQuery;
use App\Services\Catalog\CatalogRecommendationPreferenceService;
use App\Services\Catalog\CatalogRecommendationTasteReranker;
use App\Services\Catalog\CatalogTasteOnboardingService;
use App\Services\Catalog\CatalogTitleUserDataMerger;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class CatalogTasteOnboardingRecommendationTest extends TestCase
{
    use RefreshDatabase;

    public function test_liked_titles_become_personal_sources_and_all_onboarding_titles_are_hard_excluded(): void
    {
        [$user, $liked, $excluded] = $this->savedOnboarding();

        $profile = app(CatalogPersonalPreferenceProfileBuilder::class)->forUser($user);
        $signals = collect($profile->signals)->keyBy('titleId');
        $hardExclusions = app(CatalogRecommendationExclusionService::class)->hardExclusions(
            new CatalogRecommendationContext(
                type: CatalogRecommendationType::Personalized,
                user: $user,
                locale: 'ru',
            ),
        );

        $this->assertSame($liked->pluck('id')->sort()->values()->all(), $signals->keys()->sort()->values()->all());
        $this->assertContains(CatalogPersonalEvidence::Onboarding, $signals[$liked->firstOrFail()->id]->evidence);
        $this->assertContains(CatalogRecommendationReason::BecauseOnboarding, $signals[$liked->firstOrFail()->id]->reasonCodes);
        $this->assertGreaterThanOrEqual(180, $signals[$liked->firstOrFail()->id]->confidence);
        $this->assertEqualsCanonicalizing(
            [...$liked->pluck('id')->all(), $excluded->id],
            $hardExclusions,
        );
    }

    public function test_preferred_genre_and_country_apply_a_bounded_positive_rerank(): void
    {
        [$user, , , $genre, $country] = $this->savedOnboarding();
        $matching = CatalogTitle::factory()->create();
        $other = CatalogTitle::factory()->create();
        $matching->genres()->attach($genre);
        $matching->countries()->attach($country);
        $preferences = app(CatalogRecommendationPreferenceQuery::class)->forUser($user);

        $reranked = app(CatalogRecommendationTasteReranker::class)->rerank($user, [
            ['id' => $other->id, 'score' => 100, 'source' => 'popularity', 'reason' => 'popular'],
            ['id' => $matching->id, 'score' => 100, 'source' => 'popularity', 'reason' => 'popular'],
        ], $preferences);

        $this->assertSame([$genre->id], $preferences->preferredGenreIds);
        $this->assertSame([$country->id], $preferences->preferredCountryIds);
        $this->assertSame($matching->id, $reranked[0]['id']);
        $this->assertGreaterThan(100, $reranked[0]['score']);
        $this->assertLessThanOrEqual(
            100 + (int) config('recommendations.onboarding.positive_total_cap', 140),
            $reranked[0]['score'],
        );
    }

    public function test_real_availability_status_and_duration_metadata_create_features_while_unknown_stays_neutral(): void
    {
        $matching = CatalogTitle::factory()->create();
        $ongoing = CatalogTitle::factory()->create();
        $unknown = CatalogTitle::factory()->create();
        $translation = Translation::query()->create([
            'name' => 'Тестовая озвучка',
            'slug' => 'onboarding-test-dubbing',
        ]);
        $completed = CatalogStatus::query()->create([
            'name' => 'Завершён',
            'slug' => 'completed',
        ]);
        $current = CatalogStatus::query()->create([
            'name' => 'Выходит',
            'slug' => 'ongoing',
        ]);
        $matching->translations()->attach($translation);
        $matching->statuses()->attach($completed);
        $ongoing->statuses()->attach($current);
        LicensedMedia::factory()->for($matching)->create([
            'status' => 'published',
            'has_subtitles' => true,
            'duration_seconds' => 1_200,
            'published_at' => now(),
        ]);
        LicensedMedia::factory()->for($ongoing)->create([
            'status' => 'published',
            'has_subtitles' => false,
            'duration_seconds' => 3_600,
            'published_at' => now(),
        ]);

        $features = app(CatalogRecommendationFeatureExtractor::class)
            ->forTitleIds([$matching->id, $ongoing->id, $unknown->id]);

        $this->assertContains('availability:dubbed', $features[$matching->id]);
        $this->assertContains('availability:subtitles', $features[$matching->id]);
        $this->assertContains('status:completed', $features[$matching->id]);
        $this->assertContains('duration:short', $features[$matching->id]);
        $this->assertContains('status:unfinished', $features[$ongoing->id]);
        $this->assertContains('duration:long', $features[$ongoing->id]);
        $this->assertNotContains('status:completed', $features[$unknown->id]);
        $this->assertNotContains('status:unfinished', $features[$unknown->id]);
        $this->assertNotContains('duration:short', $features[$unknown->id]);
        $this->assertNotContains('duration:long', $features[$unknown->id]);
    }

    public function test_profile_reset_removes_only_onboarding_influence_and_neutralizes_modes(): void
    {
        [$user] = $this->savedOnboarding();

        $preference = app(CatalogRecommendationPreferenceService::class)->reset($user);

        $this->assertSame([], $preference->preferredGenreIds);
        $this->assertSame([], $preference->preferredCountryIds);
        $this->assertSame([], $preference->likedTitleIds);
        $this->assertSame([], $preference->excludedTitleIds);
        $this->assertSame(CatalogRecommendationPlaybackPreference::Any, $preference->playbackPreference);
        $this->assertSame(CatalogRecommendationCompletionPreference::Any, $preference->completionPreference);
        $this->assertSame(CatalogRecommendationEpisodeLengthPreference::Any, $preference->episodeLengthPreference);
        $this->assertNull($preference->onboardingCompletedAt);
        $this->assertDatabaseCount('catalog_recommendation_onboarding_titles', 0);
        $this->assertDatabaseCount('catalog_recommendation_preferred_genres', 0);
        $this->assertDatabaseCount('catalog_recommendation_preferred_countries', 0);
    }

    public function test_title_merge_keeps_one_canonical_exclusion_and_account_export_uses_safe_labels(): void
    {
        [$user, , , $genre, $country] = $this->savedOnboarding();
        $duplicate = CatalogTitle::factory()->create(['title' => 'Дубликат для исключения']);
        $canonical = CatalogTitle::factory()->create(['title' => 'Канонический сериал']);
        DB::table('catalog_recommendation_onboarding_titles')->insert([
            [
                'user_id' => $user->id,
                'catalog_title_id' => $duplicate->id,
                'kind' => CatalogRecommendationOnboardingTitleKind::Excluded->value,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'user_id' => $user->id,
                'catalog_title_id' => $canonical->id,
                'kind' => CatalogRecommendationOnboardingTitleKind::Liked->value,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        app(CatalogTitleUserDataMerger::class)->moveTitle($duplicate, $canonical);
        $row = DB::table('catalog_recommendation_onboarding_titles')
            ->where('user_id', $user->id)
            ->where('catalog_title_id', $canonical->id)
            ->sole();
        $export = app(AccountDataExportService::class)->export($user);
        $onboarding = $export['recommendations']['onboarding'];

        $this->assertSame('excluded', $row->kind);
        $this->assertSame($genre->name, $onboarding['genres'][0]);
        $this->assertSame($country->name, $onboarding['countries'][0]);
        $this->assertArrayNotHasKey('user_id', $onboarding);
        $this->assertArrayNotHasKey('catalog_title_id', $onboarding['titles'][0]);
    }

    /**
     * @return array{
     *     User,
     *     Collection<int, CatalogTitle>,
     *     CatalogTitle,
     *     Genre,
     *     Country
     * }
     */
    private function savedOnboarding(): array
    {
        $user = User::factory()->create();
        $liked = CatalogTitle::factory()->count(5)->create();
        $excluded = CatalogTitle::factory()->create();
        $genre = Genre::query()->create(['name' => 'Onboarding жанр', 'slug' => 'recommendation-onboarding-genre']);
        $country = Country::query()->create(['name' => 'Onboarding страна', 'slug' => 'recommendation-onboarding-country']);
        app(CatalogTasteOnboardingService::class)->save($user, new CatalogTasteOnboardingData(
            likedTitleIds: $liked->pluck('id')->all(),
            excludedTitleIds: [$excluded->id],
            genreIds: [$genre->id],
            countryIds: [$country->id],
            locale: 'ru',
            playbackPreference: CatalogRecommendationPlaybackPreference::Dubbed,
            completionPreference: CatalogRecommendationCompletionPreference::Completed,
            episodeLengthPreference: CatalogRecommendationEpisodeLengthPreference::Short,
        ));

        return [$user, $liked, $excluded, $genre, $country];
    }
}
