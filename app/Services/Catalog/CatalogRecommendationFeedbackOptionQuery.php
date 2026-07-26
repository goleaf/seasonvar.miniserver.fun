<?php

declare(strict_types=1);

namespace App\Services\Catalog;

use App\Models\CatalogTitle;
use App\Models\Genre;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class CatalogRecommendationFeedbackOptionQuery
{
    /**
     * @param  iterable<int, CatalogTitle>  $titles
     * @return array<int, array{genres: list<array{id: int, name: string}>, countries: list<array{id: int, name: string}>, actors: list<array{id: int, name: string}>}>
     */
    public function forTitles(iterable $titles): array
    {
        $titleIds = collect($titles)
            ->pluck('id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->filter(static fn (int $id): bool => $id > 0)
            ->unique()
            ->take(48)
            ->values()
            ->all();

        if ($titleIds === []) {
            return [];
        }

        $options = collect($titleIds)->mapWithKeys(static fn (int $id): array => [$id => [
            'genres' => [],
            'countries' => [],
            'actors' => [],
        ]])->all();
        $maximum = max(1, min(20, (int) config('recommendations.feedback.maximum_subject_options', 12)));
        $relations = [
            'genres' => ['pivot' => 'catalog_title_genre', 'table' => 'genres', 'key' => 'genre_id'],
            'countries' => ['pivot' => 'catalog_title_country', 'table' => 'countries', 'key' => 'country_id'],
            'actors' => ['pivot' => 'catalog_title_actor', 'table' => 'actors', 'key' => 'actor_id'],
        ];

        foreach ($relations as $type => $relation) {
            DB::table($relation['pivot'].' as feedback_pivot')
                ->join(
                    $relation['table'].' as feedback_subject',
                    'feedback_subject.id',
                    '=',
                    'feedback_pivot.'.$relation['key'],
                )
                ->whereIn('feedback_pivot.catalog_title_id', $titleIds)
                ->orderBy('feedback_pivot.catalog_title_id')
                ->orderBy('feedback_subject.name')
                ->orderBy('feedback_subject.id')
                ->get([
                    'feedback_pivot.catalog_title_id',
                    'feedback_subject.id',
                    'feedback_subject.name',
                ])
                ->groupBy('catalog_title_id')
                ->each(function (Collection $rows, mixed $titleId) use (&$options, $maximum, $type): void {
                    $options[(int) $titleId][$type] = $rows
                        ->take($maximum)
                        ->map(static fn (object $row): array => [
                            'id' => (int) $row->id,
                            'name' => (string) $row->name,
                        ])
                        ->all();
                });
        }

        return $options;
    }

    /** @return Collection<int, Genre> */
    public function activeHiddenGenres(User $user): Collection
    {
        return Genre::query()
            ->whereIn('genres.id', DB::table('catalog_recommendation_hidden_genres')
                ->where('user_id', $user->id)
                ->where('hidden_until', '>', now())
                ->select('genre_id'))
            ->orderBy('name')
            ->orderBy('id')
            ->get(['id', 'name']);
    }
}
