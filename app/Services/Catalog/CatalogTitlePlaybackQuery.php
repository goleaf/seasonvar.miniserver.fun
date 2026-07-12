<?php

declare(strict_types=1);

namespace App\Services\Catalog;

use App\Models\CatalogTitle;
use App\Models\Episode;
use App\Models\LicensedMedia;
use App\Models\Season;
use App\Models\User;
use App\Services\Media\ExternalMediaMetadata;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class CatalogTitlePlaybackQuery
{
    public function __construct(
        private readonly CatalogTitleQuery $titles,
        private readonly ExternalMediaMetadata $mediaMetadata,
    ) {}

    public function visibleTitle(int $catalogTitleId, ?User $user): CatalogTitle
    {
        return $this->titles
            ->visibleTo($user)
            ->select(['id', 'slug', 'title', 'poster_url'])
            ->findOrFail($catalogTitleId);
    }

    /** @return Collection<int, Season> */
    public function seasonSummaries(CatalogTitle $catalogTitle, ?User $user): Collection
    {
        $watchableEpisodeIds = $this->availableMedia($catalogTitle, $user)
            ->whereNotNull('episode_id')
            ->select('episode_id')
            ->groupBy('episode_id');

        return $catalogTitle->seasons()
            ->availableTo($user)
            ->select([
                'id',
                'catalog_title_id',
                'number',
                'kind',
                'sort_order',
                'title',
                'latest_episode_released_at',
                'episodes_released',
                'episodes_total',
                'translation_name',
            ])
            ->withCount([
                'episodes as available_episodes_count' => fn (Builder $query): Builder => $query
                    ->availableTo($user)
                    ->whereIn((new Episode)->qualifyColumn('id'), clone $watchableEpisodeIds),
                'licensedMedia as available_media_count' => fn (Builder $query): Builder => $query
                    ->availableTo($user)
                    ->forAvailableReleases($user)
                    ->withPlaybackLocation(),
            ])
            ->get();
    }

    /** @return Builder<Episode> */
    public function watchableEpisodes(CatalogTitle $catalogTitle, ?User $user): Builder
    {
        $episode = new Episode;
        $season = new Season;

        return Episode::query()
            ->availableTo($user)
            ->join($season->getTable(), $season->qualifyColumn('id'), '=', $episode->qualifyColumn('season_id'))
            ->whereIn(
                $episode->qualifyColumn('season_id'),
                Season::query()
                    ->availableTo($user)
                    ->where('catalog_title_id', $catalogTitle->id)
                    ->select('id'),
            )
            ->whereIn(
                $episode->qualifyColumn('id'),
                $this->availableMedia($catalogTitle, $user)
                    ->whereNotNull('episode_id')
                    ->select('episode_id')
                    ->groupBy('episode_id'),
            )
            ->select([
                $episode->qualifyColumn('id'),
                $episode->qualifyColumn('season_id'),
                $episode->qualifyColumn('number'),
                $episode->qualifyColumn('kind'),
                $episode->qualifyColumn('sort_order'),
            ])
            ->addSelect([
                $season->qualifyColumn('kind').' as season_order_kind',
                $season->qualifyColumn('sort_order').' as season_order_sort',
                $season->qualifyColumn('number').' as season_order_number',
                $season->qualifyColumn('id').' as season_order_id',
            ])
            ->orderBy($season->qualifyColumn('kind'))
            ->orderBy($season->qualifyColumn('sort_order'))
            ->orderBy($season->qualifyColumn('number'))
            ->orderBy($season->qualifyColumn('id'))
            ->orderBy($episode->qualifyColumn('kind'))
            ->orderBy($episode->qualifyColumn('sort_order'))
            ->orderBy($episode->qualifyColumn('number'))
            ->orderBy($episode->qualifyColumn('id'));
    }

    public function watchableEpisode(CatalogTitle $catalogTitle, ?User $user, int $episodeId): ?Episode
    {
        return $this->watchableEpisodes($catalogTitle, $user)->where((new Episode)->qualifyColumn('id'), $episodeId)->first();
    }

    public function firstWatchableEpisode(CatalogTitle $catalogTitle, ?User $user): ?Episode
    {
        return $this->watchableEpisodes($catalogTitle, $user)->first();
    }

    public function nextWatchableEpisode(CatalogTitle $catalogTitle, ?User $user, Episode $episode): ?Episode
    {
        $current = $this->watchableEpisodes($catalogTitle, $user)
            ->where((new Episode)->qualifyColumn('id'), $episode->id)
            ->first();

        if ($current === null) {
            return $this->firstWatchableEpisode($catalogTitle, $user);
        }

        $episodeTable = (new Episode)->getTable();
        $seasonTable = (new Season)->getTable();
        $columns = [
            $seasonTable.'.kind',
            $seasonTable.'.sort_order',
            $seasonTable.'.number',
            $seasonTable.'.id',
            $episodeTable.'.kind',
            $episodeTable.'.sort_order',
            $episodeTable.'.number',
            $episodeTable.'.id',
        ];
        $values = [
            $current->season_order_kind,
            $current->season_order_sort,
            $current->season_order_number,
            $current->season_order_id,
            $current->kind->value,
            $current->sort_order,
            $current->number,
            $current->id,
        ];
        $query = $this->watchableEpisodes($catalogTitle, $user);
        $query->where(function (Builder $query) use ($columns, $values): void {
            foreach ($columns as $index => $column) {
                $query->orWhere(function (Builder $branch) use ($columns, $values, $index, $column): void {
                    foreach (array_slice($columns, 0, $index) as $prefixIndex => $prefixColumn) {
                        $branch->where($prefixColumn, $values[$prefixIndex]);
                    }

                    $branch->where($column, '>', $values[$index]);
                });
            }
        });

        return $query->first();
    }

    /** @return Collection<int, Episode> */
    public function episodesForSeason(CatalogTitle $catalogTitle, Season $season, ?User $user): Collection
    {
        $episodes = Episode::query()
            ->availableTo($user)
            ->where('season_id', $season->id)
            ->whereIn(
                'id',
                $this->availableMedia($catalogTitle, $user)
                    ->where('season_id', $season->id)
                    ->whereNotNull('episode_id')
                    ->select('episode_id')
                    ->groupBy('episode_id'),
            )
            ->select(['id', 'season_id', 'number', 'kind', 'sort_order', 'title', 'released_at', 'summary'])
            ->with([
                'licensedMedia' => fn (HasMany $query): HasMany => $query
                    ->availableTo($user)
                    ->forAvailableReleases($user)
                    ->withPlaybackLocation()
                    ->where('catalog_title_id', $catalogTitle->id)
                    ->select([
                        'id',
                        'catalog_title_id',
                        'season_id',
                        'episode_id',
                        'title',
                        'path',
                        'playback_url',
                        'quality',
                        'translation_name',
                        'variant_type',
                        'variant_name',
                        'variant_key',
                        'has_subtitles',
                        'format',
                        'source_url',
                    ])
                    ->latest('published_at')
                    ->latest(),
            ])
            ->orderBy('kind')
            ->orderBy('sort_order')
            ->orderBy('number')
            ->orderBy('id')
            ->get();

        $episodes->each(function (Episode $episode) use ($catalogTitle, $season): void {
            $episode->setRelation('season', $season);
            $episode->licensedMedia->each(function (LicensedMedia $media) use ($catalogTitle, $season, $episode): void {
                $media->setRelation('catalogTitle', $catalogTitle);
                $media->setRelation('season', $season);
                $media->setRelation('episode', $episode);
            });
        });
        $season->setRelation('episodes', $episodes);

        return $episodes;
    }

    public function bestMediaForEpisode(
        CatalogTitle $catalogTitle,
        ?User $user,
        Episode $episode,
        ?string $variant = null,
        ?string $quality = null,
        ?string $format = null,
    ): ?LicensedMedia {
        $mediaItems = $this->playbackMedia($catalogTitle, $user)
            ->where('episode_id', $episode->id)
            ->latest('published_at')
            ->latest()
            ->get();

        return $this->preferredMedia($mediaItems, $variant, $quality, $format);
    }

    /** @param Collection<int, LicensedMedia> $mediaItems */
    public function preferredMedia(
        Collection $mediaItems,
        ?string $variant = null,
        ?string $quality = null,
        ?string $format = null,
    ): ?LicensedMedia {
        foreach ([$variant, $quality, $format] as $index => $requestedValue) {
            if ($requestedValue === null) {
                continue;
            }

            $matches = $mediaItems->filter(function (LicensedMedia $media) use ($index, $requestedValue): bool {
                $actualValue = match ($index) {
                    0 => $this->mediaVariantKey($media),
                    1 => $this->mediaQuality($media),
                    default => $this->mediaFormat($media),
                };

                return $actualValue !== null && Str::lower($actualValue) === Str::lower($requestedValue);
            })->values();

            if ($matches->isNotEmpty()) {
                $mediaItems = $matches;
            }
        }

        return $mediaItems->first();
    }

    /** @return array{variant: string, quality: string, format: string} */
    public function mediaProfile(LicensedMedia $media): array
    {
        return [
            'variant' => $this->mediaVariantKey($media) ?? '',
            'quality' => $this->mediaQuality($media) ?? '',
            'format' => $this->mediaFormat($media) ?? '',
        ];
    }

    public function titleMedia(CatalogTitle $catalogTitle, ?User $user): ?LicensedMedia
    {
        return $this->playbackMedia($catalogTitle, $user)
            ->whereNull('episode_id')
            ->latest('published_at')
            ->latest()
            ->first();
    }

    public function findAvailableMedia(CatalogTitle $catalogTitle, ?User $user, int $mediaId): ?LicensedMedia
    {
        return $this->playbackMedia($catalogTitle, $user)->find($mediaId);
    }

    /** @return Builder<LicensedMedia> */
    public function availableMedia(CatalogTitle $catalogTitle, ?User $user): Builder
    {
        return LicensedMedia::query()
            ->availableTo($user)
            ->forAvailableReleases($user)
            ->withPlaybackLocation()
            ->where('catalog_title_id', $catalogTitle->id);
    }

    /** @return Builder<LicensedMedia> */
    private function playbackMedia(CatalogTitle $catalogTitle, ?User $user): Builder
    {
        return $this->availableMedia($catalogTitle, $user)->select([
            'id',
            'catalog_title_id',
            'season_id',
            'episode_id',
            'title',
            'path',
            'playback_url',
            'quality',
            'translation_name',
            'variant_type',
            'variant_name',
            'variant_key',
            'has_subtitles',
            'format',
            'source_url',
        ]);
    }

    private function mediaVariantKey(LicensedMedia $media): ?string
    {
        if (is_string($media->variant_key) && $media->variant_key !== '') {
            return $media->variant_key;
        }

        $url = $this->mediaUrl($media);

        return $url !== null
            ? $this->mediaMetadata->playbackVariant($media->title, $media->source_url, $url)['variant_key']
            : null;
    }

    private function mediaQuality(LicensedMedia $media): ?string
    {
        if (is_string($media->quality) && $media->quality !== '') {
            return $media->quality;
        }

        $url = $this->mediaUrl($media);

        return $url !== null ? $this->mediaMetadata->quality($media->title, $url) : null;
    }

    private function mediaFormat(LicensedMedia $media): ?string
    {
        if (is_string($media->format) && $media->format !== '') {
            return $media->format;
        }

        $url = $this->mediaUrl($media);

        return $url !== null ? $this->mediaMetadata->format($url) : null;
    }

    private function mediaUrl(LicensedMedia $media): ?string
    {
        $url = $media->playback_url ?: $media->path;

        return is_string($url) && trim($url) !== '' ? $url : null;
    }
}
