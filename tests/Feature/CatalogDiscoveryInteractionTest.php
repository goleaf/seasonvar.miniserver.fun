<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\DTOs\CatalogRecommendationContext;
use App\Enums\CatalogPopularityPeriod;
use App\Enums\CatalogRecommendationDiversityPreference;
use App\Enums\CatalogRecommendationFeedback;
use App\Enums\CatalogRecommendationFeedbackReason;
use App\Enums\CatalogRecommendationFreshnessPreference;
use App\Enums\CatalogRecommendationType;
use App\Livewire\CatalogDiscoveryPage;
use App\Models\CatalogRecommendationFeedbackDetail;
use App\Models\CatalogRecommendationHiddenGenre;
use App\Models\CatalogRecommendationPreference;
use App\Models\CatalogTitle;
use App\Models\Episode;
use App\Models\EpisodeViewProgress;
use App\Models\Genre;
use App\Models\LicensedMedia;
use App\Models\Season;
use App\Models\User;
use App\Services\Catalog\CatalogRecommendationRepeatSuppressor;
use App\Services\Catalog\CatalogRecommendationService;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class CatalogDiscoveryInteractionTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('discoveryTypes')]
    public function test_every_public_discovery_type_keeps_the_canonical_route(string $type): void
    {
        $this->get(route('discover.index', ['type' => $type]))
            ->assertOk();
    }

    public function test_guest_personalized_cold_start_fills_the_page_from_multiple_public_sources(): void
    {
        $weekly = $this->playableTitle('Недельный интерес');
        $this->recordProgress($weekly, now());

        foreach (range(1, 30) as $index) {
            $this->playableTitle("Популярный тайтл {$index}");
        }

        $result = app(CatalogRecommendationService::class)->discover(
            $this->guestPersonalizedContext(),
        );

        $this->assertCount(24, $result->items);
        $this->assertSame(
            $result->items->count(),
            $result->items->pluck('title.id')->unique()->count(),
        );
        $this->assertTrue($result->items->contains(
            fn ($item): bool => $item->title->is($weekly),
        ));
        $this->assertGreaterThan(
            1,
            $result->items->pluck('source.value')->unique()->count(),
        );
    }

    public function test_monthly_trending_fallback_is_distinct_and_does_not_duplicate_the_weekly_title(): void
    {
        $weekly = $this->playableTitle('Недельный тайтл');
        $monthly = $this->playableTitle('Месячный тайтл');
        $this->recordProgress($weekly, now());
        $this->recordProgress($monthly, now()->subDays(10));

        foreach (range(1, 30) as $index) {
            $this->playableTitle("Резерв популярности {$index}");
        }

        $result = app(CatalogRecommendationService::class)->discover(
            $this->guestPersonalizedContext(),
        );
        $weeklyItems = $result->items->filter(
            fn ($item): bool => $item->title->is($weekly),
        );
        $monthlyItem = $result->items->first(
            fn ($item): bool => $item->title->is($monthly),
        );

        $this->assertCount(1, $weeklyItems);
        $this->assertNotNull($monthlyItem);
        $this->assertSame('trending_period', $monthlyItem->explanations[0]->reason->value);
        $this->assertSame('30 дней', $monthlyItem->explanations[0]->parameters['period'] ?? null);
    }

    public function test_refresh_resolves_random_recommendations_once_and_records_the_new_result(): void
    {
        foreach (range(1, 48) as $index) {
            $this->playableTitle("Случайный тайтл {$index}");
        }

        $boundsQueries = 0;
        DB::listen(function (QueryExecuted $query) use (&$boundsQueries): void {
            $sql = str($query->sql)->replace(['`', '"'], '')->lower()->squish()->toString();

            if (str_contains($sql, 'min(catalog_titles.id) as minimum_id')
                && str_contains($sql, 'max(catalog_titles.id) as maximum_id')) {
                $boundsQueries++;
            }
        });

        $component = Livewire::test(CatalogDiscoveryPage::class, ['type' => 'random'])
            ->assertHasNoErrors();
        $initialBoundsQueries = $boundsQueries;
        $boundsQueries = 0;
        $refreshedIds = [];

        $component
            ->call('refreshRecommendations')
            ->assertHasNoErrors()
            ->assertViewHas('result', function ($result) use (&$refreshedIds): bool {
                $refreshedIds = $result->items->pluck('title.id')->all();

                return $result->page === 1 && ! $result->hasMore;
            });

        $this->assertSame(1, $initialBoundsQueries);
        $this->assertSame($initialBoundsQueries, $boundsQueries);
        $this->assertNotEmpty($refreshedIds);
        $this->assertEmpty(array_diff(
            $refreshedIds,
            app(CatalogRecommendationRepeatSuppressor::class)->recentIds(null),
        ));
    }

    public function test_random_context_never_exposes_a_second_page(): void
    {
        foreach (range(1, 30) as $index) {
            $this->playableTitle("Одностраничный random {$index}");
        }

        $result = app(CatalogRecommendationService::class)->discover(
            new CatalogRecommendationContext(
                type: CatalogRecommendationType::Random,
                user: null,
                locale: 'ru',
                page: 2,
                perPage: 24,
                seed: 'fixed-random-page-contract',
            ),
        );

        $this->assertSame(1, $result->page);
        $this->assertCount(24, $result->items);
        $this->assertFalse($result->hasMore);
    }

    public function test_verified_user_can_save_and_undo_more_like_this_feedback(): void
    {
        $user = User::factory()->create();
        $title = $this->playableTitle('Точный положительный сигнал');

        Livewire::actingAs($user)
            ->test(CatalogDiscoveryPage::class, ['type' => 'personalized'])
            ->call('setFeedback', $title->id, 'more_like_this')
            ->assertHasNoErrors()
            ->assertSet('lastFeedbackTitleId', $title->id)
            ->assertSet('notice', 'Будем чаще учитывать похожие сериалы в персональных рекомендациях.')
            ->call('undoFeedback')
            ->assertHasNoErrors()
            ->assertSet('lastFeedbackTitleId', null);

        $this->assertDatabaseHas('catalog_title_user_states', [
            'user_id' => $user->id,
            'catalog_title_id' => $title->id,
            'recommendation_feedback' => null,
        ]);
        $this->assertContains('more_like_this', CatalogRecommendationFeedback::values());
    }

    public function test_verified_user_must_choose_a_reason_and_subject_is_saved_server_side(): void
    {
        $user = User::factory()->create();
        $title = $this->playableTitle('Осмысленный отрицательный сигнал');
        $genre = Genre::query()->create([
            'name' => 'Жанр причины',
            'slug' => 'reason-subject-genre',
        ]);
        $title->genres()->attach($genre);
        $component = Livewire::actingAs($user)
            ->test(CatalogDiscoveryPage::class, ['type' => 'personalized'])
            ->call('setFeedback', $title->id, 'not_interested')
            ->assertHasErrors(['recommendationFeedback'])
            ->call(
                'setFeedbackReason',
                $title->id,
                CatalogRecommendationFeedbackReason::DislikeGenre->value,
                $genre->id,
            )
            ->assertHasNoErrors()
            ->assertSet('lastFeedbackTitleId', $title->id);

        $detail = CatalogRecommendationFeedbackDetail::query()->sole();
        $this->assertSame(CatalogRecommendationFeedbackReason::DislikeGenre, $detail->reason);
        $this->assertSame($genre->id, $detail->genre_id);
        $component->call('undoFeedback')->assertHasNoErrors();
        $this->assertSame(0, CatalogRecommendationFeedbackDetail::query()->count());
    }

    public function test_personalized_controls_update_preferences_hide_genre_and_reset_profile(): void
    {
        $user = User::factory()->create();
        $genre = Genre::query()->create([
            'name' => 'Временно скрываемая комедия',
            'slug' => 'livewire-temporary-hidden-genre',
        ]);

        Livewire::actingAs($user)
            ->test(CatalogDiscoveryPage::class, ['type' => 'personalized'])
            ->call(
                'updateRecommendationPreferences',
                CatalogRecommendationDiversityPreference::Varied->value,
                CatalogRecommendationFreshnessPreference::Newer->value,
            )
            ->assertHasNoErrors()
            ->assertSet('diversityPreference', 'varied')
            ->assertSet('freshnessPreference', 'newer')
            ->call('hideRecommendationGenre', $genre->id)
            ->assertHasNoErrors();

        $this->assertDatabaseHas('catalog_recommendation_preferences', [
            'user_id' => $user->id,
            'diversity' => 'varied',
            'freshness' => 'newer',
        ]);
        $this->assertDatabaseHas('catalog_recommendation_hidden_genres', [
            'user_id' => $user->id,
            'genre_id' => $genre->id,
        ]);

        Livewire::actingAs($user)
            ->test(CatalogDiscoveryPage::class, ['type' => 'personalized'])
            ->call('resetRecommendationProfile')
            ->assertHasNoErrors()
            ->assertSet('diversityPreference', 'balanced')
            ->assertSet('freshnessPreference', 'balanced');

        $this->assertNotNull(CatalogRecommendationPreference::query()->find($user->id)?->profile_reset_at);
        $this->assertSame(0, CatalogRecommendationHiddenGenre::query()->count());
    }

    private function guestPersonalizedContext(): CatalogRecommendationContext
    {
        return new CatalogRecommendationContext(
            type: CatalogRecommendationType::Personalized,
            user: null,
            locale: 'ru',
            period: CatalogPopularityPeriod::Week,
            ratingSource: 'kinopoisk',
            perPage: 24,
            seed: 'guest-cold-start-contract',
        );
    }

    private function playableTitle(string $title): CatalogTitle
    {
        $catalogTitle = CatalogTitle::factory()->create(['title' => $title]);
        LicensedMedia::factory()->for($catalogTitle)->create([
            'status' => 'published',
            'published_at' => now(),
        ]);

        return $catalogTitle;
    }

    private function recordProgress(CatalogTitle $title, \DateTimeInterface $watchedAt): void
    {
        $season = Season::factory()->for($title)->create();
        $episode = Episode::factory()->for($season)->create();

        EpisodeViewProgress::query()->create([
            'user_id' => User::factory()->create()->id,
            'catalog_title_id' => $title->id,
            'episode_id' => $episode->id,
            'position_seconds' => 600,
            'duration_seconds' => 1_200,
            'progress_percent' => 50,
            'last_watched_at' => $watchedAt,
        ]);
    }

    /** @return array<string, array{string}> */
    public static function discoveryTypes(): array
    {
        return [
            'personalized' => ['personalized'],
            'trending' => ['trending'],
            'popular' => ['popular'],
            'top_rated' => ['top_rated'],
            'recently_added' => ['recently_added'],
            'recently_updated' => ['recently_updated'],
            'upcoming' => ['upcoming'],
            'editorial' => ['editorial'],
            'random' => ['random'],
        ];
    }
}
