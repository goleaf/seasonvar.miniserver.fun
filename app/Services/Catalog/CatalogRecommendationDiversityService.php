<?php

declare(strict_types=1);

namespace App\Services\Catalog;

use Illuminate\Support\Facades\DB;

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
        $genreLimit = max(1, (int) config('recommendations.diversity.primary_genre_limit', 5));
        $actorLimit = max(1, (int) config('recommendations.diversity.leading_actor_limit', 4));
        $genreCounts = [];
        $actorCounts = [];
        $selected = [];
        $deferred = [];

        foreach ($candidates as $candidate) {
            $id = (int) $candidate['id'];
            $genre = $dominantGenres[$id] ?? null;
            $actor = $dominantActors[$id] ?? null;
            $genreFull = $genre !== null && ($genreCounts[(int) $genre] ?? 0) >= $genreLimit;
            $actorFull = $actor !== null && ($actorCounts[(int) $actor] ?? 0) >= $actorLimit;

            if ($genreFull || $actorFull) {
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
}
