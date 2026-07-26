<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\DTOs\CatalogRecommendationContext;
use App\Enums\CatalogRecommendationFeedbackReason;
use App\Enums\CatalogRecommendationType;
use App\Models\Actor;
use App\Models\CatalogRecommendationHiddenGenre;
use App\Models\CatalogStatus;
use App\Models\CatalogTitle;
use App\Models\CatalogTitleRating;
use App\Models\Country;
use App\Models\Episode;
use App\Models\Genre;
use App\Models\Season;
use App\Models\User;
use App\Services\Catalog\CatalogPersonalNegativePreferenceBuilder;
use App\Services\Catalog\CatalogRecommendationFeatureExtractor;
use App\Services\Catalog\CatalogRecommendationFeedbackService;
use App\Services\Catalog\CatalogRecommendationVisibilityService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class CatalogRecommendationReasonSignalTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_one_explicit_disliked_genre_creates_a_bounded_feature_demotion(): void
    {
        $user = User::factory()->create();
        $title = CatalogTitle::factory()->create();
        $genre = Genre::query()->create([
            'name' => 'Нежелательный жанр',
            'slug' => 'explicitly-disliked-genre',
        ]);
        $title->genres()->attach($genre);
        app(CatalogRecommendationFeedbackService::class)->save(
            $user,
            $title,
            CatalogRecommendationFeedbackReason::DislikeGenre,
            $genre->id,
        );

        $demotions = app(CatalogPersonalNegativePreferenceBuilder::class)->forUser($user);

        $this->assertArrayHasKey("genre:{$genre->id}", $demotions);
        $this->assertSame(
            (int) config('recommendations.feedback.explicit_feature_demotion', 90),
            $demotions["genre:{$genre->id}"],
        );
    }

    public function test_country_and_actor_reasons_target_only_the_verified_subject_feature(): void
    {
        $title = CatalogTitle::factory()->create();
        $country = Country::query()->create([
            'name' => 'Нежелательная страна',
            'slug' => 'explicitly-disliked-country',
        ]);
        $actor = Actor::query()->create([
            'name' => 'Нежелательный актёр',
            'slug' => 'explicitly-disliked-actor',
        ]);
        $title->countries()->attach($country);
        $title->actors()->attach($actor);

        foreach ([
            [CatalogRecommendationFeedbackReason::DislikeCountry, $country->id, "country:{$country->id}"],
            [CatalogRecommendationFeedbackReason::DislikeActor, $actor->id, "actor:{$actor->id}"],
        ] as [$reason, $subjectId, $expectedFeature]) {
            $user = User::factory()->create();
            app(CatalogRecommendationFeedbackService::class)->save(
                $user,
                $title,
                $reason,
                $subjectId,
            );

            $this->assertArrayHasKey(
                $expectedFeature,
                app(CatalogPersonalNegativePreferenceBuilder::class)->forUser($user),
            );
        }
    }

    public function test_feature_extractor_builds_every_semantic_reason_boundary_in_bounded_queries(): void
    {
        config([
            'recommendations.feedback.classic_age_years' => 15,
            'recommendations.feedback.long_title_episode_count' => 2,
            'recommendations.feedback.low_rating_threshold' => 6,
        ]);
        $title = CatalogTitle::factory()->create([
            'title' => 'Любовная история',
            'description' => 'Герои влюбляются.',
            'year' => now()->year - 20,
        ]);
        $genre = Genre::query()->create(['name' => 'Драма сигналов', 'slug' => 'signal-drama']);
        $country = Country::query()->create(['name' => 'Страна сигналов', 'slug' => 'signal-country']);
        $actor = Actor::query()->create(['name' => 'Актёр сигналов', 'slug' => 'signal-actor']);
        $status = CatalogStatus::query()->create(['name' => 'Продолжается', 'slug' => 'ongoing-signal']);
        $title->genres()->attach($genre);
        $title->countries()->attach($country);
        $title->actors()->attach($actor);
        $title->statuses()->attach($status);
        $season = Season::factory()->for($title)->create();
        Episode::factory()->for($season)->create(['number' => 1]);
        Episode::factory()->for($season)->create(['number' => 2]);
        CatalogTitleRating::query()->create([
            'catalog_title_id' => $title->id,
            'provider' => 'kinopoisk',
            'rating' => 5.5,
            'votes' => 1_000,
        ]);

        $features = app(CatalogRecommendationFeatureExtractor::class)->forTitleIds([$title->id])[$title->id];

        $this->assertContains("genre:{$genre->id}", $features);
        $this->assertContains("country:{$country->id}", $features);
        $this->assertContains("actor:{$actor->id}", $features);
        $this->assertContains('theme:romance', $features);
        $this->assertContains('length:long', $features);
        $this->assertContains('status:unfinished', $features);
        $this->assertContains('era:classic', $features);
        $this->assertContains('rating:low', $features);
    }

    public function test_semantic_reasons_create_their_specific_demotion_without_three_title_threshold(): void
    {
        config(['recommendations.feedback.long_title_episode_count' => 1]);
        $title = CatalogTitle::factory()->create([
            'title' => 'Романтическая классика',
            'description' => 'История любви.',
            'year' => now()->year - 20,
        ]);
        $season = Season::factory()->for($title)->create();
        Episode::factory()->for($season)->create(['number' => 1]);
        $status = CatalogStatus::query()->create(['name' => 'Выходит', 'slug' => 'ongoing-reason']);
        $title->statuses()->attach($status);
        CatalogTitleRating::query()->create([
            'catalog_title_id' => $title->id,
            'provider' => 'kinopoisk',
            'rating' => 4.5,
            'votes' => 500,
        ]);
        $expectations = [
            CatalogRecommendationFeedbackReason::TooManyEpisodes->value => 'length:long',
            CatalogRecommendationFeedbackReason::Unfinished->value => 'status:unfinished',
            CatalogRecommendationFeedbackReason::TooOld->value => 'era:classic',
            CatalogRecommendationFeedbackReason::LowRating->value => 'rating:low',
            CatalogRecommendationFeedbackReason::WrongMood->value => 'theme:romance',
        ];

        foreach ($expectations as $reasonValue => $feature) {
            $user = User::factory()->create();
            app(CatalogRecommendationFeedbackService::class)->save(
                $user,
                $title,
                CatalogRecommendationFeedbackReason::from($reasonValue),
            );

            $this->assertArrayHasKey(
                $feature,
                app(CatalogPersonalNegativePreferenceBuilder::class)->forUser($user),
                "Причина {$reasonValue} не создала сигнал {$feature}.",
            );
        }
    }

    public function test_exact_only_reasons_do_not_generalize_taste_from_one_title(): void
    {
        $title = CatalogTitle::factory()->create(['year' => now()->year - 20]);

        foreach ([
            CatalogRecommendationFeedbackReason::WatchedElsewhere,
            CatalogRecommendationFeedbackReason::NotThisTitle,
        ] as $reason) {
            $user = User::factory()->create();
            app(CatalogRecommendationFeedbackService::class)->save($user, $title, $reason);

            $this->assertSame(
                [],
                app(CatalogPersonalNegativePreferenceBuilder::class)->forUser($user),
            );
        }
    }

    public function test_not_similar_generalizes_from_the_titles_extracted_features(): void
    {
        $user = User::factory()->create();
        $title = CatalogTitle::factory()->create([
            'title' => 'Романтическая классика сходства',
            'description' => 'История любви.',
            'year' => now()->year - 20,
        ]);
        $genre = Genre::query()->create([
            'name' => 'Жанр непохожих',
            'slug' => 'not-similar-genre',
        ]);
        $country = Country::query()->create([
            'name' => 'Страна непохожих',
            'slug' => 'not-similar-country',
        ]);
        $actor = Actor::query()->create([
            'name' => 'Актёр непохожих',
            'slug' => 'not-similar-actor',
        ]);
        $title->genres()->attach($genre);
        $title->countries()->attach($country);
        $title->actors()->attach($actor);

        app(CatalogRecommendationFeedbackService::class)->save(
            $user,
            $title,
            CatalogRecommendationFeedbackReason::NotSimilar,
        );

        $demotions = app(CatalogPersonalNegativePreferenceBuilder::class)->forUser($user);

        $this->assertArrayHasKey("genre:{$genre->id}", $demotions);
        $this->assertArrayHasKey('theme:romance', $demotions);
        $this->assertArrayNotHasKey("country:{$country->id}", $demotions);
        $this->assertArrayNotHasKey("actor:{$actor->id}", $demotions);
        $this->assertArrayNotHasKey('era:classic', $demotions);
    }

    public function test_active_hidden_genre_is_excluded_by_visibility_and_expiry_restores_it(): void
    {
        CarbonImmutable::setTestNow('2026-07-26 12:00:00 UTC');
        $user = User::factory()->create();
        $genre = Genre::query()->create([
            'name' => 'Скрытый жанр выдачи',
            'slug' => 'hidden-visibility-genre',
        ]);
        $hidden = CatalogTitle::factory()->create();
        $visible = CatalogTitle::factory()->create();
        $hidden->genres()->attach($genre);
        CatalogRecommendationHiddenGenre::query()->create([
            'user_id' => $user->id,
            'genre_id' => $genre->id,
            'hidden_until' => CarbonImmutable::now()->addDay(),
        ]);
        $context = new CatalogRecommendationContext(
            type: CatalogRecommendationType::Personalized,
            user: $user,
            locale: 'ru',
        );
        $visibility = app(CatalogRecommendationVisibilityService::class);

        $activeIds = $visibility->eligible($context, watchable: false)
            ->whereKey([$hidden->id, $visible->id])
            ->pluck('catalog_titles.id')
            ->all();
        $this->assertNotContains($hidden->id, $activeIds);
        $this->assertContains($visible->id, $activeIds);

        CarbonImmutable::setTestNow('2026-07-28 12:00:00 UTC');
        $expiredIds = $visibility->eligible($context, watchable: false)
            ->whereKey([$hidden->id, $visible->id])
            ->pluck('catalog_titles.id')
            ->all();
        $this->assertContains($hidden->id, $expiredIds);
    }
}
