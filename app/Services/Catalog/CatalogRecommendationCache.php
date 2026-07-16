<?php

declare(strict_types=1);

namespace App\Services\Catalog;

use App\DTOs\CatalogRecommendationContext;
use App\Enums\CatalogRecommendationReason;
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
     * @param  Closure(): list<array{id: int, score: int, source: string, reason: string, reasons?: list<array{reason: string, parameters?: array<string, scalar>}>, relation_type?: string|null}>  $rebuild
     * @param  list<int>  $excludedIds
     * @return list<array{id: int, score: int, source: string, reason: string, reasons?: list<array{reason: string, parameters: array<string, scalar>}>, relation_type?: string|null}>
     */
    public function rememberPublic(CatalogRecommendationContext $context, Closure $rebuild, array $excludedIds = []): array
    {
        if ($context->user !== null
            || $context->type === CatalogRecommendationType::Personalized
            || $context->type === CatalogRecommendationType::Random
            || $context->seed !== null) {
            return $rebuild();
        }

        $filters = $context->filters;
        ksort($filters);
        $exclusions = collect([
            ...$context->excludedTitleIds,
            ...$excludedIds,
            ...($context->currentTitleId !== null ? [$context->currentTitleId] : []),
        ])
            ->filter(fn (int $id): bool => $id > 0)
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
                'filters' => hash('sha256', json_encode($filters, JSON_THROW_ON_ERROR)),
                'ranking_version' => (string) config('recommendations.version', 'task18-v6'),
            ],
            $this->ttl->for(CacheDomain::Recommendations),
            $rebuild,
        );

        return $this->normalize($result->value);
    }

    /**
     * @return list<array{id: int, score: int, source: string, reason: string, reasons?: list<array{reason: string, parameters: array<string, scalar>}>, relation_type?: string|null}>
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
            ->map(function (array $row): array {
                $normalized = [
                    'id' => (int) $row['id'],
                    'score' => (int) $row['score'],
                    'source' => $row['source'],
                    'reason' => $row['reason'],
                    'relation_type' => is_string($row['relation_type'] ?? null) ? $row['relation_type'] : null,
                ];
                $reasons = $this->normalizeReasons($row['reasons'] ?? []);

                if ($reasons !== []) {
                    $normalized['reasons'] = $reasons;
                }

                return $normalized;
            })
            ->values()
            ->all();
    }

    /** @return list<array{reason: string, parameters: array<string, scalar>}> */
    private function normalizeReasons(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return collect($value)
            ->filter(fn (mixed $item): bool => is_array($item)
                && CatalogRecommendationReason::tryFrom((string) ($item['reason'] ?? '')) !== null)
            ->map(function (array $item): array {
                $parameters = collect(is_array($item['parameters'] ?? null) ? $item['parameters'] : [])
                    ->filter(fn (mixed $parameter, mixed $key): bool => is_string($key) && is_scalar($parameter))
                    ->mapWithKeys(fn (mixed $parameter, string $key): array => [$key => $parameter])
                    ->all();

                return [
                    'reason' => (string) $item['reason'],
                    'parameters' => $parameters,
                ];
            })
            ->unique(fn (array $item): string => $item['reason'].'|'.json_encode(
                $item['parameters'],
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE,
            ))
            ->take(4)
            ->values()
            ->all();
    }
}
