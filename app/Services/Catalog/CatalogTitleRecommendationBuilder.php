<?php

namespace App\Services\Catalog;

use App\Enums\ReviewStatus;
use App\Models\CatalogRecommendationBuild;
use App\Models\CatalogTitle;
use App\Models\CatalogTitleRecommendation;
use App\Services\Reviews\ReviewSchema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Throwable;

final class CatalogTitleRecommendationBuilder
{
    private const DEFAULT_MIN_SCORE = 600;

    private const EDITORIAL_COLLECTION_SIGNAL_PREFIX = 'editorial_collection:';

    private const MAX_PROFILE_CHUNK_SIZE = 100;

    /** @var list<string> */
    private const SOURCE_SIGNAL_TYPES = [
        'provider_recommendation',
        'related_title',
        'editorial_collection',
    ];

    /** @var array<string, array<int|string, int>> */
    private array $featureDocumentCounts = [];

    /** @var array<string, int> */
    private array $themeDocumentCounts = [];

    private int $profileCount = 0;

    /** @var list<string> */
    private array $profileRelationTypes = [];

    public function __construct(
        private readonly CatalogTaxonomyRegistry $taxonomies,
        private readonly CatalogTitleQuery $titles,
        private readonly CatalogRecommendationThemeExtractor $themes,
        private readonly ReviewSchema $reviewSchema,
        private readonly CatalogRecommendationCacheInvalidator $cacheInvalidator,
        private readonly CatalogRecommendationCandidateGenerator $candidateGenerator,
        private readonly CatalogRecommendationPairScorer $pairScorer,
        private readonly CatalogRecommendationBuildEvaluator $buildEvaluator,
        private readonly CatalogRecommendationBuildActivator $buildActivator,
        private readonly CatalogRecommendationBuildPruner $buildPruner,
        private readonly CatalogRecommendationDirtyTitleTracker $dirtyTitles,
    ) {}

    /**
     * @param  (callable(string, array<string, mixed>): void)|null  $progress
     * @return array{mode: string, algorithm_version: string, titles: int, titles_with_recommendations: int, titles_without_recommendations: int, stored: int, deleted: int, average_recommendations: float, min_score: int, max_per_title: int, duration_ms: int}
     */
    public function rebuild(?callable $progress = null): array
    {
        $build = $this->startShadowBuild();

        try {
            return $this->performRebuild($progress, $build);
        } catch (Throwable $exception) {
            $this->failShadowBuild($build, $exception);
            $this->pruneShadowBuilds($progress, $build);

            throw $exception;
        } finally {
            $this->resetWorkingSet();
        }
    }

    /**
     * @param  (callable(string, array<string, mixed>): void)|null  $progress
     * @return array<string, mixed>
     */
    public function rebuildDirty(?callable $progress = null): array
    {
        $startedAt = microtime(true);
        $dirtyLimit = max(1, (int) config('recommendations.similarity_v6.dirty_title_limit', 2_000));
        $dirtyIds = $this->dirtyTitles->ids($dirtyLimit + 1);
        $fallbackReason = $this->scopedFallbackReason($dirtyIds, $dirtyLimit);

        if ($fallbackReason !== null) {
            return $this->fallbackToFullRebuild($dirtyIds, $fallbackReason, $progress);
        }

        if ($dirtyIds === []) {
            return $this->emptyScopedResult($startedAt);
        }

        try {
            $chunkSize = min(
                self::MAX_PROFILE_CHUNK_SIZE,
                max(10, (int) config('seasonvar.recommendations.chunk_size', self::MAX_PROFILE_CHUNK_SIZE)),
            );
            $profiles = $this->compactProfileIndex($chunkSize);
            $sourceLimit = max(1, (int) config('recommendations.similarity_v6.scoped_source_limit', 2_000));
            $affectedIds = $this->affectedSourceIds($dirtyIds, $profiles, $sourceLimit + 1);

            if (count($affectedIds) > $sourceLimit) {
                $this->resetWorkingSet();

                return $this->fallbackToFullRebuild(
                    $dirtyIds,
                    'affected-source-limit-exceeded',
                    $progress,
                );
            }

            return $this->performScopedRebuild(
                $dirtyIds,
                $affectedIds,
                $profiles,
                $progress,
                $startedAt,
            );
        } finally {
            $this->resetWorkingSet();
        }
    }

    /**
     * @param  (callable(string, array<string, mixed>): void)|null  $progress
     * @return array{mode: string, algorithm_version: string, titles: int, titles_with_recommendations: int, titles_without_recommendations: int, stored: int, deleted: int, average_recommendations: float, min_score: int, max_per_title: int, duration_ms: int}
     */
    private function performRebuild(?callable $progress, ?CatalogRecommendationBuild $build): array
    {
        $startedAt = microtime(true);
        $chunkSize = min(
            self::MAX_PROFILE_CHUNK_SIZE,
            max(10, (int) config('seasonvar.recommendations.chunk_size', self::MAX_PROFILE_CHUNK_SIZE)),
        );
        $minScore = max(1, (int) config('seasonvar.recommendations.min_score', self::DEFAULT_MIN_SCORE));
        $maxPerTitle = max(1, (int) config('seasonvar.recommendations.max_per_title', 12));
        $computedAt = now();
        $profiles = $this->compactProfileIndex($chunkSize);
        $stored = 0;
        $activeRowsBefore = $build !== null ? CatalogTitleRecommendation::query()->count() : 0;
        $deleted = $build === null ? $this->deleteOutOfScopeRecommendations() : 0;
        $titlesWithRecommendations = 0;

        $this->progress($progress, 'catalog-title-recommendations-started', [
            'titles' => count($profiles),
            'chunk_size' => $chunkSize,
            'min_score' => $minScore,
            'max_per_title' => $maxPerTitle,
            'build_id' => $build?->id,
        ]);

        $processedInChunk = 0;

        foreach ($profiles as $encodedProfile) {
            $profile = $this->decodeProfile($encodedProfile);
            $rows = $this->recommendationRows($profile, $profiles, $minScore, $maxPerTitle, $computedAt);
            $result = $build !== null
                ? $this->storeBuildRecommendations((int) $build->id, $rows)
                : $this->replaceTitleRecommendations($profile['id'], $rows);

            $stored += $result['stored'];
            $deleted += $result['deleted'];
            $processedInChunk++;

            if ($rows !== []) {
                $titlesWithRecommendations++;
            }

            if ($processedInChunk === $chunkSize) {
                $this->progress($progress, 'catalog-title-recommendations-chunk-complete', [
                    'processed' => $processedInChunk,
                    'stored' => $stored,
                    'deleted' => $deleted,
                ]);
                $processedInChunk = 0;
            }
        }

        if ($processedInChunk > 0) {
            $this->progress($progress, 'catalog-title-recommendations-chunk-complete', [
                'processed' => $processedInChunk,
                'stored' => $stored,
                'deleted' => $deleted,
            ]);
        }

        $evaluation = null;
        $activated = $build === null;
        $gatePassed = $build === null;

        if ($build !== null) {
            $evaluation = $this->buildEvaluator->evaluate($build, $maxPerTitle);
            $gatePassed = $evaluation->gatePassed;

            if ($gatePassed) {
                $this->buildActivator->activate($build);
                $activated = true;
                $deleted = $activeRowsBefore;
            }
        } else {
            $this->cacheInvalidator->publicSignalsChanged('recommendation-rebuild');
        }

        $prunedBuilds = $this->pruneShadowBuilds($progress, $build);

        $titleCount = count($profiles);
        $result = [
            'mode' => 'full',
            'algorithm_version' => $this->algorithmVersion(),
            'titles' => $titleCount,
            'titles_with_recommendations' => $titlesWithRecommendations,
            'titles_without_recommendations' => max(0, $titleCount - $titlesWithRecommendations),
            'stored' => $stored,
            'deleted' => $deleted,
            'average_recommendations' => $titleCount > 0 ? round($stored / $titleCount, 2) : 0.0,
            'min_score' => $minScore,
            'max_per_title' => $maxPerTitle,
            'build_id' => $build?->id,
            'activated' => $activated,
            'gate_passed' => $gatePassed,
            'baseline_metrics' => $evaluation?->baseline->toArray(),
            'candidate_metrics' => $evaluation?->candidate->toArray(),
            'row_churn' => $evaluation?->rowChurn,
            'shadow_builds_pruned' => $prunedBuilds,
            'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
        ];

        $this->progress($progress, 'catalog-title-recommendations-complete', $result);

        return $result;
    }

    /**
     * @param  list<int>  $dirtyIds
     * @param  array<int, string>  $profiles
     * @return list<int>
     */
    private function affectedSourceIds(array $dirtyIds, array $profiles, int $limit): array
    {
        $affected = [];

        foreach ($dirtyIds as $dirtyId) {
            $affected[$dirtyId] = true;
        }

        CatalogTitleRecommendation::query()
            ->whereIntegerInRaw('recommended_title_id', $dirtyIds)
            ->orderBy('catalog_title_id')
            ->limit($limit)
            ->pluck('catalog_title_id')
            ->each(function (mixed $id) use (&$affected): void {
                $affected[(int) $id] = true;
            });

        $neighbourLimit = max(
            1,
            (int) config('recommendations.similarity_v6.scoped_neighbours_per_title', 240),
        );

        foreach ($dirtyIds as $dirtyId) {
            $encodedProfile = $profiles[$dirtyId] ?? null;

            if ($encodedProfile === null) {
                continue;
            }

            foreach ($this->candidateGenerator->idsFor($this->decodeProfile($encodedProfile), $neighbourLimit) as $id) {
                $affected[$id] = true;

                if (count($affected) >= $limit) {
                    break 2;
                }
            }
        }

        $ids = array_map('intval', array_keys($affected));
        sort($ids, SORT_NUMERIC);

        return $ids;
    }

    /**
     * @param  list<int>  $dirtyIds
     * @param  list<int>  $affectedIds
     * @param  array<int, string>  $profiles
     * @param  (callable(string, array<string, mixed>): void)|null  $progress
     * @return array<string, mixed>
     */
    private function performScopedRebuild(
        array $dirtyIds,
        array $affectedIds,
        array $profiles,
        ?callable $progress,
        float $startedAt,
    ): array {
        $minScore = max(1, (int) config('seasonvar.recommendations.min_score', self::DEFAULT_MIN_SCORE));
        $maxPerTitle = max(1, (int) config('seasonvar.recommendations.max_per_title', 12));
        $computedAt = now();

        $this->progress($progress, 'catalog-title-recommendations-scoped-started', [
            'dirty_titles' => count($dirtyIds),
            'affected_titles' => count($affectedIds),
            'min_score' => $minScore,
            'max_per_title' => $maxPerTitle,
        ]);

        $currentRows = CatalogTitleRecommendation::query()
            ->whereIntegerInRaw('catalog_title_id', $affectedIds)
            ->select([
                'id',
                'catalog_title_id',
                'recommended_title_id',
                'score',
                'rank',
                'matched_features_count',
                'metadata_score',
                'source_score',
                'quality_score',
                'reasons',
            ])
            ->orderBy('catalog_title_id')
            ->orderBy('rank')
            ->get()
            ->groupBy('catalog_title_id');
        $changedRows = [];
        $titlesWithRecommendations = 0;
        $totalRecommendations = 0;

        foreach ($affectedIds as $sourceId) {
            $encodedProfile = $profiles[$sourceId] ?? null;
            $rows = $encodedProfile !== null
                ? $this->recommendationRows(
                    $this->decodeProfile($encodedProfile),
                    $profiles,
                    $minScore,
                    $maxPerTitle,
                    $computedAt,
                )
                : [];
            $totalRecommendations += count($rows);

            if ($rows !== []) {
                $titlesWithRecommendations++;
            }

            if ($this->recommendationPayloadHash($rows)
                !== $this->recommendationPayloadHash($currentRows->get($sourceId, []))) {
                $changedRows[$sourceId] = $rows;
            }
        }

        $deleted = 0;
        $stored = collect($changedRows)->sum(fn (array $rows): int => count($rows));

        if ($changedRows !== []) {
            DB::transaction(function () use ($changedRows, &$deleted): void {
                $sourceIds = array_map('intval', array_keys($changedRows));
                $deleted = CatalogTitleRecommendation::query()
                    ->whereIntegerInRaw('catalog_title_id', $sourceIds)
                    ->delete();
                $rows = array_merge(...array_values($changedRows));

                foreach (array_chunk($rows, 500) as $chunk) {
                    CatalogTitleRecommendation::query()->insert($chunk);
                }
            });

            $this->cacheInvalidator->publicSignalsChanged('recommendation-scoped-rebuild');
        }

        $this->dirtyTitles->forget($dirtyIds);

        $result = [
            'mode' => 'scoped',
            'algorithm_version' => $this->algorithmVersion(),
            'titles' => count($affectedIds),
            'titles_with_recommendations' => $titlesWithRecommendations,
            'titles_without_recommendations' => max(0, count($affectedIds) - $titlesWithRecommendations),
            'stored' => (int) $stored,
            'deleted' => $deleted,
            'average_recommendations' => $affectedIds !== []
                ? round($totalRecommendations / count($affectedIds), 2)
                : 0.0,
            'min_score' => $minScore,
            'max_per_title' => $maxPerTitle,
            'build_id' => null,
            'activated' => true,
            'gate_passed' => true,
            'baseline_metrics' => null,
            'candidate_metrics' => null,
            'row_churn' => [
                'changed_sources' => count($changedRows),
                'inserted' => (int) $stored,
                'deleted' => $deleted,
            ],
            'dirty_titles' => count($dirtyIds),
            'affected_titles' => count($affectedIds),
            'changed_titles' => count($changedRows),
            'unchanged_titles' => max(0, count($affectedIds) - count($changedRows)),
            'scope_fallback_reason' => null,
            'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
        ];

        $this->progress($progress, 'catalog-title-recommendations-scoped-complete', $result);

        return $result;
    }

    /** @param iterable<mixed> $rows */
    private function recommendationPayloadHash(iterable $rows): string
    {
        $payload = [];

        foreach ($rows as $row) {
            $reasons = data_get($row, 'reasons', []);

            if (is_string($reasons)) {
                $reasons = json_decode($reasons, true, flags: JSON_THROW_ON_ERROR);
            }

            $payload[] = [
                'candidate_id' => (int) data_get($row, 'recommended_title_id'),
                'rank' => (int) data_get($row, 'rank'),
                'score' => (int) data_get($row, 'score'),
                'matched_features_count' => (int) data_get($row, 'matched_features_count'),
                'metadata_score' => (int) data_get($row, 'metadata_score'),
                'source_score' => (int) data_get($row, 'source_score'),
                'quality_score' => (int) data_get($row, 'quality_score'),
                'reasons' => is_array($reasons) ? $this->canonicalizePayload($reasons) : [],
            ];
        }

        usort($payload, static fn (array $left, array $right): int => [
            $left['rank'],
            $left['candidate_id'],
        ] <=> [
            $right['rank'],
            $right['candidate_id'],
        ]);

        return hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }

    /** @return array<array-key, mixed> */
    private function canonicalizePayload(array $value): array
    {
        if (array_is_list($value)) {
            return array_map(
                fn (mixed $item): mixed => is_array($item) ? $this->canonicalizePayload($item) : $item,
                $value,
            );
        }

        ksort($value, SORT_STRING);

        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = $this->canonicalizePayload($item);
            }
        }

        return $value;
    }

    /**
     * @param  list<int>  $dirtyIds
     * @param  (callable(string, array<string, mixed>): void)|null  $progress
     * @return array<string, mixed>
     */
    private function fallbackToFullRebuild(array $dirtyIds, string $reason, ?callable $progress): array
    {
        $result = $this->rebuild($progress);

        if (($result['activated'] ?? false) === true && ($result['gate_passed'] ?? false) === true) {
            $this->forgetAllDirtyTitles();
        }

        return [
            ...$result,
            'dirty_titles' => count($dirtyIds),
            'affected_titles' => (int) ($result['titles'] ?? 0),
            'changed_titles' => (int) ($result['titles'] ?? 0),
            'unchanged_titles' => 0,
            'scope_fallback_reason' => $reason,
        ];
    }

    /** @param list<int> $dirtyIds */
    private function scopedFallbackReason(array $dirtyIds, int $dirtyLimit): ?string
    {
        if (count($dirtyIds) > $dirtyLimit) {
            return 'dirty-limit-exceeded';
        }

        return $this->activeFeatureSetMatches() ? null : 'active-version-mismatch';
    }

    private function activeFeatureSetMatches(): bool
    {
        if (! Schema::hasTable('catalog_recommendation_builds')) {
            return false;
        }

        $activeBuild = CatalogRecommendationBuild::query()
            ->where('status', 'active')
            ->latest('id')
            ->first();

        if ($activeBuild === null
            || $activeBuild->algorithm_version !== $this->algorithmVersion()
            || $activeBuild->feature_version !== $this->featureVersion()) {
            return false;
        }

        return ! CatalogTitleRecommendation::query()
            ->where('algorithm_version', '!=', $this->algorithmVersion())
            ->exists();
    }

    /** @return array<string, mixed> */
    private function emptyScopedResult(float $startedAt): array
    {
        return [
            'mode' => 'noop',
            'algorithm_version' => $this->algorithmVersion(),
            'titles' => 0,
            'titles_with_recommendations' => 0,
            'titles_without_recommendations' => 0,
            'stored' => 0,
            'deleted' => 0,
            'average_recommendations' => 0.0,
            'min_score' => max(1, (int) config('seasonvar.recommendations.min_score', self::DEFAULT_MIN_SCORE)),
            'max_per_title' => max(1, (int) config('seasonvar.recommendations.max_per_title', 12)),
            'build_id' => null,
            'activated' => true,
            'gate_passed' => true,
            'baseline_metrics' => null,
            'candidate_metrics' => null,
            'row_churn' => null,
            'dirty_titles' => 0,
            'affected_titles' => 0,
            'changed_titles' => 0,
            'unchanged_titles' => 0,
            'scope_fallback_reason' => null,
            'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
        ];
    }

    private function forgetAllDirtyTitles(): void
    {
        do {
            $ids = $this->dirtyTitles->ids(2_000);
            $this->dirtyTitles->forget($ids);
        } while ($ids !== []);
    }

    /** @return array<int, string> */
    private function compactProfileIndex(int $chunkSize): array
    {
        $profiles = [];
        $publicCounts = $this->titles->publicCardCounts(null);
        $reviewCount = $this->reviewSchema->communityAvailable()
            ? ['reviews' => fn (Builder $query): Builder => $query
                ->where('status', ReviewStatus::Published->value)
                ->whereNull('deleted_at')
                ->whereNull('merged_into_id')]
            : ['reviews'];
        $this->profileRelationTypes = array_keys($this->taxonomies->relations());

        $this->titles->visibleTo(null)
            ->select(['id', 'title', 'original_title', 'description', 'type', 'year'])
            ->with(array_merge($this->recommendationRelationLoads(), [
                'ratings:id,catalog_title_id,rating',
                'recommendationSignals' => fn ($query) => $query
                    ->positive()
                    ->whereIn('signal_type', self::SOURCE_SIGNAL_TYPES)
                    ->select(['id', 'catalog_title_id', 'signal_type', 'signal_key', 'weight']),
                'outgoingRelations' => fn ($query) => $query
                    ->select(['id', 'source_title_id', 'target_title_id', 'priority'])
                    ->where('is_active', true),
            ]))
            ->withCount([
                ...$reviewCount,
                'licensedMedia as published_media_count' => $publicCounts['licensedMedia as published_media_count'],
            ])
            ->lazyById($chunkSize)
            ->each(function (CatalogTitle $title) use (&$profiles): void {
                $relations = $this->profileRelations($title);
                $themes = array_keys($this->themes->extract(
                    $title->title,
                    $title->original_title,
                    $title->description,
                ));
                $profile = [
                    'id' => (int) $title->id,
                    'type' => (string) $title->type,
                    'year' => $title->year !== null ? (int) $title->year : null,
                    'published_media_count' => (int) $title->published_media_count,
                    'reviews_count' => (int) $title->reviews_count,
                    'best_rating' => $this->bestRating($title),
                    'signals' => $this->profileSignals($title),
                    'provider_targets' => $this->profileProviderTargets($title),
                    'relations' => $relations,
                    'themes' => $themes,
                ];
                $profiles[$title->id] = $this->encodeProfile($profile);
                $this->candidateGenerator->add($profile);

                foreach ($profile['relations'] as $filterType => $ids) {
                    foreach ($ids as $id) {
                        $this->featureDocumentCounts[$filterType][$id] = ($this->featureDocumentCounts[$filterType][$id] ?? 0) + 1;
                    }
                }

                foreach ($themes as $theme) {
                    $this->themeDocumentCounts[$theme] = ($this->themeDocumentCounts[$theme] ?? 0) + 1;
                }

                foreach (array_keys($profile['signals']) as $signalKey) {
                    if (! str_starts_with($signalKey, self::EDITORIAL_COLLECTION_SIGNAL_PREFIX)) {
                        continue;
                    }

                    $this->featureDocumentCounts['editorial_collection'][$signalKey]
                        = ($this->featureDocumentCounts['editorial_collection'][$signalKey] ?? 0) + 1;
                }
            });

        $this->profileCount = count($profiles);

        return $profiles;
    }

    /** @return array<string, \Closure> */
    private function recommendationRelationLoads(): array
    {
        return collect($this->taxonomies->relations())
            ->mapWithKeys(function (array $config, string $filterType): array {
                $modelClass = $config['model'];
                $table = (new $modelClass)->getTable();

                return [
                    $config['relation'] => function ($query) use ($filterType, $table) {
                        $query->select($table.'.id');

                        if ($filterType === 'tag') {
                            $query->publiclyEligible();
                        }

                        return $query;
                    },
                ];
            })
            ->all();
    }

    /**
     * @return array<string, list<int>>
     */
    private function profileRelations(CatalogTitle $title): array
    {
        $relations = [];

        foreach ($this->taxonomies->relations() as $filterType => $config) {
            $relations[$filterType] = $title->{$config['relation']}
                ->pluck('id')
                ->map(fn ($id): int => (int) $id)
                ->unique()
                ->values()
                ->all();
        }

        return $relations;
    }

    private function bestRating(CatalogTitle $title): ?float
    {
        $rating = $title->ratings
            ->pluck('rating')
            ->filter(fn ($value): bool => $value !== null)
            ->map(fn ($value): float => (float) $value)
            ->max();

        return $rating !== null ? (float) $rating : null;
    }

    /**
     * @return array<string, int>
     */
    private function profileSignals(CatalogTitle $title): array
    {
        return $title->recommendationSignals
            ->filter(fn ($signal): bool => in_array($signal->signal_type, self::SOURCE_SIGNAL_TYPES, true))
            ->mapWithKeys(fn ($signal): array => [
                $signal->signal_type.':'.$signal->signal_key => (int) $signal->weight,
            ])
            ->all();
    }

    /** @return array<int, int> */
    private function profileProviderTargets(CatalogTitle $title): array
    {
        $maximumWeight = max(
            1,
            (int) config('recommendations.similarity_v6.provider_relation_score', 650),
        );

        return $title->outgoingRelations
            ->filter(fn ($relation): bool => (int) $relation->target_title_id !== (int) $title->id)
            ->sortBy([
                ['priority', 'asc'],
                ['id', 'asc'],
            ])
            ->unique('target_title_id')
            ->mapWithKeys(fn ($relation): array => [
                (int) $relation->target_title_id => $maximumWeight,
            ])
            ->all();
    }

    /**
     * @param  array{id: int, type: string, year: int|null, published_media_count: int, reviews_count: int, best_rating: float|null, signals: array<string, int>, provider_targets: array<int, int>, relations: array<string, list<int>>, themes: list<string>}  $source
     * @param  array<int, string>  $profiles
     * @return list<array<string, mixed>>
     */
    private function recommendationRows(array $source, array $profiles, int $minScore, int $maxPerTitle, Carbon $computedAt): array
    {
        $rows = [];

        foreach ($this->candidateIds($source) as $candidateId) {
            $encodedCandidate = $profiles[$candidateId] ?? null;

            if ($encodedCandidate === null) {
                continue;
            }

            $candidate = $this->decodeProfile($encodedCandidate);

            $scored = $this->score($source, $candidate, $minScore);

            if ($scored === null) {
                continue;
            }

            $rows[] = [
                'catalog_title_id' => $source['id'],
                'recommended_title_id' => $candidate['id'],
                'score' => $scored['score'],
                'algorithm_version' => $this->algorithmVersion(),
                'matched_features_count' => $scored['matched_features_count'],
                'metadata_score' => $scored['metadata_score'],
                'source_score' => $scored['source_score'],
                'quality_score' => $scored['quality_score'],
                'reasons' => $scored['reasons'],
                'diversity_features' => $scored['diversity_features'],
                'computed_at' => $computedAt,
            ];
        }

        return $this->rankRows($rows, $maxPerTitle);
    }

    /**
     * @param  array<string, list<array<string, mixed>>>  $rowsByDiversity
     * @param  array<string, mixed>  $row
     */
    private function retainDiversityCandidate(array &$rowsByDiversity, array $row, int $maxPerTitle): void
    {
        $key = (string) $row['diversity_key'];
        $rowsByDiversity[$key][] = $row;

        if (count($rowsByDiversity[$key]) <= $maxPerTitle) {
            return;
        }

        usort(
            $rowsByDiversity[$key],
            fn (array $left, array $right): int => (int) $right['score'] <=> (int) $left['score'],
        );
        array_pop($rowsByDiversity[$key]);
    }

    /** @return list<int> */
    private function candidateIds(array $source): array
    {
        return $this->candidateGenerator->idsFor(
            $source,
            max(
                1,
                (int) config(
                    'seasonvar.recommendations.candidate_limit',
                    config('recommendations.similarity_v6.candidate_limit', 240),
                ),
            ),
        );
    }

    /**
     * @return array{id: int, type: string, year: int|null, published_media_count: int, reviews_count: int, best_rating: float|null, signals: array<string, int>, provider_targets: array<int, int>, relations: array<string, list<int>>, themes: list<string>}
     */
    private function decodeProfile(string $profile): array
    {
        $decoded = json_decode($profile, true, flags: JSON_THROW_ON_ERROR);

        return [
            'id' => (int) $decoded[0],
            'type' => (string) $decoded[1],
            'year' => $decoded[2] !== null ? (int) $decoded[2] : null,
            'published_media_count' => (int) $decoded[3],
            'reviews_count' => (int) $decoded[4],
            'best_rating' => $decoded[5] !== null ? (float) $decoded[5] : null,
            'signals' => (array) $decoded[6],
            'provider_targets' => array_map('intval', (array) $decoded[7]),
            'relations' => array_combine($this->profileRelationTypes, (array) $decoded[8]),
            'themes' => array_values((array) $decoded[9]),
        ];
    }

    /**
     * @param  array{id: int, type: string, year: int|null, published_media_count: int, reviews_count: int, best_rating: float|null, signals: array<string, int>, provider_targets: array<int, int>, relations: array<string, list<int>>, themes: list<string>}  $profile
     */
    private function encodeProfile(array $profile): string
    {
        return json_encode([
            $profile['id'],
            $profile['type'],
            $profile['year'],
            $profile['published_media_count'],
            $profile['reviews_count'],
            $profile['best_rating'],
            $profile['signals'],
            $profile['provider_targets'],
            array_values($profile['relations']),
            $profile['themes'],
        ], JSON_THROW_ON_ERROR);
    }

    /** @return array{score: int, metadata_score: int, source_score: int, quality_score: int, matched_features_count: int, reasons: array<string, array<string, int|float|string>>, diversity_features: list<string>}|null */
    private function score(array $source, array $candidate, int $minScore): ?array
    {
        $documentFrequency = $this->featureDocumentCounts;
        $documentFrequency['theme'] = $this->themeDocumentCounts;
        $score = $this->pairScorer->score(
            $source,
            $candidate,
            $documentFrequency,
            $this->profileCount,
            $minScore,
        );

        if ($score === null) {
            return null;
        }

        return [
            'score' => $score->total(),
            'metadata_score' => $score->metadataScore,
            'source_score' => $score->sourceScore,
            'quality_score' => $score->qualityScore,
            'matched_features_count' => $score->matchedFeaturesCount,
            'reasons' => $score->reasons,
            'diversity_features' => $score->diversityFeatures,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function rankRows(array $rows, int $maxPerTitle): array
    {
        $remaining = $rows;
        $selected = [];
        $diversityPenalty = max(0, (int) config('seasonvar.recommendations.diversity_penalty', 120));

        while ($remaining !== [] && count($selected) < $maxPerTitle) {
            $bestIndex = null;
            $bestRankingScore = PHP_INT_MIN;
            $bestRawScore = PHP_INT_MIN;
            $bestTitleId = PHP_INT_MAX;

            foreach ($remaining as $index => $row) {
                $features = $this->rowDiversityFeatures($row);
                $maximumOverlap = 0.0;

                foreach ($selected as $selectedRow) {
                    $maximumOverlap = max(
                        $maximumOverlap,
                        $this->jaccardOverlap($features, $this->rowDiversityFeatures($selectedRow)),
                    );
                }

                $rawScore = (int) $row['score'];
                $rankingScore = $rawScore - (int) round($diversityPenalty * $maximumOverlap);
                $titleId = (int) ($row['recommended_title_id'] ?? PHP_INT_MAX);

                if (
                    $rankingScore > $bestRankingScore
                    || ($rankingScore === $bestRankingScore && $rawScore > $bestRawScore)
                    || ($rankingScore === $bestRankingScore && $rawScore === $bestRawScore && $titleId < $bestTitleId)
                ) {
                    $bestIndex = $index;
                    $bestRankingScore = $rankingScore;
                    $bestRawScore = $rawScore;
                    $bestTitleId = $titleId;
                }
            }

            if ($bestIndex === null) {
                break;
            }

            $selected[] = $remaining[$bestIndex];
            unset($remaining[$bestIndex]);
            $remaining = array_values($remaining);
        }

        $timestamp = now();

        return collect($selected)
            ->map(function (array $row, int $index) use ($timestamp): array {
                unset($row['diversity_features'], $row['diversity_key']);
                $row['rank'] = $index + 1;
                $row['reasons'] = json_encode($row['reasons'] ?? [], JSON_UNESCAPED_UNICODE);
                $row['created_at'] = $timestamp;
                $row['updated_at'] = $timestamp;

                return $row;
            })
            ->all();
    }

    /**
     * @param  array<string, mixed>  $row
     * @return list<string>
     */
    private function rowDiversityFeatures(array $row): array
    {
        $features = $row['diversity_features'] ?? [];

        if ($features === [] && isset($row['diversity_key'])) {
            $features = [(string) $row['diversity_key']];
        }

        return array_values(array_unique(array_map('strval', (array) $features)));
    }

    /**
     * @param  list<string>  $left
     * @param  list<string>  $right
     */
    private function jaccardOverlap(array $left, array $right): float
    {
        $union = array_unique([...$left, ...$right]);

        if ($union === []) {
            return 0.0;
        }

        return count(array_intersect($left, $right)) / count($union);
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array{stored: int, deleted: int}
     */
    private function storeBuildRecommendations(int $buildId, array $rows): array
    {
        $rows = collect($rows)
            ->map(function (array $row) use ($buildId): array {
                unset($row['algorithm_version']);
                $row['build_id'] = $buildId;

                return $row;
            })
            ->all();

        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('catalog_recommendation_build_rows')->insert($chunk);
        }

        return [
            'stored' => count($rows),
            'deleted' => 0,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array{stored: int, deleted: int}
     */
    private function replaceTitleRecommendations(int $titleId, array $rows): array
    {
        return DB::transaction(function () use ($titleId, $rows): array {
            $deleted = CatalogTitleRecommendation::query()
                ->where('catalog_title_id', $titleId)
                ->delete();

            if (! $this->titles->visibleTo(null)->whereKey($titleId)->exists()) {
                return [
                    'stored' => 0,
                    'deleted' => $deleted,
                ];
            }

            $visibleCandidateIds = $this->titles->visibleTo(null)
                ->whereKey(collect($rows)->pluck('recommended_title_id'))
                ->pluck('id')
                ->map(fn (mixed $id): int => (int) $id)
                ->all();
            $rows = collect($rows)
                ->filter(fn (array $row): bool => in_array((int) $row['recommended_title_id'], $visibleCandidateIds, true))
                ->values()
                ->map(function (array $row, int $index): array {
                    $row['rank'] = $index + 1;

                    return $row;
                })
                ->all();

            foreach (array_chunk($rows, 500) as $chunk) {
                CatalogTitleRecommendation::query()->insert($chunk);
            }

            return [
                'stored' => count($rows),
                'deleted' => $deleted,
            ];
        });
    }

    private function deleteOutOfScopeRecommendations(): int
    {
        return CatalogTitleRecommendation::query()
            ->where(function (Builder $query): void {
                $query
                    ->whereNotIn('catalog_title_id', $this->titles->visibleTo(null)->select('id'))
                    ->orWhereNotIn('recommended_title_id', $this->titles->visibleTo(null)->select('id'));
            })
            ->delete();
    }

    private function algorithmVersion(): string
    {
        $version = trim((string) config('recommendations.similarity_v6.algorithm_version', 'v6'));

        return $version !== '' ? $version : 'v6';
    }

    private function featureVersion(): string
    {
        $version = trim((string) config('recommendations.similarity_v6.feature_version', 'tokens-v2'));

        return $version !== '' ? $version : 'tokens-v2';
    }

    private function startShadowBuild(): ?CatalogRecommendationBuild
    {
        if (! (bool) config('recommendations.similarity_v6.shadow_enabled', true)
            || ! Schema::hasTable('catalog_recommendation_builds')
            || ! Schema::hasTable('catalog_recommendation_build_rows')) {
            return null;
        }

        return CatalogRecommendationBuild::query()->create([
            'algorithm_version' => $this->algorithmVersion(),
            'feature_version' => $this->featureVersion(),
            'status' => 'building',
            'started_at' => now(),
        ]);
    }

    private function failShadowBuild(?CatalogRecommendationBuild $build, Throwable $exception): void
    {
        if ($build === null) {
            return;
        }

        CatalogRecommendationBuild::query()
            ->whereKey($build->id)
            ->whereNotIn('status', ['active', 'failed'])
            ->update([
                'status' => 'failed',
                'failure_message' => Str::limit(
                    $exception::class.': '.$exception->getMessage(),
                    2_000,
                    '',
                ),
                'completed_at' => now(),
                'updated_at' => now(),
            ]);
    }

    /** @param (callable(string, array<string, mixed>): void)|null $progress */
    private function pruneShadowBuilds(?callable $progress, ?CatalogRecommendationBuild $build): int
    {
        if ($build === null) {
            return 0;
        }

        try {
            return $this->buildPruner->prune();
        } catch (Throwable $exception) {
            report($exception);
            $this->progress($progress, 'catalog-title-recommendation-build-prune-failed', [
                'build_id' => $build->id,
            ]);

            return 0;
        }
    }

    private function resetWorkingSet(): void
    {
        $this->candidateGenerator->reset();
        $this->featureDocumentCounts = [];
        $this->themeDocumentCounts = [];
        $this->profileCount = 0;
    }

    /**
     * @param  (callable(string, array<string, mixed>): void)|null  $progress
     * @param  array<string, mixed>  $context
     */
    private function progress(?callable $progress, string $event, array $context): void
    {
        if ($progress === null) {
            return;
        }

        $progress($event, $context);
    }
}
