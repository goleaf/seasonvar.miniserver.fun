<?php

declare(strict_types=1);

namespace App\Services\Catalog;

use App\DTOs\CatalogRecommendationBuildEvaluation;
use App\DTOs\CatalogRecommendationQualityReport;
use App\Models\CatalogRecommendationBuild;
use App\Models\CatalogTitle;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;
use JsonException;

final class CatalogRecommendationBuildEvaluator
{
    public function __construct(
        private readonly CatalogRecommendationQualityEvaluator $quality,
        private readonly CatalogTitleQuery $titles,
    ) {}

    public function evaluate(CatalogRecommendationBuild $build, int $limit = 12): CatalogRecommendationBuildEvaluation
    {
        $limit = max(1, min(24, $limit));
        [$grades, $sourceIdsBySlug] = $this->availableGolden();
        $goldenAvailable = $grades !== [];
        $allowWithoutGolden = (bool) config(
            'recommendations.similarity_v6.allow_activation_without_golden',
            false,
        );

        if (! $goldenAvailable && ! $allowWithoutGolden) {
            $baseline = $this->emptyReport();
            $candidate = $this->emptyReport();
        } else {
            if ($sourceIdsBySlug === []) {
                $sourceIdsBySlug = $this->titles->visibleTo(null)
                    ->pluck('id', 'slug')
                    ->map(fn (mixed $id): int => (int) $id)
                    ->all();
            }

            $baseline = $this->quality->evaluate(
                $this->qualityRows(null, $sourceIdsBySlug, $limit),
                $grades,
                $limit,
            );
            $candidate = $this->quality->evaluate(
                $this->qualityRows((int) $build->id, $sourceIdsBySlug, $limit),
                $grades,
                $limit,
            );
        }

        $candidateRowCount = DB::table('catalog_recommendation_build_rows')
            ->where('build_id', $build->id)
            ->count();
        $minimumCoverage = max(
            0.0,
            min(1.0, (float) config('recommendations.similarity_v6.minimum_judgment_coverage', 0.8)),
        );
        $gatePassed = $candidateRowCount > 0
            && ($goldenAvailable
                ? $candidate->watchableRate === 1.0
                && $candidate->ndcgAtLimit >= $baseline->ndcgAtLimit
                && $candidate->emptySourceCount <= $baseline->emptySourceCount
                && $candidate->judgmentCoverage >= $minimumCoverage
                && $candidate->reasonFaithfulnessFailures === 0
                : $allowWithoutGolden
                    && $candidate->watchableRate === 1.0
                    && $candidate->reasonFaithfulnessFailures === 0);
        $rowChurn = $this->rowChurn((int) $build->id);
        $scoreDistribution = $this->scoreDistribution((int) $build->id, $candidateRowCount);
        $metrics = [
            'golden_available' => $goldenAvailable,
            'gate_passed' => $gatePassed,
            'baseline' => $baseline->toArray(),
            'candidate' => $candidate->toArray(),
            'row_churn' => $rowChurn,
            'candidate_rows' => $candidateRowCount,
            ...$scoreDistribution,
        ];

        $build->update([
            'status' => $gatePassed ? 'evaluated' : 'rejected',
            'metrics' => $metrics,
            'failure_message' => $gatePassed
                ? null
                : ($candidateRowCount === 0
                    ? 'Shadow build не содержит строк рекомендаций.'
                    : ($goldenAvailable
                        ? 'Shadow build не прошёл quality gate.'
                        : 'Для локального каталога нет доступной golden-разметки.')),
            'completed_at' => now(),
        ]);

        return new CatalogRecommendationBuildEvaluation(
            gatePassed: $gatePassed,
            goldenAvailable: $goldenAvailable,
            baseline: $baseline,
            candidate: $candidate,
            rowChurn: $rowChurn,
        );
    }

    /**
     * @return array{array<string, array<string, int>>, array<string, int>}
     */
    private function availableGolden(): array
    {
        $path = resource_path('recommendations/golden-v6.json');

        if (! is_file($path)) {
            return [[], []];
        }

        try {
            $document = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return [[], []];
        }

        $sources = is_array($document['sources'] ?? null) ? $document['sources'] : [];
        $rawGrades = [];

        foreach ($sources as $source) {
            if (! is_array($source) || ! is_string($source['slug'] ?? null)) {
                continue;
            }

            $slug = trim($source['slug']);

            if ($slug === '') {
                continue;
            }

            $rawGrades[$slug] = is_array($source['grades'] ?? null) ? $source['grades'] : [];
        }

        if ($rawGrades === []) {
            return [[], []];
        }

        $sourceIdsBySlug = $this->titles->visibleTo(null)
            ->whereIn('slug', array_keys($rawGrades))
            ->pluck('id', 'slug')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();
        $grades = array_intersect_key($rawGrades, $sourceIdsBySlug);

        return [$grades, $sourceIdsBySlug];
    }

    /**
     * @param  array<string, int>  $sourceIdsBySlug
     * @return list<array{source: string, candidate: string, rank: int, watchable: bool, reasons: list<string>}>
     */
    private function qualityRows(?int $buildId, array $sourceIdsBySlug, int $limit): array
    {
        if ($sourceIdsBySlug === []) {
            return [];
        }

        $sourceSlugsById = array_flip($sourceIdsBySlug);
        $table = $buildId === null
            ? 'catalog_title_recommendations'
            : 'catalog_recommendation_build_rows';
        $query = DB::table($table)
            ->whereIn('catalog_title_id', array_values($sourceIdsBySlug))
            ->where('rank', '<=', $limit);

        if ($buildId !== null) {
            $query->where('build_id', $buildId);
        }

        $rows = $query
            ->orderBy('catalog_title_id')
            ->orderBy('rank')
            ->get(['catalog_title_id', 'recommended_title_id', 'rank', 'reasons']);

        if ($rows->isEmpty()) {
            return [];
        }

        $candidateIds = $rows
            ->pluck('recommended_title_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
        $candidateSlugs = CatalogTitle::query()
            ->whereKey($candidateIds)
            ->pluck('slug', 'id')
            ->map(fn (mixed $slug): string => (string) $slug)
            ->all();
        $publicCounts = $this->titles->publicCardCounts(null);
        $watchableIds = $this->titles->visibleTo(null)
            ->whereKey($candidateIds)
            ->withCount([
                'licensedMedia as published_media_count' => fn ($query) => $publicCounts['licensedMedia as published_media_count']($query)
                    ->withoutKnownFailures()
                    ->withPlaybackLocation(),
            ])
            ->get(['catalog_titles.id'])
            ->filter(fn (CatalogTitle $title): bool => (int) $title->published_media_count > 0)
            ->pluck('id')
            ->mapWithKeys(fn (mixed $id): array => [(int) $id => true])
            ->all();

        return $rows
            ->map(function (object $row) use ($candidateSlugs, $sourceSlugsById, $watchableIds): ?array {
                $source = $sourceSlugsById[(int) $row->catalog_title_id] ?? null;
                $candidate = $candidateSlugs[(int) $row->recommended_title_id] ?? null;

                if (! is_string($source) || ! is_string($candidate)) {
                    return null;
                }

                $reasons = $this->reasonKeys($row->reasons);

                return [
                    'source' => $source,
                    'candidate' => $candidate,
                    'rank' => (int) $row->rank,
                    'watchable' => isset($watchableIds[(int) $row->recommended_title_id]),
                    'reasons' => $reasons,
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    /** @return list<string> */
    private function reasonKeys(mixed $value): array
    {
        if (is_string($value)) {
            try {
                $value = json_decode($value, true, flags: JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                return [];
            }
        }

        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter(
            array_keys($value),
            fn (mixed $key): bool => is_string($key) && $key !== '',
        ));
    }

    private function rowChurn(int $buildId): float
    {
        $activeCount = DB::table('catalog_title_recommendations')->count();
        $candidateCount = DB::table('catalog_recommendation_build_rows')
            ->where('build_id', $buildId)
            ->count();
        $removed = DB::table('catalog_title_recommendations as active')
            ->leftJoin('catalog_recommendation_build_rows as candidate', function (JoinClause $join) use ($buildId): void {
                $join
                    ->on('candidate.catalog_title_id', '=', 'active.catalog_title_id')
                    ->on('candidate.recommended_title_id', '=', 'active.recommended_title_id')
                    ->where('candidate.build_id', '=', $buildId);
            })
            ->whereNull('candidate.id')
            ->count();
        $added = DB::table('catalog_recommendation_build_rows as candidate')
            ->leftJoin('catalog_title_recommendations as active', function (JoinClause $join): void {
                $join
                    ->on('active.catalog_title_id', '=', 'candidate.catalog_title_id')
                    ->on('active.recommended_title_id', '=', 'candidate.recommended_title_id');
            })
            ->where('candidate.build_id', $buildId)
            ->whereNull('active.id')
            ->count();

        return round(($removed + $added) / max(1, $activeCount + $candidateCount), 4);
    }

    /** @return array{score_min: int|null, score_median: int|null, score_p95: int|null} */
    private function scoreDistribution(int $buildId, int $rowCount): array
    {
        if ($rowCount < 1) {
            return [
                'score_min' => null,
                'score_median' => null,
                'score_p95' => null,
            ];
        }

        $scores = DB::table('catalog_recommendation_build_rows')
            ->where('build_id', $buildId)
            ->orderBy('score')
            ->orderBy('id');
        $minimum = (int) (clone $scores)->value('score');
        $medianOffset = (int) floor(($rowCount - 1) * 0.5);
        $p95Offset = max(0, (int) ceil($rowCount * 0.95) - 1);

        return [
            'score_min' => $minimum,
            'score_median' => (int) (clone $scores)->offset($medianOffset)->value('score'),
            'score_p95' => (int) (clone $scores)->offset($p95Offset)->value('score'),
        ];
    }

    private function emptyReport(): CatalogRecommendationQualityReport
    {
        return new CatalogRecommendationQualityReport(
            precisionAtLimit: 0.0,
            ndcgAtLimit: 0.0,
            sourceCount: 0,
            emptySourceCount: 0,
            watchableRate: 0.0,
            candidateCoverage: 0,
            maximumIncoming: 0,
            incomingAtLeast100: 0,
            reasonFaithfulnessFailures: 0,
            judgedRowCount: 0,
            judgmentCoverage: 0.0,
        );
    }
}
