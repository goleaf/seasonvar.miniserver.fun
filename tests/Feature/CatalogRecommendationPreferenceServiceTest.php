<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\CatalogRecommendationDiversityPreference;
use App\Enums\CatalogRecommendationFeedbackReason;
use App\Enums\CatalogRecommendationFreshnessPreference;
use App\Models\CatalogRecommendationFeedbackDetail;
use App\Models\CatalogRecommendationHiddenGenre;
use App\Models\CatalogRecommendationPreference;
use App\Models\CatalogTitle;
use App\Models\Genre;
use App\Models\User;
use App\Services\Catalog\CatalogRecommendationFeedbackService;
use App\Services\Catalog\CatalogRecommendationPreferenceQuery;
use App\Services\Catalog\CatalogRecommendationPreferenceService;
use App\Services\Catalog\CatalogRecommendationRepeatSuppressor;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class CatalogRecommendationPreferenceServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow('2026-07-26 12:00:00 UTC');
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_missing_row_resolves_to_balanced_defaults_without_writing(): void
    {
        $user = User::factory()->create();

        $data = app(CatalogRecommendationPreferenceQuery::class)->forUser($user);

        $this->assertSame(CatalogRecommendationDiversityPreference::Balanced, $data->diversity);
        $this->assertSame(CatalogRecommendationFreshnessPreference::Balanced, $data->freshness);
        $this->assertNull($data->profileResetAt);
        $this->assertSame([], $data->hiddenGenreIds);
        $this->assertSame(0, CatalogRecommendationPreference::query()->count());
    }

    public function test_user_can_update_both_bounded_recommendation_preferences(): void
    {
        $user = User::factory()->create();

        $data = app(CatalogRecommendationPreferenceService::class)->update(
            $user,
            CatalogRecommendationDiversityPreference::Varied,
            CatalogRecommendationFreshnessPreference::Newer,
        );

        $this->assertSame(CatalogRecommendationDiversityPreference::Varied, $data->diversity);
        $this->assertSame(CatalogRecommendationFreshnessPreference::Newer, $data->freshness);
        $this->assertDatabaseHas('catalog_recommendation_preferences', [
            'user_id' => $user->id,
            'diversity' => 'varied',
            'freshness' => 'newer',
            'version' => 2,
        ]);
    }

    public function test_hide_genre_uses_server_configured_expiry_and_upserts_one_owner_row(): void
    {
        config(['recommendations.feedback.hidden_genre_days' => 30]);
        $user = User::factory()->create();
        $genre = Genre::query()->create([
            'name' => 'Временно скрытый жанр',
            'slug' => 'temporarily-hidden-feedback-genre',
        ]);
        $service = app(CatalogRecommendationPreferenceService::class);

        $service->hideGenre($user, $genre);
        CarbonImmutable::setTestNow('2026-07-27 12:00:00 UTC');
        $service->hideGenre($user, $genre);

        $hidden = CatalogRecommendationHiddenGenre::query()->sole();
        $this->assertSame($user->id, $hidden->user_id);
        $this->assertSame($genre->id, $hidden->genre_id);
        $this->assertTrue($hidden->hidden_until->equalTo(CarbonImmutable::parse('2026-08-26 12:00:00 UTC')));
        $this->assertSame([$genre->id], app(CatalogRecommendationPreferenceQuery::class)->forUser($user)->hiddenGenreIds);

        $service->restoreGenre($user, $genre);
        $this->assertSame(0, CatalogRecommendationHiddenGenre::query()->count());
    }

    public function test_reset_clears_learned_detail_and_temporary_hides_but_preserves_exact_title_state(): void
    {
        $user = User::factory()->create();
        $title = CatalogTitle::factory()->create();
        $genre = Genre::query()->create([
            'name' => 'Жанр до сброса',
            'slug' => 'genre-before-profile-reset',
        ]);
        app(CatalogRecommendationFeedbackService::class)->save(
            $user,
            $title,
            CatalogRecommendationFeedbackReason::NotSimilar,
        );
        $preferences = app(CatalogRecommendationPreferenceService::class);
        $preferences->update(
            $user,
            CatalogRecommendationDiversityPreference::Focused,
            CatalogRecommendationFreshnessPreference::Proven,
        );
        $preferences->hideGenre($user, $genre);
        app(CatalogRecommendationRepeatSuppressor::class)->remember($user, [$title->id]);

        $data = $preferences->reset($user);

        $this->assertSame(CatalogRecommendationDiversityPreference::Balanced, $data->diversity);
        $this->assertSame(CatalogRecommendationFreshnessPreference::Balanced, $data->freshness);
        $this->assertTrue($data->profileResetAt?->equalTo(CarbonImmutable::now()) ?? false);
        $this->assertSame([], $data->hiddenGenreIds);
        $this->assertSame(0, CatalogRecommendationFeedbackDetail::query()->count());
        $this->assertSame(0, CatalogRecommendationHiddenGenre::query()->count());
        $this->assertDatabaseHas('catalog_title_user_states', [
            'user_id' => $user->id,
            'catalog_title_id' => $title->id,
            'recommendation_feedback' => 'not_interested',
        ]);
        $this->assertSame([], app(CatalogRecommendationRepeatSuppressor::class)->recentIds($user));
    }
}
