<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\CatalogRecommendationDiversityPreference;
use App\Enums\CatalogRecommendationFeedback;
use App\Enums\CatalogRecommendationFeedbackReason;
use App\Enums\CatalogRecommendationFreshnessPreference;
use App\Models\CatalogRecommendationFeedbackDetail;
use App\Models\CatalogRecommendationHiddenGenre;
use App\Models\CatalogRecommendationPreference;
use App\Models\CatalogTitle;
use App\Models\CatalogTitleUserState;
use App\Models\Genre;
use App\Models\User;
use App\Services\Auth\AccountDataExportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class CatalogRecommendationFeedbackExternalContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_account_export_preserves_the_stable_positive_feedback_value(): void
    {
        $user = User::factory()->create();
        $title = CatalogTitle::factory()->create(['slug' => 'export-positive-feedback']);
        CatalogTitleUserState::query()->create([
            'user_id' => $user->id,
            'catalog_title_id' => $title->id,
            'recommendation_feedback' => CatalogRecommendationFeedback::MoreLikeThis,
            'recommendation_feedback_updated_at' => now(),
        ]);

        $library = app(AccountDataExportService::class)->export($user)['library'];

        $this->assertCount(1, $library);
        $this->assertSame('export-positive-feedback', $library[0]['title_slug']);
        $this->assertSame('more_like_this', $library[0]['recommendation_feedback']);
        $this->assertNotNull($library[0]['recommendation_feedback_updated_at']);
    }

    public function test_account_export_includes_readable_private_recommendation_preferences_without_internal_ids(): void
    {
        $user = User::factory()->create();
        $title = CatalogTitle::factory()->create([
            'slug' => 'export-smart-feedback',
            'title' => 'Экспортируемая рекомендация',
        ]);
        $genre = Genre::query()->create([
            'name' => 'Научная фантастика',
            'slug' => 'export-science-fiction',
        ]);
        CatalogTitleUserState::query()->create([
            'user_id' => $user->id,
            'catalog_title_id' => $title->id,
            'recommendation_feedback' => CatalogRecommendationFeedback::NotInterested,
            'recommendation_feedback_updated_at' => now(),
        ]);
        CatalogRecommendationFeedbackDetail::query()->create([
            'user_id' => $user->id,
            'catalog_title_id' => $title->id,
            'reason' => CatalogRecommendationFeedbackReason::DislikeGenre,
            'genre_id' => $genre->id,
        ]);
        CatalogRecommendationPreference::query()->create([
            'user_id' => $user->id,
            'diversity' => CatalogRecommendationDiversityPreference::Varied,
            'freshness' => CatalogRecommendationFreshnessPreference::Newer,
            'profile_reset_at' => now()->subDay(),
            'version' => 4,
        ]);
        CatalogRecommendationHiddenGenre::query()->create([
            'user_id' => $user->id,
            'genre_id' => $genre->id,
            'hidden_until' => now()->addDays(30),
        ]);

        $recommendations = app(AccountDataExportService::class)->export($user)['recommendations'];

        $this->assertSame('varied', $recommendations['preferences']['diversity']);
        $this->assertSame('newer', $recommendations['preferences']['freshness']);
        $this->assertNotNull($recommendations['preferences']['profile_reset_at']);
        $this->assertSame([
            'title_slug' => 'export-smart-feedback',
            'title' => 'Экспортируемая рекомендация',
            'reason' => 'dislike_genre',
            'subject_type' => 'genre',
            'subject' => 'Научная фантастика',
            'created_at' => $recommendations['feedback'][0]['created_at'],
            'updated_at' => $recommendations['feedback'][0]['updated_at'],
        ], $recommendations['feedback'][0]);
        $this->assertSame('Научная фантастика', $recommendations['temporarily_hidden_genres'][0]['genre']);
        $this->assertArrayNotHasKey('user_id', $recommendations['feedback'][0]);
        $this->assertArrayNotHasKey('catalog_title_id', $recommendations['feedback'][0]);
        $this->assertArrayNotHasKey('genre_id', $recommendations['feedback'][0]);
    }
}
