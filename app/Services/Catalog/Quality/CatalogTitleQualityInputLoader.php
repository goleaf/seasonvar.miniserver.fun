<?php

declare(strict_types=1);

namespace App\Services\Catalog\Quality;

use App\DTOs\CatalogQuality\CatalogTitleQualityFacts;
use App\Enums\ReleaseKind;
use App\Models\CatalogTitle;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class CatalogTitleQualityInputLoader
{
    /**
     * @param  iterable<int, int|string>  $titleIds
     * @return Collection<int, CatalogTitleQualityFacts>
     */
    public function load(
        iterable $titleIds,
        ?CarbonInterface $evaluatedAt = null,
    ): Collection {
        $ids = collect($titleIds)
            ->filter(static fn (int|string $id): bool => is_int($id) || ctype_digit($id))
            ->map(static fn (int|string $id): int => (int) $id)
            ->filter(static fn (int $id): bool => $id > 0)
            ->unique()
            ->take(1_000)
            ->values();

        if ($ids->isEmpty()) {
            return collect();
        }

        $titles = CatalogTitle::query()
            ->select([
                'id',
                'source_page_id',
                'title',
                'original_title',
                'year',
                'description',
                'poster_url',
                'provider_field_values',
            ])
            ->with([
                'sourcePage:id,last_crawled_at,last_imported_at',
                'genres:id,name',
                'countries:id,name',
                'tags:id,name,type',
                'ratings:id,catalog_title_id,provider,rating,votes',
            ])
            ->whereKey($ids->all())
            ->get();

        $provenance = DB::table('catalog_title_tag_sources')
            ->whereIn('catalog_title_id', $ids->all())
            ->where('is_current', true)
            ->get(['catalog_title_id', 'tag_id'])
            ->mapWithKeys(
                static fn (object $row): array => [
                    ((int) $row->catalog_title_id).':'.((int) $row->tag_id) => true,
                ],
            );
        $seasons = $this->seasonFacts($ids->all());
        $media = $this->mediaFacts($ids->all());
        $now = $evaluatedAt ?? CarbonImmutable::now();

        return $titles
            ->mapWithKeys(function (CatalogTitle $title) use (
                $provenance,
                $seasons,
                $media,
                $now,
            ): array {
                $titleId = (int) $title->id;
                $sourceCheckedAt = collect([
                    $title->sourcePage?->last_crawled_at,
                    $title->sourcePage?->last_imported_at,
                ])->filter()->sortDesc()->first();

                return [$titleId => CatalogTitleQualityFacts::fromArray([
                    'catalog_title_id' => $titleId,
                    'title' => $title->title,
                    'original_title' => $title->original_title,
                    'year' => $title->year,
                    'description' => $title->description,
                    'poster_url' => $title->poster_url,
                    'countries' => $title->countries->pluck('name')->all(),
                    'genres' => $title->genres->pluck('name')->all(),
                    'tags' => $title->tags
                        ->map(static fn ($tag): array => [
                            'name' => (string) $tag->name,
                            'type' => $tag->type->value,
                            'has_current_provenance' => $provenance->has(
                                $titleId.':'.((int) $tag->id),
                            ),
                        ])
                        ->all(),
                    'provider_field_values' => $title->provider_field_values ?? [],
                    'seasons' => $seasons[$titleId] ?? [],
                    'media' => $media[$titleId] ?? [
                        'published_playable_count' => 0,
                        'never_checked_count' => 0,
                        'latest_checked_at' => null,
                    ],
                    'ratings' => $title->ratings
                        ->map(static fn ($rating): array => [
                            'provider' => (string) $rating->provider,
                            'rating' => $rating->rating !== null ? (float) $rating->rating : null,
                            'votes' => $rating->votes,
                        ])
                        ->all(),
                    'last_source_checked_at' => $sourceCheckedAt,
                    'evaluated_at' => $now,
                ])];
            });
    }

    /**
     * @param  list<int>  $titleIds
     * @return array<int, list<array{
     *     number: int,
     *     regular_episode_count: int,
     *     minimum_episode_number: int|null,
     *     maximum_episode_number: int|null,
     *     distinct_episode_number_count: int,
     *     released_episode_count: int|null,
     *     total_episode_count: int|null
     * }>>
     */
    private function seasonFacts(array $titleIds): array
    {
        $rows = DB::table('seasons')
            ->leftJoin('episodes', function ($join): void {
                $join
                    ->on('episodes.season_id', '=', 'seasons.id')
                    ->where('episodes.kind', ReleaseKind::Regular->value)
                    ->whereNull('episodes.deleted_at');
            })
            ->whereIn('seasons.catalog_title_id', $titleIds)
            ->where('seasons.kind', ReleaseKind::Regular->value)
            ->whereNull('seasons.deleted_at')
            ->groupBy([
                'seasons.id',
                'seasons.catalog_title_id',
                'seasons.number',
                'seasons.episodes_released',
                'seasons.episodes_total',
            ])
            ->orderBy('seasons.catalog_title_id')
            ->orderBy('seasons.number')
            ->select([
                'seasons.catalog_title_id',
                'seasons.number',
                'seasons.episodes_released',
                'seasons.episodes_total',
            ])
            ->selectRaw('COUNT(episodes.id) AS regular_episode_count')
            ->selectRaw('MIN(episodes.number) AS minimum_episode_number')
            ->selectRaw('MAX(episodes.number) AS maximum_episode_number')
            ->selectRaw('COUNT(DISTINCT episodes.number) AS distinct_episode_number_count')
            ->get();
        $facts = [];

        foreach ($rows as $row) {
            $facts[(int) $row->catalog_title_id][] = [
                'number' => (int) $row->number,
                'regular_episode_count' => (int) $row->regular_episode_count,
                'minimum_episode_number' => $row->minimum_episode_number !== null
                    ? (int) $row->minimum_episode_number
                    : null,
                'maximum_episode_number' => $row->maximum_episode_number !== null
                    ? (int) $row->maximum_episode_number
                    : null,
                'distinct_episode_number_count' => (int) $row->distinct_episode_number_count,
                'released_episode_count' => $row->episodes_released !== null
                    ? (int) $row->episodes_released
                    : null,
                'total_episode_count' => $row->episodes_total !== null
                    ? (int) $row->episodes_total
                    : null,
            ];
        }

        return $facts;
    }

    /**
     * @param  list<int>  $titleIds
     * @return array<int, array{
     *     published_playable_count: int,
     *     never_checked_count: int,
     *     latest_checked_at: string|null
     * }>
     */
    private function mediaFacts(array $titleIds): array
    {
        $playableCondition = <<<'SQL'
            status = 'published'
            AND health_status IN ('active', 'degraded')
            AND (COALESCE(playback_url, '') != '' OR COALESCE(path, '') != '')
            SQL;

        $rows = DB::table('licensed_media')
            ->whereIn('catalog_title_id', $titleIds)
            ->whereNull('deleted_at')
            ->groupBy('catalog_title_id')
            ->select('catalog_title_id')
            ->selectRaw("SUM(CASE WHEN {$playableCondition} THEN 1 ELSE 0 END) AS published_playable_count")
            ->selectRaw("SUM(CASE WHEN {$playableCondition} AND checked_at IS NULL THEN 1 ELSE 0 END) AS never_checked_count")
            ->selectRaw("MAX(CASE WHEN {$playableCondition} THEN checked_at ELSE NULL END) AS latest_checked_at")
            ->get();
        $facts = [];

        foreach ($rows as $row) {
            $facts[(int) $row->catalog_title_id] = [
                'published_playable_count' => (int) $row->published_playable_count,
                'never_checked_count' => (int) $row->never_checked_count,
                'latest_checked_at' => is_string($row->latest_checked_at)
                    ? $row->latest_checked_at
                    : null,
            ];
        }

        return $facts;
    }
}
