<?php

namespace App\Services\Catalog;

use App\Models\CatalogTitle;
use App\Models\CatalogTitleRecommendation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class CatalogTitleRecommendationBuilder
{
    private const ALGORITHM_VERSION = 'v2';

    private const DEFAULT_MIN_SCORE = 600;

    /**
     * @var array<string, int>
     */
    private const MATCH_WEIGHTS = [
        'genre' => 520,
        'tag' => 280,
        'director' => 180,
        'actor' => 140,
        'network' => 120,
        'studio' => 120,
        'translation' => 80,
        'status' => 70,
        'country' => 55,
        'age_rating' => 25,
    ];

    /**
     * @var array<string, int>
     */
    private const MATCH_CAPS = [
        'genre' => 1560,
        'tag' => 840,
        'director' => 540,
        'actor' => 560,
        'network' => 360,
        'studio' => 360,
        'translation' => 160,
        'status' => 140,
        'country' => 110,
        'age_rating' => 50,
    ];

    public function __construct(
        private readonly CatalogTaxonomyRegistry $taxonomies,
    ) {}

    /**
     * @param  (callable(string, array<string, mixed>): void)|null  $progress
     * @return array{mode: string, algorithm_version: string, titles: int, titles_with_recommendations: int, titles_without_recommendations: int, stored: int, deleted: int, average_recommendations: float, min_score: int, max_per_title: int, duration_ms: int}
     */
    public function rebuild(?callable $progress = null): array
    {
        $startedAt = microtime(true);
        $chunkSize = max(10, (int) config('seasonvar.recommendations.chunk_size', 100));
        $minScore = max(1, (int) config('seasonvar.recommendations.min_score', self::DEFAULT_MIN_SCORE));
        $maxPerTitle = max(1, (int) config('seasonvar.recommendations.max_per_title', 12));
        $computedAt = now();
        $profiles = $this->profiles($chunkSize);
        $featureMap = $this->featureMap($profiles);
        $stored = 0;
        $deleted = $this->deleteOutOfScopeRecommendations();
        $titlesWithRecommendations = 0;

        $this->progress($progress, 'catalog-title-recommendations-started', [
            'titles' => $profiles->count(),
            'chunk_size' => $chunkSize,
            'min_score' => $minScore,
            'max_per_title' => $maxPerTitle,
        ]);

        foreach ($profiles->chunk($chunkSize) as $chunk) {
            foreach ($chunk as $profile) {
                $rows = $this->recommendationRows($profile, $profiles, $featureMap, $minScore, $maxPerTitle, $computedAt);
                $result = $this->replaceTitleRecommendations(
                    $profile['id'],
                    $rows,
                );

                $stored += $result['stored'];
                $deleted += $result['deleted'];

                if ($rows !== []) {
                    $titlesWithRecommendations++;
                }
            }

            $this->progress($progress, 'catalog-title-recommendations-chunk-complete', [
                'processed' => $chunk->count(),
                'stored' => $stored,
                'deleted' => $deleted,
            ]);
        }

        $titleCount = $profiles->count();
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

        return $result;
    }

    /**
     * @return Collection<int, array{
     *     id: int,
     *     type: string,
     *     year: int|null,
     *     published_media_count: int,
     *     reviews_count: int,
     *     best_rating: float|null,
     *     signal_score: int,
     *     signals: array<string, int>,
     *     relations: array<string, list<int>>
     * }>
     */
    private function profiles(int $chunkSize): Collection
    {
        $profiles = collect();

        CatalogTitle::query()
            ->select(['id', 'type', 'year', 'is_published'])
            ->where('is_published', true)
            ->with(array_merge($this->taxonomies->relationNames(), [
                'ratings:id,catalog_title_id,rating',
                'recommendationSignals' => fn ($query) => $query->positive(),
            ]))
            ->withCount([
                'reviews',
                'licensedMedia as published_media_count' => fn (Builder $query): Builder => $query->published(),
            ])
            ->lazyById($chunkSize)
            ->each(function (CatalogTitle $title) use ($profiles): void {
                $profiles->put($title->id, [
                    'id' => (int) $title->id,
                    'type' => (string) $title->type,
                    'year' => $title->year !== null ? (int) $title->year : null,
                    'published_media_count' => (int) $title->published_media_count,
                    'reviews_count' => (int) $title->reviews_count,
                    'best_rating' => $this->bestRating($title),
                    'signal_score' => $this->signalScore($title),
                    'signals' => $this->profileSignals($title),
                    'relations' => $this->profileRelations($title),
                ]);
            });

        return $profiles;
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
            ->mapWithKeys(fn ($signal): array => [
                $signal->signal_type.':'.$signal->signal_key => (int) $signal->weight,
            ])
            ->all();
    }

    private function signalScore(CatalogTitle $title): int
    {
        return (int) $title->recommendationSignals
            ->sum(fn ($signal): int => max(0, (int) $signal->weight));
    }

    /**
     * @param  Collection<int, array{id: int, relations: array<string, list<int>>}>  $profiles
     * @return array<string, array<int, list<int>>>
     */
    private function featureMap(Collection $profiles): array
    {
        $map = [];

        foreach ($profiles as $profile) {
            foreach ($profile['relations'] as $filterType => $ids) {
                foreach ($ids as $id) {
                    $map[$filterType][$id][] = $profile['id'];
                }
            }
        }

        return $map;
    }

    /**
     * @param  array{id: int, type: string, year: int|null, published_media_count: int, reviews_count: int, best_rating: float|null, signal_score: int, signals: array<string, int>, relations: array<string, list<int>>}  $source
     * @param  Collection<int, array{id: int, type: string, year: int|null, published_media_count: int, reviews_count: int, best_rating: float|null, signal_score: int, signals: array<string, int>, relations: array<string, list<int>>}>  $profiles
     * @param  array<string, array<int, list<int>>>  $featureMap
     * @return list<array<string, mixed>>
     */
    private function recommendationRows(array $source, Collection $profiles, array $featureMap, int $minScore, int $maxPerTitle, Carbon $computedAt): array
    {
        $rows = [];

        foreach ($this->candidateIds($source, $featureMap) as $candidateId) {
            $candidate = $profiles->get($candidateId);

            if ($candidate === null || $candidate['id'] === $source['id']) {
                continue;
            }

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
                'diversity_key' => $scored['diversity_key'],
                'computed_at' => $computedAt,
            ];
        }

        return $this->rankRows($rows, $maxPerTitle);
    }

    /**
     * @param  array{id: int, relations: array<string, list<int>>}  $source
     * @param  array<string, array<int, list<int>>>  $featureMap
     * @return list<int>
     */
    private function candidateIds(array $source, array $featureMap): array
    {
        $candidateIds = [];

        foreach ($source['relations'] as $filterType => $ids) {
            foreach ($ids as $id) {
                foreach ($featureMap[$filterType][$id] ?? [] as $candidateId) {
                    $candidateIds[$candidateId] = true;
                }
            }
        }

        unset($candidateIds[$source['id']]);

        return array_keys($candidateIds);
    }

    /**
     * @param  array{type: string, year: int|null, signals: array<string, int>, relations: array<string, list<int>>}  $source
     * @param  array{id: int, type: string, year: int|null, published_media_count: int, reviews_count: int, best_rating: float|null, signal_score: int, signals: array<string, int>, relations: array<string, list<int>>}  $candidate
     * @return array{score: int, metadata_score: int, source_score: int, quality_score: int, matched_features_count: int, reasons: array<string, array<string, int|float|string>>, diversity_key: string}|null
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
        $diversityKey = 'other';

        foreach (self::MATCH_WEIGHTS as $filterType => $weight) {
            $shared = array_values(array_intersect(
                $source['relations'][$filterType] ?? [],
                $candidate['relations'][$filterType] ?? [],
            ));
            $sharedCount = count($shared);

            if ($sharedCount === 0) {
                continue;
            }

            $reasonScore = min($sharedCount * $weight, self::MATCH_CAPS[$filterType] ?? $sharedCount * $weight);
            $metadataScore += $reasonScore;
            $reasons[$filterType] = [
                'count' => $sharedCount,
                'score' => $reasonScore,
            ];

            if ($diversityKey === 'other' && in_array($filterType, ['genre', 'tag', 'director', 'actor', 'network', 'studio'], true)) {
                $diversityKey = $filterType.':'.$shared[0];
            }
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

        $sourceScore += $this->sourceSignalScore($source['signals'], $candidate['signals'], $candidate['signal_score'], $reasons);

        $mediaScore = 100 + min(100, (int) floor(log($candidate['published_media_count'] + 1) * 35));
        $qualityScore += $mediaScore;
        $reasons['published_media'] = [
            'count' => $candidate['published_media_count'],
            'score' => $mediaScore,
        ];

        if ($candidate['best_rating'] !== null) {
            $ratingScore = (int) round(min(10.0, max(0.0, $candidate['best_rating'])) * 7);
            $qualityScore += $ratingScore;
            $reasons['rating'] = [
                'value' => $candidate['best_rating'],
                'score' => $ratingScore,
            ];
        }

        if ($candidate['reviews_count'] > 0) {
            $reviewScore = min(60, (int) floor(log($candidate['reviews_count'] + 1) * 20));
            $qualityScore += $reviewScore;
            $reasons['reviews'] = [
                'count' => $candidate['reviews_count'],
                'score' => $reviewScore,
            ];
        }

        $score = $metadataScore + $sourceScore + $qualityScore;

        if ($score < $minScore) {
            return null;
        }

        return [
            'score' => $score,
            'metadata_score' => $metadataScore,
            'source_score' => $sourceScore,
            'quality_score' => $qualityScore,
            'matched_features_count' => count(array_diff(array_keys($reasons), ['type', 'published_media'])),
            'reasons' => $reasons,
            'diversity_key' => $diversityKey,
        ];
    }

    /**
     * @param  array<string, int>  $sourceSignals
     * @param  array<string, int>  $candidateSignals
     * @param  array<string, array<string, int|float|string>>  $reasons
     */
    private function sourceSignalScore(array $sourceSignals, array $candidateSignals, int $candidateSignalScore, array &$reasons): int
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

        $score = min(220, (int) floor($sharedScore / 2)) + min(120, (int) floor($candidateSignalScore / 4));

        if ($score > 0) {
            $reasons['source_signal'] = [
                'count' => $sharedCount,
                'score' => $score,
            ];
        }

        return $score;
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
        $diversityUsage = [];

        return collect($rows)
            ->sortByDesc('score')
            ->values()
            ->map(function (array $row) use (&$diversityUsage): array {
                $key = (string) $row['diversity_key'];
                $usage = $diversityUsage[$key] ?? 0;
                $diversityUsage[$key] = $usage + 1;
                $row['ranking_score'] = (int) $row['score'] - ($usage * 40);

                return $row;
            })
            ->sortByDesc('ranking_score')
            ->values()
            ->take($maxPerTitle)
            ->map(function (array $row, int $index): array {
                unset($row['diversity_key'], $row['ranking_score']);
                $row['rank'] = $index + 1;
                $row['reasons'] = json_encode($row['reasons'], JSON_UNESCAPED_UNICODE);
                $row['created_at'] = now();
                $row['updated_at'] = now();

                return $row;
            })
            ->all();
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
                    ->whereNotIn('catalog_title_id', CatalogTitle::query()->select('id')->where('is_published', true))
                    ->orWhereNotIn('recommended_title_id', CatalogTitle::query()->select('id')->where('is_published', true));
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
