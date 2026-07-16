<?php

namespace App\Services\Catalog;

use App\Enums\ReviewStatus;
use App\Models\CatalogTitle;
use App\Models\CatalogTitleRecommendation;
use App\Models\Tag;
use App\Services\Reviews\ReviewSchema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

final class CatalogTitleRecommendationBuilder
{
    private const ALGORITHM_VERSION = 'v5';

    private const DEFAULT_MIN_SCORE = 600;

    private const MAX_PROFILE_CHUNK_SIZE = 100;

    /**
     * Broad attributes remain part of exact scoring, but they are too common to
     * be useful as the only candidate seed on a large catalog.
     *
     * @var list<string>
     */
    private const CANDIDATE_FEATURE_TYPES = [
        'genre',
        'tag',
        'director',
        'actor',
        'network',
        'studio',
    ];

    /**
     * @var array<string, int>
     */
    private const MATCH_WEIGHTS = [
        'genre' => 180,
        'tag' => 220,
        'director' => 280,
        'actor' => 130,
        'network' => 200,
        'studio' => 200,
        'translation' => 45,
        'status' => 35,
        'country' => 80,
        'age_rating' => 20,
    ];

    /**
     * @var array<string, int>
     */
    private const THEME_WEIGHTS = [
        'romance' => 260,
        'relationships' => 240,
        'friendship' => 160,
        'family' => 150,
        'youth' => 100,
    ];

    private const DEFAULT_THEME_WEIGHT = 180;

    /** @var list<string> */
    private const SOURCE_SIGNAL_TYPES = [
        'provider_recommendation',
        'related_title',
    ];

    /** @var list<string> */
    private const STRONG_RELATION_TYPES = [
        'tag',
        'director',
        'network',
        'studio',
    ];

    /** @var array<string, array<int, string>> */
    private array $candidateFeatureMap = [];

    /** @var array<string, string> */
    private array $candidateThemeMap = [];

    /** @var array<string, array<int, int>> */
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
    ) {}

    /**
     * @param  (callable(string, array<string, mixed>): void)|null  $progress
     * @return array{mode: string, algorithm_version: string, titles: int, titles_with_recommendations: int, titles_without_recommendations: int, stored: int, deleted: int, average_recommendations: float, min_score: int, max_per_title: int, duration_ms: int}
     */
    public function rebuild(?callable $progress = null): array
    {
        try {
            return $this->performRebuild($progress);
        } finally {
            $this->candidateFeatureMap = [];
            $this->candidateThemeMap = [];
            $this->featureDocumentCounts = [];
            $this->themeDocumentCounts = [];
            $this->profileCount = 0;
        }
    }

    /**
     * @param  (callable(string, array<string, mixed>): void)|null  $progress
     * @return array{mode: string, algorithm_version: string, titles: int, titles_with_recommendations: int, titles_without_recommendations: int, stored: int, deleted: int, average_recommendations: float, min_score: int, max_per_title: int, duration_ms: int}
     */
    private function performRebuild(?callable $progress): array
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
        $deleted = $this->deleteOutOfScopeRecommendations();
        $titlesWithRecommendations = 0;

        $this->progress($progress, 'catalog-title-recommendations-started', [
            'titles' => count($profiles),
            'chunk_size' => $chunkSize,
            'min_score' => $minScore,
            'max_per_title' => $maxPerTitle,
        ]);

        $processedInChunk = 0;

        foreach ($profiles as $encodedProfile) {
            $profile = $this->decodeProfile($encodedProfile);
            $rows = $this->recommendationRows($profile, $profiles, $minScore, $maxPerTitle, $computedAt);
            $result = $this->replaceTitleRecommendations(
                $profile['id'],
                $rows,
            );

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

        $titleCount = count($profiles);
        $result = [
            'mode' => 'full',
            'algorithm_version' => self::ALGORITHM_VERSION,
            'titles' => $titleCount,
            'titles_with_recommendations' => $titlesWithRecommendations,
            'titles_without_recommendations' => max(0, $titleCount - $titlesWithRecommendations),
            'stored' => $stored,
            'deleted' => $deleted,
            'average_recommendations' => $titleCount > 0 ? round($stored / $titleCount, 2) : 0.0,
            'min_score' => $minScore,
            'max_per_title' => $maxPerTitle,
            'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
        ];

        $this->progress($progress, 'catalog-title-recommendations-complete', $result);
        $this->cacheInvalidator->publicSignalsChanged('recommendation-rebuild');

        return $result;
    }

    /** @return array<int, string> */
    private function compactProfileIndex(int $chunkSize): array
    {
        $profiles = [];
        $featureMap = $this->sharedCandidateFeatureMap();
        $themeMap = [];
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
                    ->whereIn('signal_type', self::SOURCE_SIGNAL_TYPES),
            ]))
            ->withCount([
                ...$reviewCount,
                'licensedMedia as published_media_count' => $publicCounts['licensedMedia as published_media_count'],
            ])
            ->lazyById($chunkSize)
            ->each(function (CatalogTitle $title) use (&$featureMap, &$profiles, &$themeMap): void {
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
                    'relations' => $relations,
                    'themes' => $themes,
                ];
                $profiles[$title->id] = $this->encodeProfile($profile);

                foreach ($profile['relations'] as $filterType => $ids) {
                    foreach ($ids as $id) {
                        $this->featureDocumentCounts[$filterType][$id] = ($this->featureDocumentCounts[$filterType][$id] ?? 0) + 1;
                    }

                    if (! in_array($filterType, self::CANDIDATE_FEATURE_TYPES, true)) {
                        continue;
                    }

                    foreach ($ids as $id) {
                        if (isset($featureMap[$filterType][$id])) {
                            $featureMap[$filterType][$id] .= pack('V', $profile['id']);
                        }
                    }
                }

                foreach ($themes as $theme) {
                    $this->themeDocumentCounts[$theme] = ($this->themeDocumentCounts[$theme] ?? 0) + 1;
                    $this->appendPackedTitleId($themeMap, 'theme:'.$theme, $profile['id']);

                    foreach ($relations['country'] ?? [] as $countryId) {
                        $this->appendPackedTitleId($themeMap, 'theme:'.$theme.'|country:'.$countryId, $profile['id']);
                    }

                    foreach ($relations['genre'] ?? [] as $genreId) {
                        $this->appendPackedTitleId($themeMap, 'theme:'.$theme.'|genre:'.$genreId, $profile['id']);
                    }
                }
            });

        $this->candidateFeatureMap = $featureMap;
        $this->candidateThemeMap = $themeMap;
        $this->profileCount = count($profiles);

        return $profiles;
    }

    /**
     * @param  array<string, string>  $map
     */
    private function appendPackedTitleId(array &$map, string $key, int $titleId): void
    {
        $map[$key] = ($map[$key] ?? '').pack('V', $titleId);
    }

    /**
     * @return array<string, array<int, string>>
     */
    private function sharedCandidateFeatureMap(): array
    {
        $featureMap = [];
        $title = new CatalogTitle;

        foreach (self::CANDIDATE_FEATURE_TYPES as $filterType) {
            $relationName = $this->taxonomies->relationName($filterType);
            $relation = $title->{$relationName}();

            if (! $relation instanceof BelongsToMany) {
                continue;
            }

            $foreignKey = $relation->getQualifiedForeignPivotKeyName();
            $relatedKey = $relation->getQualifiedRelatedPivotKeyName();
            $featureIds = DB::table($relation->getTable())
                ->select($relatedKey)
                ->whereIn($foreignKey, $this->titles->visibleTo(null)->select('id'))
                ->when(
                    $filterType === 'tag',
                    fn ($query) => $query->whereIn($relatedKey, Tag::query()->publiclyEligible()->select('tags.id')),
                )
                ->groupBy($relatedKey)
                ->havingRaw('count(distinct '.$foreignKey.') > 1')
                ->pluck($relatedKey)
                ->map(fn (mixed $id): int => (int) $id)
                ->all();

            if ($featureIds !== []) {
                $featureMap[$filterType] = array_fill_keys($featureIds, '');
            }
        }

        return $featureMap;
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

    /**
     * @param  array{id: int, type: string, year: int|null, published_media_count: int, reviews_count: int, best_rating: float|null, signals: array<string, int>, relations: array<string, list<int>>, themes: list<string>}  $source
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
                'algorithm_version' => self::ALGORITHM_VERSION,
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

    /**
     * @param  array{id: int, relations: array<string, list<int>>, themes: list<string>}  $source
     * @return list<int>
     */
    private function candidateIds(array $source): array
    {
        $candidateLimit = max(1, (int) config('seasonvar.recommendations.candidate_limit', 120));
        $scanPerFeature = max(1, (int) config('seasonvar.recommendations.candidate_scan_per_feature', 60));
        $candidateScores = [];
        $retainedPoolSize = $candidateLimit * 4;

        foreach (self::CANDIDATE_FEATURE_TYPES as $filterType) {
            foreach ($source['relations'][$filterType] ?? [] as $featureId) {
                $packedTitleIds = $this->candidateFeatureMap[$filterType][$featureId] ?? null;

                if ($packedTitleIds === null) {
                    continue;
                }

                foreach ($this->sampledFeatureTitleIds(
                    $packedTitleIds,
                    $source['id'],
                    $filterType,
                    $featureId,
                    $scanPerFeature,
                ) as $candidateId) {
                    if ($candidateId === $source['id']) {
                        continue;
                    }

                    $candidateScores[$candidateId] = ($candidateScores[$candidateId] ?? 0)
                        + self::MATCH_WEIGHTS[$filterType];
                }

                if (count($candidateScores) > $retainedPoolSize * 2) {
                    arsort($candidateScores, SORT_NUMERIC);
                    $candidateScores = array_slice($candidateScores, 0, $retainedPoolSize, true);
                }
            }
        }

        foreach ($source['themes'] as $theme) {
            $this->accumulateThemeCandidates(
                $candidateScores,
                $source['id'],
                'theme:'.$theme,
                220,
                $scanPerFeature,
            );

            foreach ($source['relations']['country'] ?? [] as $countryId) {
                $this->accumulateThemeCandidates(
                    $candidateScores,
                    $source['id'],
                    'theme:'.$theme.'|country:'.$countryId,
                    340,
                    $scanPerFeature,
                );
            }

            foreach ($source['relations']['genre'] ?? [] as $genreId) {
                $this->accumulateThemeCandidates(
                    $candidateScores,
                    $source['id'],
                    'theme:'.$theme.'|genre:'.$genreId,
                    380,
                    $scanPerFeature,
                );
            }

            if (count($candidateScores) > $retainedPoolSize * 2) {
                arsort($candidateScores, SORT_NUMERIC);
                $candidateScores = array_slice($candidateScores, 0, $retainedPoolSize, true);
            }
        }

        arsort($candidateScores, SORT_NUMERIC);

        return array_map(
            static fn (int|string $id): int => (int) $id,
            array_keys(array_slice($candidateScores, 0, $candidateLimit, true)),
        );
    }

    /**
     * @param  array<int, int>  $candidateScores
     */
    private function accumulateThemeCandidates(
        array &$candidateScores,
        int $sourceId,
        string $key,
        int $weight,
        int $limit,
    ): void {
        $packedTitleIds = $this->candidateThemeMap[$key] ?? null;

        if ($packedTitleIds === null) {
            return;
        }

        foreach ($this->sampledFeatureTitleIds($packedTitleIds, $sourceId, 'theme', $key, $limit) as $candidateId) {
            if ($candidateId !== $sourceId) {
                $candidateScores[$candidateId] = ($candidateScores[$candidateId] ?? 0) + $weight;
            }
        }
    }

    /** @return iterable<int> */
    private function sampledFeatureTitleIds(
        string $packedTitleIds,
        int $sourceId,
        string $filterType,
        int|string $featureId,
        int $limit,
    ): iterable {
        $titleCount = intdiv(strlen($packedTitleIds), 4);
        $selected = min($titleCount, $limit);
        $start = $titleCount > $selected
            ? (int) (sprintf('%u', crc32($sourceId.':'.$filterType.':'.$featureId)) % $titleCount)
            : 0;

        for ($index = 0; $index < $selected; $index++) {
            $offset = (($start + $index) % $titleCount) * 4;
            $unpacked = unpack('Vtitle_id', $packedTitleIds, $offset);

            if (is_array($unpacked)) {
                yield (int) $unpacked['title_id'];
            }
        }
    }

    /**
     * @return array{id: int, type: string, year: int|null, published_media_count: int, reviews_count: int, best_rating: float|null, signals: array<string, int>, relations: array<string, list<int>>, themes: list<string>}
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
            'relations' => array_combine($this->profileRelationTypes, (array) $decoded[7]),
            'themes' => array_values((array) $decoded[8]),
        ];
    }

    /**
     * @param  array{id: int, type: string, year: int|null, published_media_count: int, reviews_count: int, best_rating: float|null, signals: array<string, int>, relations: array<string, list<int>>, themes: list<string>}  $profile
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
            array_values($profile['relations']),
            $profile['themes'],
        ], JSON_THROW_ON_ERROR);
    }

    /**
     * @param  array{type: string, year: int|null, signals: array<string, int>, relations: array<string, list<int>>, themes: list<string>}  $source
     * @param  array{id: int, type: string, year: int|null, published_media_count: int, reviews_count: int, best_rating: float|null, signals: array<string, int>, relations: array<string, list<int>>, themes: list<string>}  $candidate
     * @return array{score: int, metadata_score: int, source_score: int, quality_score: int, matched_features_count: int, reasons: array<string, array<string, int|float|string>>, diversity_features: list<string>}|null
     */
    private function score(array $source, array $candidate, int $minScore): ?array
    {
        if ($candidate['published_media_count'] <= 0) {
            return null;
        }

        $metadataScore = 0;
        $sourceScore = 0;
        $qualityScore = 0;
        $reasons = [];
        $diversityFeatures = [];
        $hasStrongMatch = false;

        foreach (self::MATCH_WEIGHTS as $filterType => $weight) {
            $shared = array_values(array_intersect(
                $source['relations'][$filterType] ?? [],
                $candidate['relations'][$filterType] ?? [],
            ));
            $sharedCount = count($shared);

            if ($sharedCount === 0) {
                continue;
            }

            $contributions = array_map(
                fn (int $id): int => (int) round(
                    $weight * $this->rarityMultiplier($this->featureDocumentCounts[$filterType][$id] ?? 1),
                ),
                $shared,
            );
            rsort($contributions, SORT_NUMERIC);
            $reasonScore = array_sum(array_slice($contributions, 0, $filterType === 'actor' ? 2 : 3));
            $metadataScore += $reasonScore;
            $reasons[$filterType] = [
                'count' => $sharedCount,
                'score' => $reasonScore,
            ];

            if (in_array($filterType, self::CANDIDATE_FEATURE_TYPES, true)) {
                foreach ($shared as $id) {
                    $diversityFeatures[] = $filterType.':'.$id;
                }
            }

            if (in_array($filterType, self::STRONG_RELATION_TYPES, true)
                || ($filterType === 'actor' && $sharedCount >= 2)) {
                $hasStrongMatch = true;
            }
        }

        foreach (array_values(array_intersect($source['themes'], $candidate['themes'])) as $theme) {
            $reasonScore = (int) round(
                (self::THEME_WEIGHTS[$theme] ?? self::DEFAULT_THEME_WEIGHT)
                * $this->rarityMultiplier($this->themeDocumentCounts[$theme] ?? 1),
            );
            $metadataScore += $reasonScore;
            $reasons['theme_'.$theme] = ['score' => $reasonScore];
            $diversityFeatures[] = 'theme:'.$theme;
            $hasStrongMatch = true;
        }

        if ($source['type'] !== '' && $source['type'] === $candidate['type']) {
            $metadataScore += 45;
            $reasons['type'] = ['score' => 45];
        }

        $yearScore = $this->yearScore($source['year'], $candidate['year']);

        if ($yearScore > 0) {
            $metadataScore += $yearScore;
            $reasons['year'] = ['score' => $yearScore];
        }

        $sourceScore += $this->sourceSignalScore($source['signals'], $candidate['signals'], $reasons);

        if (! $hasStrongMatch || ($metadataScore + $sourceScore) < $minScore) {
            return null;
        }

        $mediaScore = 60 + min(40, (int) floor(log($candidate['published_media_count'] + 1) * 15));
        $qualityScore += $mediaScore;
        $reasons['published_media'] = [
            'count' => $candidate['published_media_count'],
            'score' => $mediaScore,
        ];

        if ($candidate['best_rating'] !== null) {
            $ratingScore = (int) round(min(10.0, max(0.0, $candidate['best_rating'])) * 5);
            $qualityScore += $ratingScore;
            $reasons['rating'] = [
                'value' => $candidate['best_rating'],
                'score' => $ratingScore,
            ];
        }

        if ($candidate['reviews_count'] > 0) {
            $reviewScore = min(30, (int) floor(log($candidate['reviews_count'] + 1) * 10));
            $qualityScore += $reviewScore;
            $reasons['reviews'] = [
                'count' => $candidate['reviews_count'],
                'score' => $reviewScore,
            ];
        }

        $score = $metadataScore + $sourceScore + $qualityScore;

        return [
            'score' => $score,
            'metadata_score' => $metadataScore,
            'source_score' => $sourceScore,
            'quality_score' => $qualityScore,
            'matched_features_count' => count(array_diff(array_keys($reasons), ['type', 'published_media', 'rating', 'reviews'])),
            'reasons' => $reasons,
            'diversity_features' => array_values(array_unique($diversityFeatures)),
        ];
    }

    /**
     * @param  array<string, int>  $sourceSignals
     * @param  array<string, int>  $candidateSignals
     * @param  array<string, array<string, int|float|string>>  $reasons
     */
    private function sourceSignalScore(array $sourceSignals, array $candidateSignals, array &$reasons): int
    {
        $sharedScore = 0;
        $sharedCount = 0;

        foreach ($sourceSignals as $key => $sourceWeight) {
            $candidateWeight = $candidateSignals[$key] ?? null;

            if ($candidateWeight === null) {
                continue;
            }

            $sharedScore += min((int) $sourceWeight, (int) $candidateWeight);
            $sharedCount++;
        }

        $score = min(220, (int) floor($sharedScore / 2));

        if ($score > 0) {
            $reasons['source_signal'] = [
                'count' => $sharedCount,
                'score' => $score,
            ];
        }

        return $score;
    }

    private function rarityMultiplier(int $documentCount): float
    {
        if ($this->profileCount <= 1) {
            return 1.0;
        }

        $catalogToDocumentRatio = $this->profileCount / max(1, $documentCount);

        return 1.0 + min(1.5, log10(1.0 + $catalogToDocumentRatio));
    }

    private function yearScore(?int $sourceYear, ?int $candidateYear): int
    {
        if ($sourceYear === null || $candidateYear === null) {
            return 0;
        }

        $diff = abs($sourceYear - $candidateYear);

        return match (true) {
            $diff === 0 => 90,
            $diff === 1 => 45,
            $diff === 2 => 25,
            default => 0,
        };
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
