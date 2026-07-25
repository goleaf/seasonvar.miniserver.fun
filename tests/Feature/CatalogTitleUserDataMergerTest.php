<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\CatalogRecommendationFeedback;
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
}
