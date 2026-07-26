<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\CatalogRecommendationFeedback;
use App\Enums\CatalogRecommendationFeedbackReason;
use App\Models\CatalogRecommendationFeedbackDetail;
use App\Models\CatalogTitle;
use App\Models\CatalogTitleUserState;
use App\Models\User;
use App\Services\Catalog\CatalogTitleUserDataMerger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class CatalogTitleUserDataMergerTest extends TestCase
{
    use RefreshDatabase;

    public function test_duplicate_title_merge_keeps_deterministic_feedback_precedence(): void
    {
        $user = User::factory()->create();
        $canonical = CatalogTitle::factory()->create();
        $duplicate = CatalogTitle::factory()->create();
        $timestamp = now()->startOfSecond();
        CatalogTitleUserState::query()->create([
            'user_id' => $user->id,
            'catalog_title_id' => $canonical->id,
            'recommendation_feedback' => CatalogRecommendationFeedback::MoreLikeThis,
            'recommendation_feedback_version' => 2,
            'recommendation_feedback_updated_at' => $timestamp,
        ]);
        CatalogTitleUserState::query()->create([
            'user_id' => $user->id,
            'catalog_title_id' => $duplicate->id,
            'recommendation_feedback' => CatalogRecommendationFeedback::Blacklisted,
            'recommendation_feedback_version' => 3,
            'recommendation_feedback_updated_at' => $timestamp,
        ]);

        app(CatalogTitleUserDataMerger::class)->moveTitle($duplicate, $canonical);

        $state = CatalogTitleUserState::query()
            ->whereBelongsTo($user)
            ->whereBelongsTo($canonical)
            ->sole();

        $this->assertSame(CatalogRecommendationFeedback::Blacklisted, $state->recommendation_feedback);
        $this->assertSame(3, $state->recommendationFeedbackVersion());
        $this->assertDatabaseMissing('catalog_title_user_states', [
            'user_id' => $user->id,
            'catalog_title_id' => $duplicate->id,
        ]);
    }

    public function test_duplicate_title_merge_moves_the_detail_matching_the_winning_feedback(): void
    {
        $user = User::factory()->create();
        $canonical = CatalogTitle::factory()->create();
        $duplicate = CatalogTitle::factory()->create();
        CatalogTitleUserState::query()->create([
            'user_id' => $user->id,
            'catalog_title_id' => $canonical->id,
            'recommendation_feedback' => CatalogRecommendationFeedback::MoreLikeThis,
            'recommendation_feedback_version' => 1,
            'recommendation_feedback_updated_at' => now()->subDay(),
        ]);
        CatalogTitleUserState::query()->create([
            'user_id' => $user->id,
            'catalog_title_id' => $duplicate->id,
            'recommendation_feedback' => CatalogRecommendationFeedback::NotInterested,
            'recommendation_feedback_version' => 2,
            'recommendation_feedback_updated_at' => now(),
        ]);
        CatalogRecommendationFeedbackDetail::query()->create([
            'user_id' => $user->id,
            'catalog_title_id' => $duplicate->id,
            'reason' => CatalogRecommendationFeedbackReason::TooManyEpisodes,
        ]);

        app(CatalogTitleUserDataMerger::class)->moveTitle($duplicate, $canonical);

        $detail = CatalogRecommendationFeedbackDetail::query()
            ->whereBelongsTo($user)
            ->sole();

        $this->assertSame($canonical->id, $detail->catalog_title_id);
        $this->assertSame(CatalogRecommendationFeedbackReason::TooManyEpisodes, $detail->reason);
        $this->assertDatabaseMissing('catalog_recommendation_feedback_details', [
            'catalog_title_id' => $duplicate->id,
        ]);
    }

    public function test_duplicate_title_merge_removes_detail_when_positive_feedback_wins(): void
    {
        $user = User::factory()->create();
        $canonical = CatalogTitle::factory()->create();
        $duplicate = CatalogTitle::factory()->create();
        CatalogTitleUserState::query()->create([
            'user_id' => $user->id,
            'catalog_title_id' => $canonical->id,
            'recommendation_feedback' => CatalogRecommendationFeedback::MoreLikeThis,
            'recommendation_feedback_version' => 4,
            'recommendation_feedback_updated_at' => now(),
        ]);
        CatalogTitleUserState::query()->create([
            'user_id' => $user->id,
            'catalog_title_id' => $duplicate->id,
            'recommendation_feedback' => CatalogRecommendationFeedback::MoreLikeThis,
            'recommendation_feedback_version' => 2,
            'recommendation_feedback_updated_at' => now()->subDay(),
        ]);
        CatalogRecommendationFeedbackDetail::query()->create([
            'user_id' => $user->id,
            'catalog_title_id' => $duplicate->id,
            'reason' => CatalogRecommendationFeedbackReason::NotSimilar,
        ]);

        app(CatalogTitleUserDataMerger::class)->moveTitle($duplicate, $canonical);

        $this->assertDatabaseMissing('catalog_recommendation_feedback_details', [
            'user_id' => $user->id,
        ]);
    }
}
