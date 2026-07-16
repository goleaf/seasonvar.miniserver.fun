<?php

declare(strict_types=1);

namespace App\Services\Catalog;

use App\Enums\CatalogRecommendationReason;
use App\Enums\CatalogRecommendationSource;

final class CatalogRecommendationExplorationMixer
{
    /**
     * @param  list<array<string, mixed>>  $exploit
     * @param  list<array<string, mixed>>  $explore
     * @return list<array<string, mixed>>
     */
    public function mix(array $exploit, array $explore, int $limit, string $seed): array
    {
        $limit = max(0, $limit);
        $exploit = collect($exploit)
            ->filter(static fn (mixed $row): bool => is_array($row) && (int) ($row['id'] ?? 0) > 0)
            ->unique('id')
            ->take($limit)
            ->values()
            ->all();
        $ratio = max(0.0, min(0.15, (float) config('recommendations.personalized_v2.exploration_ratio', 0.15)));
        $exploreCount = min(count($explore), (int) floor($limit * $ratio));

        if ($limit === 0 || $exploreCount < 1 || $explore === []) {
            return $exploit;
        }

        $floor = max(0.0, min(1.0, (float) config(
            'recommendations.personalized_v2.exploration_relevance_floor',
            0.45,
        )));
        $exploitIds = array_fill_keys(array_column($exploit, 'id'), true);
        $eligible = collect($explore)
            ->filter(static fn (mixed $row): bool => is_array($row)
                && (int) ($row['id'] ?? 0) > 0
                && (float) ($row['normalized_relevance'] ?? 0.0) >= $floor
                && ! isset($exploitIds[(int) $row['id']]))
            ->unique('id')
            ->sort(function (array $left, array $right) use ($seed): int {
                $hashOrder = strcmp(
                    hash('xxh128', $seed.'|'.(int) $left['id']),
                    hash('xxh128', $seed.'|'.(int) $right['id']),
                );

                if ($hashOrder !== 0) {
                    return $hashOrder;
                }

                return (($right['score'] ?? 0) <=> ($left['score'] ?? 0))
                    ?: ((int) $left['id'] <=> (int) $right['id']);
            })
            ->take($exploreCount)
            ->values()
            ->all();

        if ($eligible === []) {
            return $exploit;
        }

        $result = $exploit;
        $count = count($eligible);
        $removeCount = max(0, (count($result) + $count) - $limit);

        if ($removeCount > 0) {
            $victims = collect($result)
                ->sort(static fn (array $left, array $right): int => (($left['score'] ?? 0) <=> ($right['score'] ?? 0))
                    ?: ((int) $left['id'] <=> (int) $right['id']))
                ->take($removeCount)
                ->pluck('id')
                ->mapWithKeys(static fn (mixed $id): array => [(int) $id => true])
                ->all();
            $result = array_values(array_filter(
                $result,
                static fn (array $row): bool => ! isset($victims[(int) $row['id']]),
            ));
        }

        foreach ($eligible as $slot => $row) {
            $position = min($limit - 1, (int) floor((($slot + 1) * $limit) / ($count + 1)));
            $row['source'] = CatalogRecommendationSource::ContentSimilarity->value;
            $row['reason'] = CatalogRecommendationReason::NewForYou->value;
            $row['reasons'] = [[
                'reason' => CatalogRecommendationReason::NewForYou->value,
                'parameters' => [],
            ]];

            array_splice($result, min($position, count($result)), 0, [$row]);
        }

        if (collect($exploit)->contains(static fn (array $row): bool => isset($row['blend_position']))) {
            foreach ($result as $position => &$row) {
                $row['blend_position'] = $position;
            }
            unset($row);
        }

        return array_slice(array_values($result), 0, $limit);
    }
}
