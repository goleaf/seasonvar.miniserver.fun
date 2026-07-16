<?php

declare(strict_types=1);

namespace App\Services\Catalog;

use App\DTOs\CatalogRecommendationContext;
use App\DTOs\CatalogRecommendationExplanation;
use App\DTOs\CatalogRecommendationItem;
use App\DTOs\CatalogRecommendationResult;
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

        if ($context->type === CatalogRecommendationType::Personalized) {
            $discoveryDemotions = $this->exclusions->discoveryDemotions($context);
            $personalizedExclusions = array_values(array_unique([
                ...$hardExclusions,
                ...$discoveryDemotions,
            ]));
            $candidates = $context->user !== null
                ? $this->personalized->candidates(
                    $context,
                    $personalizedExclusions,
                )
                : [];
            $personalized = $candidates !== [];

            if ($candidates === [] && $hardExclusions !== $baseHardExclusions && $context->user !== null) {
                $candidates = $this->personalized->candidates(
                    $context,
                    array_values(array_unique([
                        ...$baseHardExclusions,
                        ...$discoveryDemotions,
                    ])),
                );
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

                $displayType = match ($candidates[0]['source'] ?? null) {
                    CatalogRecommendationSource::Editorial->value => CatalogRecommendationType::Editorial,
                    CatalogRecommendationSource::Trending->value => CatalogRecommendationType::Trending,
                    default => CatalogRecommendationType::Popular,
                };
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

        return $this->result(
            context: $context,
            candidates: $candidates,
            displayType: $displayType,
            personalized: $personalized,
            coldStart: $coldStart,
            watchable: $context->type !== CatalogRecommendationType::Upcoming,
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
     * @param  list<array{id: int, score: int, source: string, reason: string, relation_type?: string|null}>  $candidates
     */
    private function result(
        CatalogRecommendationContext $context,
        array $candidates,
        CatalogRecommendationType $displayType,
        bool $personalized,
        bool $coldStart,
        bool $watchable,
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
                explanations: [new CatalogRecommendationExplanation($reason)],
                rank: (($context->boundedPage() - 1) * $context->boundedPerPage()) + $index + 1,
                score: (int) ($row['score'] ?? 0),
                relationType: is_string($row['relation_type'] ?? null) ? $row['relation_type'] : null,
            );
        });

        return new CatalogRecommendationResult(
            requestedType: $context->type,
            displayType: $displayType,
            items: $items,
            page: $page,
            perPage: $perPage,
            hasMore: $hasMore,
            personalized: $personalized,
            coldStart: $coldStart,
        );
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
                ->map(fn (CatalogTitleRecommendation $row): array => [
                    'id' => (int) $row->recommended_title_id,
                    'score' => (int) $row->score,
                    'source' => CatalogRecommendationSource::ContentSimilarity->value,
                    'reason' => $this->storedSimilarityReason($row->reasons)->value,
                ])
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

    private function storedSimilarityReason(mixed $reasons): CatalogRecommendationReason
    {
        $keys = is_array($reasons) ? array_keys($reasons) : [];

        foreach (['director', 'actor', 'tag', 'genre', 'studio', 'translation'] as $key) {
            if (! in_array($key, $keys, true)) {
                continue;
            }

            return match ($key) {
                'director' => CatalogRecommendationReason::SharedDirector,
                'actor' => CatalogRecommendationReason::SharedActor,
                'tag' => CatalogRecommendationReason::SimilarTags,
                'studio' => CatalogRecommendationReason::SharedStudio,
                'translation' => CatalogRecommendationReason::SharedTranslation,
                default => CatalogRecommendationReason::SimilarGenres,
            };
        }

        return CatalogRecommendationReason::SimilarGenres;
    }
}
