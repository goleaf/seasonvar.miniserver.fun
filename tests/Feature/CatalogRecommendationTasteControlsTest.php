<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\DTOs\CatalogRecommendationPreferenceData;
use App\Enums\CatalogRecommendationDiversityPreference;
use App\Enums\CatalogRecommendationFeedbackReason;
use App\Enums\CatalogRecommendationFreshnessPreference;
use App\Models\CatalogTitle;
use App\Models\Genre;
use App\Models\User;
use App\Services\Catalog\CatalogRecommendationDiversityService;
use App\Services\Catalog\CatalogRecommendationFeedbackService;
use App\Services\Catalog\CatalogRecommendationFreshnessReranker;
use App\Services\Catalog\CatalogRecommendationPreferenceQuery;
use App\Services\Catalog\CatalogRecommendationTasteReranker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class CatalogRecommendationTasteControlsTest extends TestCase
{
    use RefreshDatabase;

    public function test_varied_mode_limits_dominant_genre_earlier_than_focused_mode(): void
    {
        config(['recommendations.diversity.primary_genre_limit' => 2]);
        $dominant = Genre::query()->create([
            'name' => 'Доминирующий жанр',
            'slug' => 'dominant-diversity-genre',
        ]);
        $other = Genre::query()->create([
            'name' => 'Другой жанр',
            'slug' => 'other-diversity-genre',
        ]);
        $titles = CatalogTitle::factory()->count(4)->create();
        $titles->take(3)->each(function (CatalogTitle $title) use ($dominant): void {
            $title->genres()->attach($dominant);
        });
        $titles->last()?->genres()->attach($other);
        $candidates = $titles->values()->map(fn (CatalogTitle $title, int $index): array => [
            'id' => $title->id,
            'score' => 100 - $index,
            'source' => 'popularity',
            'reason' => 'popular',
        ])->all();
        $service = app(CatalogRecommendationDiversityService::class);

        $focused = $service->diversify($candidates, 4, CatalogRecommendationDiversityPreference::Focused);
        $varied = $service->diversify($candidates, 4, CatalogRecommendationDiversityPreference::Varied);

        $this->assertSame($titles->pluck('id')->all(), array_column($focused, 'id'));
        $this->assertSame($titles->last()?->id, $varied[1]['id']);
    }

    public function test_newer_and_proven_modes_apply_small_deterministic_opposite_adjustments(): void
    {
        $new = CatalogTitle::factory()->create(['year' => now()->year]);
        $proven = CatalogTitle::factory()->create(['year' => now()->year - 10]);
        $candidates = [
            ['id' => $proven->id, 'score' => 100, 'source' => 'popularity', 'reason' => 'popular'],
            ['id' => $new->id, 'score' => 100, 'source' => 'popularity', 'reason' => 'popular'],
        ];
        $reranker = app(CatalogRecommendationFreshnessReranker::class);

        $balanced = $reranker->rerank($candidates, CatalogRecommendationFreshnessPreference::Balanced);
        $newer = $reranker->rerank($candidates, CatalogRecommendationFreshnessPreference::Newer);
        $provenRows = $reranker->rerank($candidates, CatalogRecommendationFreshnessPreference::Proven);

        $this->assertSame($candidates, $balanced);
        $this->assertSame($new->id, $newer[0]['id']);
        $this->assertSame($proven->id, $provenRows[0]['id']);
        $this->assertLessThanOrEqual(
            (int) config('recommendations.feedback.freshness_adjustment_cap', 40),
            abs($newer[0]['score'] - 100),
        );
    }

    public function test_common_taste_reranker_applies_explicit_reason_to_legacy_candidates_once(): void
    {
        $user = User::factory()->create();
        $genre = Genre::query()->create([
            'name' => 'Исключаемый из похожих жанр',
            'slug' => 'legacy-explicit-demotion-genre',
        ]);
        $feedbackTitle = CatalogTitle::factory()->create();
        $matching = CatalogTitle::factory()->create();
        $other = CatalogTitle::factory()->create();
        $feedbackTitle->genres()->attach($genre);
        $matching->genres()->attach($genre);
        app(CatalogRecommendationFeedbackService::class)->save(
            $user,
            $feedbackTitle,
            CatalogRecommendationFeedbackReason::DislikeGenre,
            $genre->id,
        );
        $candidates = [
            ['id' => $matching->id, 'score' => 100, 'source' => 'user_history', 'reason' => 'because_history'],
            ['id' => $other->id, 'score' => 100, 'source' => 'user_history', 'reason' => 'because_history'],
        ];
        $preferences = app(CatalogRecommendationPreferenceQuery::class)->forUser($user);

        $reranked = app(CatalogRecommendationTasteReranker::class)->rerank(
            $user,
            $candidates,
            $preferences,
        );

        $this->assertSame($other->id, $reranked[0]['id']);
        $this->assertSame(10, $reranked[1]['score']);
        $this->assertArrayNotHasKey('taste_demotions_applied', $reranked[0]);
        $this->assertStringNotContainsString('genre:', json_encode($reranked, JSON_THROW_ON_ERROR));
    }

    public function test_common_taste_reranker_preserves_personal_public_blend_slots(): void
    {
        $user = User::factory()->create();
        $publicOld = CatalogTitle::factory()->create(['year' => now()->year - 10]);
        $personalOld = CatalogTitle::factory()->create(['year' => now()->year - 10]);
        $publicNew = CatalogTitle::factory()->create(['year' => now()->year]);
        $personalNew = CatalogTitle::factory()->create(['year' => now()->year]);
        $candidates = [
            ['id' => $publicOld->id, 'score' => 100, 'source' => 'popularity', 'reason' => 'popular', 'blend_position' => 0],
            ['id' => $personalOld->id, 'score' => 100, 'source' => 'user_history', 'reason' => 'because_history', 'blend_position' => 1, 'taste_demotions_applied' => true],
            ['id' => $publicNew->id, 'score' => 100, 'source' => 'popularity', 'reason' => 'popular', 'blend_position' => 2],
            ['id' => $personalNew->id, 'score' => 100, 'source' => 'user_feedback', 'reason' => 'because_feedback', 'blend_position' => 3, 'taste_demotions_applied' => true],
        ];
        $preferences = new CatalogRecommendationPreferenceData(
            diversity: CatalogRecommendationDiversityPreference::Balanced,
            freshness: CatalogRecommendationFreshnessPreference::Newer,
            profileResetAt: null,
            hiddenGenreIds: [],
        );

        $reranked = app(CatalogRecommendationTasteReranker::class)->rerank(
            $user,
            $candidates,
            $preferences,
        );

        $this->assertSame(
            ['popularity', 'user_feedback', 'popularity', 'user_history'],
            array_column($reranked, 'source'),
        );
        $this->assertSame([$publicNew->id, $personalNew->id], [
            $reranked[0]['id'],
            $reranked[1]['id'],
        ]);
        $this->assertSame([0, 1, 2, 3], array_column($reranked, 'blend_position'));
    }
}
