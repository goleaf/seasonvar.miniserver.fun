<?php

declare(strict_types=1);

namespace App\Services\Catalog;

use App\DTOs\CatalogRecommendationContext;
use App\DTOs\CatalogRecommendationExplanation;
use App\DTOs\CatalogRecommendationItem;
use App\DTOs\CatalogRecommendationResult;
use App\Enums\CatalogPersonalizationConfidence;
use App\Enums\CatalogRecommendationReason;
use App\Enums\CatalogRecommendationSource;
use App\Enums\CatalogRecommendationType;
use App\Enums\CatalogTitleRelationSource;
use App\Models\CatalogTitle;
use App\Models\CatalogTitleRecommendation;
use App\Models\CatalogTitleRelation;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

final class CatalogRecommendationService
{
    public function __construct(
        private readonly CatalogPublicDiscoveryQuery $public,
        private readonly CatalogPersonalizedRecommendationQuery $personalized,
        private readonly CatalogRecommendationExclusionService $exclusions,
        private readonly CatalogRecommendationAvailabilityReranker $availability,
        private readonly CatalogRecommendationDiversityService $diversity,
        private readonly CatalogRecommendationCache $cache,
        private readonly CatalogRecommendationTitleLoader $loader,
        private readonly CatalogRecommendationVisibilityService $visibility,
        private readonly CatalogTitleRelationService $relations,
        private readonly CatalogRecommendationRepeatSuppressor $repeats,
        private readonly CatalogRecommendationPresenter $presenter,
        private readonly CatalogRecommendationExplorationMixer $exploration,
        private readonly CatalogPersonalizationRollout $personalizationRollout,
    ) {}

    public function discover(CatalogRecommendationContext $context): CatalogRecommendationResult
    {
        $baseHardExclusions = $this->exclusions->hardExclusions($context);
        $hardExclusions = $context->seed !== null
            ? $this->exclusions->hardExclusions($context, includeRecent: true)
            : $baseHardExclusions;
        $coldStart = false;
        $personalized = false;
        $displayType = $context->type;
        $personalizationConfidence = null;
        $preserveBlendOrder = false;

        if ($context->type === CatalogRecommendationType::Personalized) {
            $discoveryDemotions = $this->exclusions->discoveryDemotions($context);
            $personalizedExclusions = array_values(array_unique([
                ...$hardExclusions,
                ...$discoveryDemotions,
            ]));
            if ($context->user !== null && $this->personalizationRollout->enabledFor($context->user)) {
                $set = $this->personalized->candidateSet($context, $personalizedExclusions);

                if ($set->candidates === [] && $hardExclusions !== $baseHardExclusions) {
                    $set = $this->personalized->candidateSet($context, array_values(array_unique([
                        ...$baseHardExclusions,
                        ...$discoveryDemotions,
                    ])));
                }

                $personalizationConfidence = $set->confidence;
                $fallbackExclusions = array_values(array_unique([
                    ...$personalizedExclusions,
                    ...$set->sourceTitleIds,
                    ...array_column($set->candidates, 'id'),
                ]));
                $publicCandidates = $this->coldStartCandidates($context, $fallbackExclusions);

                if ($publicCandidates === [] && $hardExclusions !== $baseHardExclusions) {
                    $publicCandidates = $this->coldStartCandidates($context, array_values(array_unique([
                        ...$baseHardExclusions,
                        ...$discoveryDemotions,
                        ...$set->sourceTitleIds,
                        ...array_column($set->candidates, 'id'),
                    ])));
                }

                $pageSize = $context->boundedPerPage();
                $personalPerPage = match ($set->confidence) {
                    CatalogPersonalizationConfidence::Cold => 0,
                    CatalogPersonalizationConfidence::Low => (int) floor($pageSize * 0.25),
                    CatalogPersonalizationConfidence::Medium => (int) floor($pageSize * 0.60),
                    CatalogPersonalizationConfidence::High => $pageSize,
                };
                $personalLimit = min(count($set->candidates), $personalPerPage * $context->boundedPage());
                $through = min(
                    max(24, min(500, (int) config('recommendations.candidate_limit', 180))),
                    ($context->boundedPage() * $pageSize) + 1,
                );
                $personalRows = array_slice($set->candidates, 0, $personalLimit);
                $candidates = $this->blendPersonalCandidates(
                    $personalRows,
                    $publicCandidates,
                    $through,
                    $context->boundedPage() * $pageSize,
                );
                $candidates = $this->exploration->mix(
                    $candidates,
                    array_slice($set->candidates, $personalLimit),
                    $through,
                    $context->seed ?? (string) config('recommendations.personalized_v2.rollout_seed', 'personalized-v2')
                        .'|'.$context->locale.'|'.$context->boundedPage(),
                );
                $personalized = $personalRows !== [];
                $coldStart = $set->confidence === CatalogPersonalizationConfidence::Cold || ! $personalized;
                $displayType = $set->confidence === CatalogPersonalizationConfidence::High
                    && count($personalRows) >= $pageSize
                    ? CatalogRecommendationType::Personalized
                    : $this->fallbackDisplayType($publicCandidates);
                $preserveBlendOrder = true;
            } else {
                $candidates = $context->user !== null
                    ? $this->personalized->candidates($context, $personalizedExclusions)
                    : [];
                $personalized = $candidates !== [];

                if ($candidates === [] && $hardExclusions !== $baseHardExclusions && $context->user !== null) {
                    $candidates = $this->personalized->candidates($context, array_values(array_unique([
                        ...$baseHardExclusions,
                        ...$discoveryDemotions,
                    ])));
                    $personalized = $candidates !== [];
                }

                if ($candidates === []) {
                    $coldStart = true;
                    $candidates = $this->coldStartCandidates($context, $personalizedExclusions);

                    if ($candidates === [] && $hardExclusions !== $baseHardExclusions) {
                        $candidates = $this->coldStartCandidates($context, array_values(array_unique([
                            ...$baseHardExclusions,
                            ...$discoveryDemotions,
                        ])));
                    }

                    $displayType = $this->fallbackDisplayType($candidates);
                }
            }
        } else {
            $candidates = $this->cache->rememberPublic(
                $context,
                fn (): array => $this->public->candidates($context, $hardExclusions),
                $hardExclusions,
            );

            if ($candidates === [] && $hardExclusions !== $baseHardExclusions) {
                $candidates = $this->cache->rememberPublic(
                    $context,
                    fn (): array => $this->public->candidates($context, $baseHardExclusions),
                    $baseHardExclusions,
                );
            }
        }

        $candidates = $this->exclusions->applySoftDemotions($context, $candidates);
        $candidates = $this->availability->rerank($context, $candidates);

        if ($preserveBlendOrder) {
            usort($candidates, static fn (array $left, array $right): int => (($left['blend_position'] ?? PHP_INT_MAX) <=> ($right['blend_position'] ?? PHP_INT_MAX))
                ?: ($right['score'] <=> $left['score'])
                ?: ($right['id'] <=> $left['id']));
        }

        return $this->result(
            context: $context,
            candidates: $candidates,
            displayType: $displayType,
            personalized: $personalized,
            coldStart: $coldStart,
            watchable: $context->type !== CatalogRecommendationType::Upcoming,
            personalizationConfidence: $personalizationConfidence,
        );
    }

    /**
     * @return array{related: Collection<int, CatalogRecommendationItem>, similar: Collection<int, CatalogRecommendationItem>}
     */
    public function forTitle(CatalogTitle $title, ?User $user, int $limit = 12): array
    {
        $limit = max(1, min(24, $limit));
        $relatedContext = new CatalogRecommendationContext(
            type: CatalogRecommendationType::Related,
            user: $user,
            locale: app()->currentLocale(),
            currentTitleId: $title->id,
            perPage: $limit,
        );
        $hardExclusions = $this->exclusions->hardExclusions($relatedContext);
        $relatedRows = $this->relations
            ->forTitle($title, $user, $limit)
            ->reject(fn (CatalogTitleRelation $relation): bool => in_array((int) $relation->target_title_id, $hardExclusions, true))
            ->values();
        $relatedTitles = $this->loader
            ->load($relatedContext, $relatedRows->pluck('target_title_id')->map(fn (mixed $id): int => (int) $id)->all())
            ->keyBy('id');
        $relatedItems = $relatedRows
            ->filter(fn (CatalogTitleRelation $relation): bool => $relatedTitles->has((int) $relation->target_title_id))
            ->values()
            ->map(function (CatalogTitleRelation $relation, int $index) use ($relatedTitles): CatalogRecommendationItem {
                /** @var CatalogTitle $target */
                $target = $relatedTitles->get((int) $relation->target_title_id);

                return new CatalogRecommendationItem(
                    title: $target,
                    type: CatalogRecommendationType::Related,
                    source: $relation->relationSource() === CatalogTitleRelationSource::Editorial
                        ? CatalogRecommendationSource::Editorial
                        : CatalogRecommendationSource::ImportedProvider,
                    explanations: [new CatalogRecommendationExplanation(
                        CatalogRecommendationReason::RelatedStory,
                        ['relation' => $this->presenter->relation($relation->relationType())],
                    )],
                    rank: $index + 1,
                    score: max(1, 65_535 - (int) $relation->priority),
                    relationType: $relation->relationType()->value,
                );
            });
        $relatedIds = $relatedItems->map(fn (CatalogRecommendationItem $item): int => $item->title->id)->all();
        $similarContext = new CatalogRecommendationContext(
            type: CatalogRecommendationType::Similar,
            user: $user,
            locale: app()->currentLocale(),
            currentTitleId: $title->id,
            excludedTitleIds: $relatedIds,
            perPage: $limit,
        );
        $similarRows = $this->similarCandidates($title, $similarContext, $limit);
        $similarResult = $this->result(
            context: $similarContext,
            candidates: $similarRows,
            displayType: CatalogRecommendationType::Similar,
            personalized: false,
            coldStart: false,
            watchable: true,
        );

        return ['related' => $relatedItems, 'similar' => $similarResult->items];
    }

    public function rememberShown(CatalogRecommendationResult $result, ?User $user): void
    {
        $this->repeats->remember($user, $result->items->map(
            fn (CatalogRecommendationItem $item): int => $item->title->id,
        ));
    }

    /**
     * @param  list<int>  $excludedIds
     * @return list<array{id: int, score: int, source: string, reason: string}>
     */
    private function coldStartCandidates(CatalogRecommendationContext $context, array $excludedIds): array
    {
        foreach ([CatalogRecommendationType::Editorial, CatalogRecommendationType::Trending, CatalogRecommendationType::Popular] as $type) {
            $fallback = new CatalogRecommendationContext(
                type: $type,
                user: $context->user,
                locale: $context->locale,
                excludedTitleIds: $context->excludedTitleIds,
                filters: $context->filters,
                period: $context->period,
                ratingSource: $context->ratingSource,
                page: 1,
                perPage: max($context->boundedPerPage(), (int) config('recommendations.personalized.public_fallback_limit', 24)),
            );
            $rows = $this->public->candidates($fallback, $excludedIds);

            if ($rows !== []) {
                return $rows;
            }
        }

        return [];
    }

    /**
     * @param  list<array{id: int, score: int, source: string, reason: string, reasons?: list<array{reason: string, parameters?: array<string, scalar>}>, relation_type?: string|null}>  $candidates
     */
    private function result(
        CatalogRecommendationContext $context,
        array $candidates,
        CatalogRecommendationType $displayType,
        bool $personalized,
        bool $coldStart,
        bool $watchable,
        ?CatalogPersonalizationConfidence $personalizationConfidence = null,
    ): CatalogRecommendationResult {
        $perPage = $context->boundedPerPage();
        $page = $context->boundedPage();
        $through = min(count($candidates), ($page * $perPage) + 1);
        $diversified = $this->diversity->diversify($candidates, $through);
        $window = array_slice($diversified, ($page - 1) * $perPage, $perPage + 1);
        $hasMore = count($window) > $perPage;
        $window = array_slice($window, 0, $perPage);
        $rowsById = collect($window)->keyBy('id');
        $titles = $this->loader->load($context, array_column($window, 'id'), $watchable);
        $items = $titles->map(function (CatalogTitle $title, int $index) use ($context, $displayType, $rowsById): CatalogRecommendationItem {
            $row = $rowsById->get($title->id);
            $source = CatalogRecommendationSource::tryFrom((string) ($row['source'] ?? ''))
                ?? CatalogRecommendationSource::ContentSimilarity;
            $reason = CatalogRecommendationReason::tryFrom((string) ($row['reason'] ?? ''))
                ?? CatalogRecommendationReason::SimilarGenres;

            return new CatalogRecommendationItem(
                title: $title,
                type: $displayType,
                source: $source,
                explanations: $this->rowExplanations($row, $reason),
                rank: (($context->boundedPage() - 1) * $context->boundedPerPage()) + $index + 1,
                score: (int) ($row['score'] ?? 0),
                relationType: is_string($row['relation_type'] ?? null) ? $row['relation_type'] : null,
            );
        });
        $hasDisplayedPersonalRow = collect($window)->contains(
            fn (array $row): bool => str_starts_with((string) ($row['source'] ?? ''), 'user_'),
        );

        return new CatalogRecommendationResult(
            requestedType: $context->type,
            displayType: $displayType,
            items: $items,
            page: $page,
            perPage: $perPage,
            hasMore: $hasMore,
            personalized: $personalized && $hasDisplayedPersonalRow,
            coldStart: $coldStart,
            personalizationConfidence: $personalizationConfidence,
        );
    }

    /**
     * @param  list<array<string, mixed>>  $personal
     * @param  list<array<string, mixed>>  $public
     * @return list<array<string, mixed>>
     */
    private function blendPersonalCandidates(
        array $personal,
        array $public,
        int $limit,
        int $displayLimit,
    ): array {
        $limit = max(0, $limit);
        $displayLimit = min($limit, max(0, $displayLimit));
        $personal = collect($personal)->unique('id')->values()->all();
        $personalIds = array_fill_keys(array_column($personal, 'id'), true);
        $public = collect($public)
            ->reject(static fn (array $row): bool => isset($personalIds[(int) $row['id']]))
            ->unique('id')
            ->values()
            ->all();
        $personalCount = min(count($personal), $displayLimit);
        $personalPositions = [];

        for ($index = 0; $index < $personalCount; $index++) {
            $position = (int) floor((($index + 1) * $displayLimit) / ($personalCount + 1));

            while (isset($personalPositions[$position]) && $position < $displayLimit) {
                $position++;
            }

            $personalPositions[min($displayLimit - 1, $position)] = true;
        }

        $blended = [];
        $personalIndex = 0;
        $publicIndex = 0;

        for ($position = 0; $position < $limit; $position++) {
            $usePersonal = $position < $displayLimit
                && isset($personalPositions[$position])
                && isset($personal[$personalIndex]);
            $row = $usePersonal ? $personal[$personalIndex++] : ($public[$publicIndex++] ?? null);

            if ($row === null && isset($personal[$personalIndex])) {
                $row = $personal[$personalIndex++];
            }

            if ($row === null) {
                break;
            }

            $row['blend_position'] = $position;
            $blended[] = $row;
        }

        return $blended;
    }

    /** @param list<array<string, mixed>> $candidates */
    private function fallbackDisplayType(array $candidates): CatalogRecommendationType
    {
        return match ($candidates[0]['source'] ?? null) {
            CatalogRecommendationSource::Editorial->value => CatalogRecommendationType::Editorial,
            CatalogRecommendationSource::Trending->value => CatalogRecommendationType::Trending,
            default => CatalogRecommendationType::Popular,
        };
    }

    /** @return list<array{id: int, score: int, source: string, reason: string}> */
    private function similarCandidates(
        CatalogTitle $title,
        CatalogRecommendationContext $context,
        int $limit,
    ): array {
        $excludedIds = $this->exclusions->hardExclusions($context);

        if (Schema::hasTable('catalog_title_recommendations')) {
            $rows = CatalogTitleRecommendation::query()
                ->where('catalog_title_id', $title->id)
                ->whereNotIn('recommended_title_id', $excludedIds)
                ->whereIn('recommended_title_id', $this->visibility
                    ->eligible($context, watchable: true, excludedIds: $excludedIds)
                    ->select('catalog_titles.id'))
                ->orderBy('rank')
                ->orderByDesc('score')
                ->limit($limit * 2)
                ->get(['recommended_title_id', 'score', 'reasons'])
                ->map(function (CatalogTitleRecommendation $row): array {
                    $explanations = $this->presenter->storedSimilarityExplanations($row->reasons);
                    $primary = $explanations[0]->reason ?? CatalogRecommendationReason::SimilarGenres;

                    return [
                        'id' => (int) $row->recommended_title_id,
                        'score' => (int) $row->score,
                        'source' => CatalogRecommendationSource::ContentSimilarity->value,
                        'reason' => $primary->value,
                        'reasons' => array_map(
                            static fn (CatalogRecommendationExplanation $explanation): array => [
                                'reason' => $explanation->reason->value,
                                'parameters' => $explanation->parameters,
                            ],
                            $explanations,
                        ),
                    ];
                })
                ->all();

            if ($rows !== []) {
                return $rows;
            }
        }

        $genreIds = $title->genres()->pluck('genres.id');

        if ($genreIds->isEmpty()) {
            return [];
        }

        return $this->visibility
            ->eligible($context, watchable: true, excludedIds: $excludedIds)
            ->whereHas('genres', fn (Builder $query): Builder => $query->whereKey($genreIds))
            ->latest('indexed_at')
            ->orderByDesc('catalog_titles.id')
            ->limit($limit)
            ->pluck('catalog_titles.id')
            ->map(fn (mixed $id, int $index): array => [
                'id' => (int) $id,
                'score' => $limit - $index,
                'source' => CatalogRecommendationSource::ContentSimilarity->value,
                'reason' => CatalogRecommendationReason::SimilarGenres->value,
            ])
            ->all();
    }

    /**
     * @param  array<string, mixed>  $row
     * @return list<CatalogRecommendationExplanation>
     */
    private function rowExplanations(array $row, CatalogRecommendationReason $fallback): array
    {
        $encoded = is_array($row['reasons'] ?? null) ? $row['reasons'] : [];
        $explanations = [];

        foreach (array_slice($encoded, 0, 4) as $item) {
            if (! is_array($item)) {
                continue;
            }

            $reason = CatalogRecommendationReason::tryFrom((string) ($item['reason'] ?? ''));

            if ($reason === null) {
                continue;
            }

            $parameters = collect(is_array($item['parameters'] ?? null) ? $item['parameters'] : [])
                ->filter(fn (mixed $value, mixed $key): bool => is_string($key) && is_scalar($value))
                ->mapWithKeys(fn (mixed $value, string $key): array => [$key => $value])
                ->all();
            $explanations[] = new CatalogRecommendationExplanation($reason, $parameters);
        }

        return $explanations !== []
            ? $explanations
            : [new CatalogRecommendationExplanation($fallback)];
    }
}
