<?php

declare(strict_types=1);

namespace App\Services\Catalog;

use App\Enums\CatalogTitleRelationType;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class CatalogRecommendationDiversityService
{
    /**
     * @param list<array{id: int, score: int, source: string, reason: string, relation_type?: string|null}> $candidates
     * @return list<array{id: int, score: int, source: string, reason: string, relation_type?: string|null}>
     */
    public function diversify(array $candidates, int $limit): array
    {
        if ($candidates === [] || $limit < 1) {
            return [];
        }

        $titleIds = collect($candidates)->pluck('id')->map(fn (mixed $id): int => (int) $id)->unique()->values();
        $genreRows = DB::table('catalog_title_genre')
            ->whereIn('catalog_title_id', $titleIds)
            ->get(['catalog_title_id', 'genre_id']);
        $actorRows = DB::table('catalog_title_actor')
            ->whereIn('catalog_title_id', $titleIds)
            ->get(['catalog_title_id', 'actor_id']);
        $dominantGenres = $this->dominantFeatureByTitle($genreRows, 'genre_id');
        $dominantActors = $this->dominantFeatureByTitle($actorRows, 'actor_id');
        $franchises = $this->franchiseByTitle($titleIds->all());
        $genreLimit = max(1, (int) config('recommendations.diversity.primary_genre_limit', 5));
        $actorLimit = max(1, (int) config('recommendations.diversity.leading_actor_limit', 4));
        $franchiseLimit = max(1, (int) config('recommendations.diversity.franchise_limit', 2));
        $genreCounts = [];
        $actorCounts = [];
        $franchiseCounts = [];
        $selected = [];
        $deferred = [];

        foreach ($candidates as $candidate) {
            $id = (int) $candidate['id'];
            $genre = $dominantGenres[$id] ?? null;
            $actor = $dominantActors[$id] ?? null;
            $franchise = $franchises[$id] ?? null;
            $genreFull = $genre !== null && ($genreCounts[(int) $genre] ?? 0) >= $genreLimit;
            $actorFull = $actor !== null && ($actorCounts[(int) $actor] ?? 0) >= $actorLimit;
            $franchiseFull = $franchise !== null && ($franchiseCounts[(int) $franchise] ?? 0) >= $franchiseLimit;

            if ($genreFull || $actorFull || $franchiseFull) {
                $deferred[] = $candidate;

                continue;
            }

            $selected[] = $candidate;

            if ($genre !== null) {
                $genreCounts[(int) $genre] = ($genreCounts[(int) $genre] ?? 0) + 1;
            }

            if ($actor !== null) {
                $actorCounts[(int) $actor] = ($actorCounts[(int) $actor] ?? 0) + 1;
            }

            if ($franchise !== null) {
                $franchiseCounts[(int) $franchise] = ($franchiseCounts[(int) $franchise] ?? 0) + 1;
            }

            if (count($selected) >= $limit) {
                break;
            }
        }

        if (count($selected) < $limit) {
            $selected = [...$selected, ...array_slice($deferred, 0, $limit - count($selected))];
        }

        return array_values(array_slice($selected, 0, $limit));
    }

    /**
     * @param \Illuminate\Support\Collection<int, object> $rows
     * @return array<int, int>
     */
    private function dominantFeatureByTitle(\Illuminate\Support\Collection $rows, string $featureColumn): array
    {
        $frequency = $rows->countBy(fn (object $row): int => (int) $row->{$featureColumn});

        return $rows
            ->groupBy(fn (object $row): int => (int) $row->catalog_title_id)
            ->map(function (\Illuminate\Support\Collection $titleRows) use ($featureColumn, $frequency): int {
                return (int) $titleRows
                    ->sort(function (object $left, object $right) use ($featureColumn, $frequency): int {
                        $leftId = (int) $left->{$featureColumn};
                        $rightId = (int) $right->{$featureColumn};

                        return ($frequency->get($rightId, 0) <=> $frequency->get($leftId, 0))
                            ?: ($leftId <=> $rightId);
                    })
                    ->first()
                    ->{$featureColumn};
            })
            ->all();
    }

    /** @param list<int> $titleIds @return array<int, int> */
    private function franchiseByTitle(array $titleIds): array
    {
        if ($titleIds === [] || ! Schema::hasTable('catalog_title_relations')) {
            return [];
        }

        $franchiseTypes = [
            CatalogTitleRelationType::Sequel->value,
            CatalogTitleRelationType::Prequel->value,
            CatalogTitleRelationType::SpinOff->value,
            CatalogTitleRelationType::SpinOffFrom->value,
            CatalogTitleRelationType::Remake->value,
            CatalogTitleRelationType::SameUniverse->value,
        ];
        /** @var array<int, int> $parents */
        $parents = array_combine($titleIds, $titleIds);

        DB::table('catalog_title_relations')
            ->whereIn('source_title_id', $titleIds)
            ->whereIn('target_title_id', $titleIds)
            ->whereIn('relation_type', $franchiseTypes)
            ->where('is_active', true)
            ->get(['source_title_id', 'target_title_id'])
            ->each(function (object $row) use (&$parents): void {
                $source = $this->root($parents, (int) $row->source_title_id);
                $target = $this->root($parents, (int) $row->target_title_id);

                if ($source !== $target) {
                    $parents[max($source, $target)] = min($source, $target);
                }
            });

        return collect($titleIds)
            ->mapWithKeys(fn (int $id): array => [$id => $this->root($parents, $id)])
            ->all();
    }

    /** @param array<int, int> $parents */
    private function root(array &$parents, int $id): int
    {
        $root = $id;

        while (($parents[$root] ?? $root) !== $root) {
            $root = $parents[$root];
        }

        while (($parents[$id] ?? $id) !== $id) {
            $next = $parents[$id];
            $parents[$id] = $root;
            $id = $next;
        }

        return $root;
    }
}
