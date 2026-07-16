<?php

declare(strict_types=1);

namespace App\Services\Catalog;

use App\DTOs\CatalogRecommendationContext;
use App\Enums\CatalogRecommendationType;
use App\Support\Cache\CacheDomain;
use App\Support\Cache\CacheTtlPolicy;
use App\Support\Cache\TieredCache;
use Closure;

final class CatalogRecommendationCache
{
    public function __construct(
        private readonly TieredCache $cache,
        private readonly CacheTtlPolicy $ttl,
    ) {}

    /**
     * @param Closure(): list<array{id: int, score: int, source: string, reason: string, relation_type?: string|null}> $rebuild
     * @return list<array{id: int, score: int, source: string, reason: string, relation_type?: string|null}>
     */
    public function rememberPublic(CatalogRecommendationContext $context, Closure $rebuild, array $excludedIds = []): array
    {
        if ($context->user !== null
            || $context->type === CatalogRecommendationType::Personalized
            || $context->type === CatalogRecommendationType::Random) {
            return $rebuild();
        }

        $filters = $context->filters;
        ksort($filters);
        $exclusions = collect([
            ...$context->excludedTitleIds,
            ...$excludedIds,
            ...($context->currentTitleId !== null ? [$context->currentTitleId] : []),
        ])
            ->filter(fn (mixed $id): bool => is_int($id) && $id > 0)
            ->unique()
            ->sort()
            ->values()
            ->all();
        $result = $this->cache->remember(
            CacheDomain::Recommendations,
            'discovery-ids-v2',
            [
                'type' => $context->type->value,
                'locale' => $context->locale,
                'audience' => 'public',
                'period' => $context->period->value,
                'rating_source' => $context->ratingSource,
                'current_title' => $context->currentTitleId ?? 0,
                'exclusions' => hash('sha256', json_encode($exclusions, JSON_THROW_ON_ERROR)),
                'page' => $context->boundedPage(),
                'per_page' => $context->boundedPerPage(),
                'filters' => hash('sha256', json_encode($filters, JSON_THROW_ON_ERROR)),
                'ranking_version' => (string) config('recommendations.version', 'task18-v4'),
            ],
            $this->ttl->for(CacheDomain::Recommendations),
            $rebuild,
        );

        return $this->normalize($result->value);
    }

    /**
     * @return list<array{id: int, score: int, source: string, reason: string, relation_type?: string|null}>
     */
    private function normalize(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return collect($value)
            ->filter(fn (mixed $row): bool => is_array($row)
                && is_numeric($row['id'] ?? null)
                && is_numeric($row['score'] ?? null)
                && is_string($row['source'] ?? null)
                && is_string($row['reason'] ?? null))
            ->map(fn (array $row): array => [
                'id' => (int) $row['id'],
                'score' => (int) $row['score'],
                'source' => $row['source'],
                'reason' => $row['reason'],
                'relation_type' => is_string($row['relation_type'] ?? null) ? $row['relation_type'] : null,
            ])
            ->values()
            ->all();
    }
}
