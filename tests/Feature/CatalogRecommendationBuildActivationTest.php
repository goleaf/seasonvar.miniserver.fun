<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\CatalogTitleRelationSource;
use App\Enums\CatalogTitleRelationType;
use App\Models\CatalogRecommendationBuild;
use App\Models\CatalogRecommendationBuildRow;
use App\Models\CatalogTitle;
use App\Models\CatalogTitleRecommendation;
use App\Models\CatalogTitleRelation;
use App\Models\LicensedMedia;
use App\Services\Catalog\CatalogRecommendationBuildActivator;
use App\Services\Catalog\CatalogRecommendationBuildEvaluator;
use App\Services\Catalog\CatalogTitleRecommendationBuilder;
use App\Support\Cache\CacheDomain;
use App\Support\Cache\CacheVersionRegistry;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class CatalogRecommendationBuildActivationTest extends TestCase
{
    use RefreshDatabase;

    public function test_activation_atomically_replaces_active_rows_and_advances_cache_version(): void
    {
        $source = CatalogTitle::factory()->create();
        $oldCandidate = CatalogTitle::factory()->create();
        $first = CatalogTitle::factory()->create();
        $second = CatalogTitle::factory()->create();
        $previousBuild = CatalogRecommendationBuild::query()->create([
            'algorithm_version' => 'v5',
            'feature_version' => 'regex-v1',
            'status' => 'active',
            'started_at' => now()->subHour(),
            'completed_at' => now()->subMinutes(50),
            'activated_at' => now()->subMinutes(50),
        ]);
        CatalogTitleRecommendation::query()->create([
            'catalog_title_id' => $source->id,
            'recommended_title_id' => $oldCandidate->id,
            'score' => 500,
            'rank' => 1,
            'algorithm_version' => 'v5',
            'reasons' => ['genre' => ['score' => 400]],
            'computed_at' => now()->subHour(),
        ]);
        $build = CatalogRecommendationBuild::query()->create([
            'algorithm_version' => 'v6',
            'feature_version' => 'tokens-v2',
            'status' => 'evaluated',
            'started_at' => now(),
            'completed_at' => now(),
        ]);
        CatalogRecommendationBuildRow::query()->create($this->row($build, $source, $first, 1, 900));
        CatalogRecommendationBuildRow::query()->create($this->row($build, $source, $second, 2, 800));
        $versions = app(CacheVersionRegistry::class);
        $before = $versions->version(CacheDomain::Recommendations);

        app(CatalogRecommendationBuildActivator::class)->activate($build);

        $this->assertSame(
            [$first->id, $second->id],
            CatalogTitleRecommendation::query()
                ->where('catalog_title_id', $source->id)
                ->orderBy('rank')
                ->pluck('recommended_title_id')
                ->all(),
        );
        $this->assertSame(['v6'], CatalogTitleRecommendation::query()->distinct()->pluck('algorithm_version')->all());
        $this->assertSame('active', $build->fresh()->status);
        $this->assertNotNull($build->fresh()->activated_at);
        $this->assertSame('evaluated', $previousBuild->fresh()->status);
        $this->assertGreaterThan($before, $versions->version(CacheDomain::Recommendations));
    }

    public function test_builder_rejects_an_unjudged_shadow_build_without_changing_active_rows(): void
    {
        config([
            'recommendations.similarity_v6.shadow_enabled' => true,
            'recommendations.similarity_v6.allow_activation_without_golden' => false,
        ]);
        $source = CatalogTitle::factory()->create(['slug' => 'local-unjudged-source']);
        $oldCandidate = CatalogTitle::factory()->create(['slug' => 'old-active-candidate']);
        $newCandidate = CatalogTitle::factory()->create(['slug' => 'new-shadow-candidate']);
        LicensedMedia::factory()->for($newCandidate)->create([
            'status' => 'published',
            'published_at' => now(),
        ]);
        CatalogTitleRecommendation::query()->create([
            'catalog_title_id' => $source->id,
            'recommended_title_id' => $oldCandidate->id,
            'score' => 500,
            'rank' => 1,
            'algorithm_version' => 'v5',
            'reasons' => ['genre' => ['score' => 400]],
            'computed_at' => now()->subHour(),
        ]);
        CatalogTitleRelation::query()->create([
            'source_title_id' => $source->id,
            'target_title_id' => $newCandidate->id,
            'relation_type' => CatalogTitleRelationType::ProviderRelated,
            'source' => CatalogTitleRelationSource::ImportedProvider,
            'provider_key' => 'shadow-only-relation',
            'priority' => 100,
            'is_locked' => false,
            'is_active' => true,
        ]);

        $result = app(CatalogTitleRecommendationBuilder::class)->rebuild();

        $this->assertFalse($result['activated']);
        $this->assertFalse($result['gate_passed']);
        $this->assertIsInt($result['build_id']);
        $this->assertSame(
            [$oldCandidate->id],
            CatalogTitleRecommendation::query()->pluck('recommended_title_id')->all(),
        );
        $this->assertDatabaseHas('catalog_recommendation_builds', [
            'id' => $result['build_id'],
            'status' => 'rejected',
        ]);
        $this->assertDatabaseHas('catalog_recommendation_build_rows', [
            'build_id' => $result['build_id'],
            'catalog_title_id' => $source->id,
            'recommended_title_id' => $newCandidate->id,
        ]);
    }

    public function test_override_without_local_golden_never_accepts_an_empty_candidate_build(): void
    {
        config(['recommendations.similarity_v6.allow_activation_without_golden' => true]);
        $build = CatalogRecommendationBuild::query()->create([
            'algorithm_version' => 'v6',
            'feature_version' => 'tokens-v2',
            'status' => 'building',
            'started_at' => now(),
        ]);

        $evaluation = app(CatalogRecommendationBuildEvaluator::class)->evaluate($build);

        $this->assertFalse($evaluation->gatePassed);
        $this->assertSame('rejected', $build->fresh()->status);
        $this->assertSame(0, $build->fresh()->metrics['candidate_rows']);
        $this->assertStringContainsString('не содержит строк', (string) $build->fresh()->failure_message);
    }

    public function test_failed_activation_still_prunes_bounded_terminal_build_history(): void
    {
        config([
            'recommendations.similarity_v6.shadow_enabled' => true,
            'recommendations.similarity_v6.allow_activation_without_golden' => false,
            'recommendations.similarity_v6.build_history_limit' => 1,
        ]);

        foreach (range(1, 3) as $index) {
            CatalogRecommendationBuild::query()->create([
                'algorithm_version' => 'v6',
                'feature_version' => 'tokens-v2',
                'status' => 'failed',
                'failure_message' => 'Старый сбой '.$index,
                'started_at' => now()->subMinutes(10 + $index),
                'completed_at' => now()->subMinutes(9 + $index),
            ]);
        }

        $source = CatalogTitle::factory()->create([
            'slug' => 'vo-vse-tiazkiebreaking-bad',
        ]);
        $candidate = CatalogTitle::factory()->create([
            'slug' => 'lucse-zvonite-solubetter-call-saul',
        ]);
        LicensedMedia::factory()->for($candidate)->create([
            'status' => 'published',
            'published_at' => now(),
        ]);
        CatalogTitleRelation::query()->create([
            'source_title_id' => $source->id,
            'target_title_id' => $candidate->id,
            'relation_type' => CatalogTitleRelationType::ProviderRelated,
            'source' => CatalogTitleRelationSource::ImportedProvider,
            'provider_key' => 'forced-activation-failure',
            'priority' => 100,
            'is_locked' => false,
            'is_active' => true,
        ]);
        DB::statement(<<<'SQL'
            CREATE TRIGGER fail_recommendation_activation
            BEFORE INSERT ON catalog_title_recommendations
            BEGIN
                SELECT RAISE(ABORT, 'forced activation failure');
            END
            SQL);

        try {
            app(CatalogTitleRecommendationBuilder::class)->rebuild();
            $this->fail('Activation failure was not propagated.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('forced activation failure', $exception->getMessage());
        } finally {
            DB::statement('DROP TRIGGER IF EXISTS fail_recommendation_activation');
        }

        $this->assertSame(1, CatalogRecommendationBuild::query()->where('status', 'failed')->count());
        $this->assertStringContainsString(
            'forced activation failure',
            (string) CatalogRecommendationBuild::query()->where('status', 'failed')->value('failure_message'),
        );
    }

    public function test_builder_activates_a_watchable_fully_judged_improvement(): void
    {
        config([
            'recommendations.similarity_v6.shadow_enabled' => true,
            'recommendations.similarity_v6.allow_activation_without_golden' => false,
        ]);
        $source = CatalogTitle::factory()->create([
            'slug' => 'vo-vse-tiazkiebreaking-bad',
        ]);
        $candidate = CatalogTitle::factory()->create([
            'slug' => 'lucse-zvonite-solubetter-call-saul',
        ]);
        LicensedMedia::factory()->for($candidate)->create([
            'status' => 'published',
            'published_at' => now(),
        ]);
        CatalogTitleRelation::query()->create([
            'source_title_id' => $source->id,
            'target_title_id' => $candidate->id,
            'relation_type' => CatalogTitleRelationType::ProviderRelated,
            'source' => CatalogTitleRelationSource::ImportedProvider,
            'provider_key' => 'golden-provider-relation',
            'priority' => 100,
            'is_locked' => false,
            'is_active' => true,
        ]);

        $result = app(CatalogTitleRecommendationBuilder::class)->rebuild();

        $this->assertTrue($result['gate_passed']);
        $this->assertTrue($result['activated']);
        $this->assertSame(1.0, $result['candidate_metrics']['judgment_coverage']);
        $this->assertSame(1.0, $result['candidate_metrics']['watchable_rate']);
        $this->assertDatabaseHas('catalog_title_recommendations', [
            'catalog_title_id' => $source->id,
            'recommended_title_id' => $candidate->id,
            'algorithm_version' => 'v6',
        ]);
        $this->assertDatabaseHas('catalog_recommendation_builds', [
            'id' => $result['build_id'],
            'status' => 'active',
        ]);
    }

    public function test_evaluation_stores_the_versioned_score_distribution(): void
    {
        $source = CatalogTitle::factory()->create();
        $candidates = CatalogTitle::factory()->count(3)->create();
        $build = CatalogRecommendationBuild::query()->create([
            'algorithm_version' => 'v6',
            'feature_version' => 'tokens-v2',
            'status' => 'building',
            'started_at' => now(),
        ]);

        foreach ([600, 1_000, 2_000] as $index => $score) {
            CatalogRecommendationBuildRow::query()->create($this->row(
                $build,
                $source,
                $candidates[$index],
                $index + 1,
                $score,
            ));
        }

        app(CatalogRecommendationBuildEvaluator::class)->evaluate($build);
        $metrics = $build->fresh()->metrics;

        $this->assertSame(600, $metrics['score_min']);
        $this->assertSame(1_000, $metrics['score_median']);
        $this->assertSame(2_000, $metrics['score_p95']);
    }

    /** @return array<string, mixed> */
    private function row(
        CatalogRecommendationBuild $build,
        CatalogTitle $source,
        CatalogTitle $candidate,
        int $rank,
        int $score,
    ): array {
        return [
            'build_id' => $build->id,
            'catalog_title_id' => $source->id,
            'recommended_title_id' => $candidate->id,
            'score' => $score,
            'rank' => $rank,
            'matched_features_count' => 2,
            'metadata_score' => $score - 100,
            'source_score' => 0,
            'quality_score' => 100,
            'reasons' => ['genre' => ['score' => $score - 100]],
            'computed_at' => now(),
        ];
    }
}
