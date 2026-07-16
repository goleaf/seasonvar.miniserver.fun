<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\DTOs\CatalogPersonalizedCandidateSet;
use App\DTOs\CatalogRecommendationContext;
use App\Enums\CatalogPersonalizationConfidence;
use App\Enums\CatalogRecommendationFeedback;
use App\Enums\CatalogRecommendationSource;
use App\Enums\CatalogRecommendationType;
use App\Enums\CatalogWatchStatus;
use App\Models\CatalogRecommendationBuild;
use App\Models\CatalogTitle;
use App\Models\CatalogTitleRecommendation;
use App\Models\CatalogTitleUserState;
use App\Models\LicensedMedia;
use App\Models\User;
use App\Services\Catalog\CatalogPersonalizedRecommendationQuery;
use App\Services\Catalog\CatalogPersonalPreferenceProfileBuilder;
use App\Services\Catalog\CatalogRecommendationScoreNormalizer;
use App\Services\Catalog\CatalogRecommendationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class CatalogPersonalizedRecommendationQueryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'recommendations.personalized_v2.enabled' => true,
            'recommendations.personalized_v2.rollout_percent' => 100,
        ]);
        CatalogRecommendationBuild::query()->create([
            'algorithm_version' => 'v6',
            'feature_version' => 'tokens-v2',
            'status' => 'active',
            'metrics' => ['score_min' => 600, 'score_median' => 1_000, 'score_p95' => 1_600],
            'started_at' => now(),
            'activated_at' => now(),
        ]);
    }

    public function test_query_returns_cold_set_without_private_evidence(): void
    {
        $set = app(CatalogPersonalizedRecommendationQuery::class)->candidateSet(
            $this->context(User::factory()->create()),
            [],
        );

        $this->assertInstanceOf(CatalogPersonalizedCandidateSet::class, $set);
        $this->assertSame(CatalogPersonalizationConfidence::Cold, $set->confidence);
        $this->assertSame([], $set->candidates);
        $this->assertSame([], $set->sourceTitleIds);
    }

    public function test_query_filters_sources_explicit_exclusions_and_unwatchable_candidates(): void
    {
        $user = User::factory()->create();
        $source = $this->source($user, CatalogWatchStatus::Planned);
        $eligible = $this->watchableTitle();
        $excluded = $this->watchableTitle();
        $unwatchable = CatalogTitle::factory()->create();

        foreach ([$source, $eligible, $excluded, $unwatchable] as $candidate) {
            if ($candidate->is($source)) {
                continue;
            }

            $this->recommend($source, $candidate, 1_200);
        }

        $profile = app(CatalogPersonalPreferenceProfileBuilder::class)->forUser($user);
        $this->assertSame(
            CatalogPersonalizationConfidence::Low,
            $profile->confidence,
        );
        $this->assertNotNull(app(CatalogRecommendationScoreNormalizer::class)->forActiveBuild());

        $set = app(CatalogPersonalizedRecommendationQuery::class)->candidateSet(
            $this->context($user),
            [$excluded->id],
        );

        $this->assertSame(CatalogPersonalizationConfidence::Low, $set->confidence);
        $this->assertSame([$source->id], $set->sourceTitleIds);
        $this->assertSame([$eligible->id], array_column($set->candidates, 'id'));
    }

    public function test_service_blends_public_rows_by_confidence_and_reports_actual_fallback(): void
    {
        $personalCandidates = collect(range(1, 8))->map(fn (): CatalogTitle => $this->watchableTitle());
        collect(range(1, 12))->each(fn (): CatalogTitle => $this->watchableTitle());
        $low = User::factory()->create();
        $medium = User::factory()->create();
        $high = User::factory()->create();
        $lowSources = [$this->source($low, CatalogWatchStatus::Planned)];
        $mediumSources = [
            $this->source($medium, CatalogWatchStatus::Completed),
            $this->source($medium, CatalogWatchStatus::Completed),
        ];
        $highSources = [
            $this->source($high, CatalogWatchStatus::Completed, rating: 10),
            $this->source($high, CatalogWatchStatus::Completed, rating: 10),
            $this->source($high, CatalogWatchStatus::Completed, rating: 10),
        ];

        foreach ([...$lowSources, ...$mediumSources, ...$highSources] as $source) {
            foreach ($personalCandidates as $index => $candidate) {
                $this->recommend($source, $candidate, 1_500 - ($index * 50));
            }
        }

        $lowResult = app(CatalogRecommendationService::class)->discover($this->context($low, perPage: 8));
        $mediumResult = app(CatalogRecommendationService::class)->discover($this->context($medium, perPage: 8));
        $highResult = app(CatalogRecommendationService::class)->discover($this->context($high, perPage: 8));

        $this->assertSame(CatalogPersonalizationConfidence::Low, $lowResult->personalizationConfidence);
        $this->assertSame(2, $this->personalCount($lowResult->items->pluck('source')->all()));
        $this->assertSame(CatalogRecommendationType::Popular, $lowResult->displayType);
        $this->assertTrue($lowResult->personalized);
        $this->assertFalse($lowResult->coldStart);

        $this->assertSame(CatalogPersonalizationConfidence::Medium, $mediumResult->personalizationConfidence);
        $this->assertSame(4, $this->personalCount($mediumResult->items->pluck('source')->all()));
        $this->assertSame(CatalogRecommendationType::Popular, $mediumResult->displayType);

        $this->assertSame(CatalogPersonalizationConfidence::High, $highResult->personalizationConfidence);
        $this->assertSame(8, $this->personalCount($highResult->items->pluck('source')->all()));
        $this->assertSame(CatalogRecommendationType::Personalized, $highResult->displayType);
    }

    public function test_zero_percent_keeps_legacy_path_and_full_rollout_uses_v2(): void
    {
        $user = User::factory()->create();
        $source = $this->source($user, CatalogWatchStatus::Planned);
        $candidate = $this->watchableTitle();
        $this->recommend($source, $candidate, 1_300);
        config(['recommendations.personalized_v2.rollout_percent' => 0]);

        $legacy = app(CatalogRecommendationService::class)->discover($this->context($user, perPage: 4));

        $this->assertNull($legacy->personalizationConfidence);
        config(['recommendations.personalized_v2.rollout_percent' => 100]);

        $v2 = app(CatalogRecommendationService::class)->discover($this->context($user, perPage: 4));

        $this->assertSame(CatalogPersonalizationConfidence::Low, $v2->personalizationConfidence);
    }

    public function test_explicit_feedback_and_dropped_titles_are_never_returned_by_v2_or_its_fallback(): void
    {
        $user = User::factory()->create();
        $source = $this->source($user, CatalogWatchStatus::Completed, rating: 10);
        $eligible = $this->watchableTitle();
        $notInterested = $this->watchableTitle();
        $dropped = $this->watchableTitle();

        foreach ([$eligible, $notInterested, $dropped] as $candidate) {
            $this->recommend($source, $candidate, 1_400);
        }

        CatalogTitleUserState::query()->create([
            'user_id' => $user->id,
            'catalog_title_id' => $notInterested->id,
            'recommendation_feedback' => CatalogRecommendationFeedback::NotInterested,
            'recommendation_feedback_updated_at' => now(),
        ]);
        CatalogTitleUserState::query()->create([
            'user_id' => $user->id,
            'catalog_title_id' => $dropped->id,
            'watch_status' => CatalogWatchStatus::Dropped,
            'watch_status_updated_at' => now(),
        ]);

        $result = app(CatalogRecommendationService::class)->discover($this->context($user, perPage: 8));
        $ids = $result->items->pluck('title.id')->all();

        $this->assertContains($eligible->id, $ids);
        $this->assertNotContains($notInterested->id, $ids);
        $this->assertNotContains($dropped->id, $ids);
    }

    private function source(User $user, CatalogWatchStatus $status, ?int $rating = null): CatalogTitle
    {
        $title = CatalogTitle::factory()->create();
        CatalogTitleUserState::query()->create([
            'user_id' => $user->id,
            'catalog_title_id' => $title->id,
            'watch_status' => $status,
            'watch_status_updated_at' => now(),
            'rating' => $rating,
            'rating_updated_at' => $rating === null ? null : now(),
        ]);

        return $title;
    }

    private function watchableTitle(): CatalogTitle
    {
        $title = CatalogTitle::factory()->create();
        LicensedMedia::factory()->for($title)->create([
            'status' => 'published',
            'published_at' => now(),
        ]);

        return $title;
    }

    private function recommend(CatalogTitle $source, CatalogTitle $candidate, int $score): void
    {
        CatalogTitleRecommendation::query()->create([
            'catalog_title_id' => $source->id,
            'recommended_title_id' => $candidate->id,
            'score' => $score,
            'rank' => 1,
            'algorithm_version' => 'v6',
            'reasons' => ['genre' => ['score' => $score]],
            'computed_at' => now(),
        ]);
    }

    private function context(User $user, int $perPage = 24): CatalogRecommendationContext
    {
        return new CatalogRecommendationContext(
            type: CatalogRecommendationType::Personalized,
            user: $user,
            locale: 'ru',
            perPage: $perPage,
        );
    }

    /** @param list<CatalogRecommendationSource> $sources */
    private function personalCount(array $sources): int
    {
        return collect($sources)->filter(fn (CatalogRecommendationSource $source): bool => str_starts_with(
            $source->value,
            'user_',
        ))->count();
    }
}
