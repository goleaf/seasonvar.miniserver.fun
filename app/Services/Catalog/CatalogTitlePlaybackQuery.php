<?php

declare(strict_types=1);

namespace App\Services\Catalog;

use App\DTOs\CatalogEpisodeNavigation;
use App\Enums\ReleaseKind;
use App\Models\CatalogTitle;
use App\Models\Episode;
use App\Models\LicensedMedia;
use App\Models\Season;
use App\Models\User;
use App\Services\Media\ExternalMediaMetadata;
use Illuminate\Container\Attributes\Scoped;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use LogicException;

#[Scoped]
final class CatalogTitlePlaybackQuery
{
    /** @var array<string, CatalogTitle> */
    private array $visibleTitles = [];

    /** @var array<string, Collection<int, Season>> */
    private array $seasonSummaryCollections = [];

    public function __construct(
        private readonly CatalogTitleQuery $titles,
        private readonly ExternalMediaMetadata $mediaMetadata,
    ) {}

    public function visibleTitle(int $catalogTitleId, ?User $user): CatalogTitle
    {
        $key = $this->requestKey($catalogTitleId, $user);

        return $this->visibleTitles[$key] ??= $this->titles
            ->visibleTo($user)
            ->select([
                'id',
                'slug',
                'title',
                'poster_url',
                'is_published',
                'publication_status',
                'audience',
                'available_from',
                'available_until',
                'deleted_at',
            ])
            ->findOrFail($catalogTitleId);
    }

    public function rememberVisibleTitle(CatalogTitle $catalogTitle, ?User $user): void
    {
        $this->visibleTitles[$this->requestKey($catalogTitle->id, $user)] = $catalogTitle;
    }

    public function forget(int $catalogTitleId, ?User $user): void
    {
        $key = $this->requestKey($catalogTitleId, $user);

        unset($this->visibleTitles[$key], $this->seasonSummaryCollections[$key]);
    }

    /** @return Collection<int, Season> */
    public function seasonSummaries(CatalogTitle $catalogTitle, ?User $user): Collection
    {
        $key = $this->requestKey($catalogTitle->id, $user);

        if (isset($this->seasonSummaryCollections[$key])) {
            return $this->seasonSummaryCollections[$key];
        }

        $seasons = $catalogTitle->seasons()
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
                'publication_status',
                'audience',
                'available_from',
                'available_until',
                'deleted_at',
            ])
            ->get();

        if ($seasons->isEmpty()) {
            return $this->seasonSummaryCollections[$key] = $seasons;
        }

        $episode = new Episode;
        $media = new LicensedMedia;
        $seasonIds = $seasons->modelKeys();
        $episodeCounts = $this->watchableEpisodes($catalogTitle, $user)
            ->whereIn($episode->qualifyColumn('season_id'), $seasonIds)
            ->reorder()
            ->select($episode->qualifyColumn('season_id'))
            ->selectRaw('COUNT(*) AS aggregate_count')
            ->groupBy($episode->qualifyColumn('season_id'))
            ->pluck('aggregate_count', $episode->qualifyColumn('season_id'));
        $mediaCounts = $this->availableMedia($catalogTitle, $user)
            ->whereIn($media->qualifyColumn('season_id'), $seasonIds)
            ->select($media->qualifyColumn('season_id'))
            ->selectRaw('COUNT(*) AS aggregate_count')
            ->groupBy($media->qualifyColumn('season_id'))
            ->pluck('aggregate_count', $media->qualifyColumn('season_id'));

        $seasons->each(function (Season $season) use ($episodeCounts, $mediaCounts): void {
            $season->setAttribute('available_episodes_count', (int) $episodeCounts->get($season->id, 0));
            $season->setAttribute('available_media_count', (int) $mediaCounts->get($season->id, 0));
        });

        return $this->seasonSummaryCollections[$key] = $seasons;
    }

    private function requestKey(int $catalogTitleId, ?User $user): string
    {
        if ($user === null) {
            return $catalogTitleId.'|guest';
        }

        $userKey = $user->getAuthIdentifier();

        return $catalogTitleId.'|user:'.($userKey !== null ? (string) $userKey : 'object:'.spl_object_id($user));
    }

    /** @return Builder<Episode> */
    public function watchableEpisodes(CatalogTitle $catalogTitle, ?User $user): Builder
    {
        return $this->watchableEpisodeQuery($user, $catalogTitle->id);
    }

    /** @return Builder<Episode> */
    public function watchableEpisodesForVisibleTitles(?User $user): Builder
    {
        return $this->watchableEpisodeQuery($user);
    }

    /** @return Builder<Episode> */
    private function watchableEpisodeQuery(?User $user, ?int $catalogTitleId = null): Builder
    {
        $episode = new Episode;
        $media = new LicensedMedia;
        $season = new Season;
        $availableMedia = LicensedMedia::query()
            ->availableTo($user)
            ->withPlaybackLocation()
            ->withoutKnownFailures()
            ->whereColumn($media->qualifyColumn('episode_id'), $episode->qualifyColumn('id'))
            ->whereColumn($media->qualifyColumn('season_id'), $episode->qualifyColumn('season_id'))
            ->whereColumn($media->qualifyColumn('catalog_title_id'), $season->qualifyColumn('catalog_title_id'))
            ->selectRaw('1');

        $query = Episode::query()
            ->availableTo($user)
            ->join($season->getTable(), $season->qualifyColumn('id'), '=', $episode->qualifyColumn('season_id'))
            ->whereExists($availableMedia->toBase());

        if ($catalogTitleId !== null) {
            $query
                ->where($season->qualifyColumn('catalog_title_id'), $catalogTitleId)
                ->whereExists(
                    $this->titles->visibleTo($user)->whereKey($catalogTitleId)->selectRaw('1')->toBase(),
                )
                ->whereIn(
                    $episode->qualifyColumn('season_id'),
                    Season::query()
                        ->availableTo($user)
                        ->where('catalog_title_id', $catalogTitleId)
                        ->select('id'),
                );
        } else {
            $query
                ->whereExists(
                    $this->titles
                        ->visibleTo($user)
                        ->whereColumn((new CatalogTitle)->qualifyColumn('id'), $season->qualifyColumn('catalog_title_id'))
                        ->selectRaw('1')
                        ->toBase(),
                )
                ->whereExists(
                    Season::query()
                        ->availableTo($user)
                        ->whereColumn($season->qualifyColumn('id'), $episode->qualifyColumn('season_id'))
                        ->selectRaw('1')
                        ->toBase(),
                );
        }

        return $query
            ->select([
                $episode->qualifyColumn('id'),
                $episode->qualifyColumn('season_id'),
                $episode->qualifyColumn('number'),
                $episode->qualifyColumn('kind'),
                $episode->qualifyColumn('sort_order'),
                $episode->qualifyColumn('publication_status'),
                $episode->qualifyColumn('audience'),
                $episode->qualifyColumn('available_from'),
                $episode->qualifyColumn('available_until'),
                $episode->qualifyColumn('deleted_at'),
            ])
            ->addSelect([
                $season->qualifyColumn('kind').' as season_order_kind',
                $season->qualifyColumn('sort_order').' as season_order_sort',
                $season->qualifyColumn('number').' as season_order_number',
                $season->qualifyColumn('id').' as season_order_id',
                $season->qualifyColumn('catalog_title_id').' as playback_catalog_title_id',
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

    /** @return Builder<Episode> */
    public function orderedEpisodesForVisibleTitles(?User $user): Builder
    {
        $episode = new Episode;
        $season = new Season;

        return Episode::query()
            ->withTrashed()
            ->join($season->getTable(), $season->qualifyColumn('id'), '=', $episode->qualifyColumn('season_id'))
            ->whereIn(
                $season->qualifyColumn('catalog_title_id'),
                $this->titles->visibleTo($user)->select('id'),
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
                $season->qualifyColumn('catalog_title_id').' as playback_catalog_title_id',
            ]);
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

        return $this->adjacentEpisode($catalogTitle, $user, $current, true);
    }

    /**
     * @param  Collection<int, Episode>  $activeSeasonEpisodes
     * @param  Collection<int, Season>  $seasonSummaries
     */
    public function episodeNavigation(
        CatalogTitle $catalogTitle,
        Season $season,
        ?User $user,
        Episode $episode,
        Collection $activeSeasonEpisodes,
        Collection $seasonSummaries,
    ): CatalogEpisodeNavigation {
        if ((int) $season->catalog_title_id !== $catalogTitle->id
            || (int) $episode->season_id !== $season->id
            || $activeSeasonEpisodes->contains(
                fn (Episode $candidate): bool => (int) $candidate->season_id !== $season->id,
            )) {
            return new CatalogEpisodeNavigation;
        }

        $episodeKind = $this->releaseKindValue($episode->kind);
        $seasonKind = $this->releaseKindValue($season->kind);

        if ($episodeKind === null || $seasonKind === null) {
            return new CatalogEpisodeNavigation;
        }

        $episodeLane = $activeSeasonEpisodes
            ->filter(
                fn (Episode $candidate): bool => $this->releaseKindValue($candidate->kind) === $episodeKind,
            )
            ->values();
        $episodeIndex = $episodeLane->search(
            fn (Episode $candidate): bool => $candidate->id === $episode->id,
        );

        if ($episodeIndex === false) {
            return new CatalogEpisodeNavigation;
        }

        $previous = $episodeIndex > 0 ? $episodeLane->get($episodeIndex - 1) : null;
        $next = $episodeIndex < $episodeLane->count() - 1 ? $episodeLane->get($episodeIndex + 1) : null;
        $seasonLane = $seasonSummaries
            ->filter(
                fn (Season $candidate): bool => (int) $candidate->catalog_title_id === $catalogTitle->id
                    && $this->releaseKindValue($candidate->kind) === $seasonKind
                    && (int) $candidate->getAttribute('available_episodes_count') > 0,
            )
            ->values();
        $seasonIndex = $seasonLane->search(
            fn (Season $candidate): bool => $candidate->id === $season->id,
        );

        if ($seasonIndex === false) {
            return new CatalogEpisodeNavigation;
        }

        $current = clone $episode;
        $current->setAttribute('season_order_kind', $seasonKind);
        $current->setAttribute('season_order_sort', $season->sort_order);
        $current->setAttribute('season_order_number', $season->number);
        $current->setAttribute('season_order_id', $season->id);

        if ($previous === null && $seasonIndex > 0) {
            $previous = $this->adjacentEpisode($catalogTitle, $user, $current, false);
        }

        if ($next === null && $seasonIndex < $seasonLane->count() - 1) {
            $next = $this->adjacentEpisode($catalogTitle, $user, $current, true);
        }

        return new CatalogEpisodeNavigation(previous: $previous, next: $next);
    }

    /** @return Collection<int, Episode> */
    public function episodesForSeason(
        CatalogTitle $catalogTitle,
        Season $season,
        ?User $user,
        bool $withMedia = true,
    ): Collection {
        $availableMediaIds = $this->availableMedia($catalogTitle, $user)
            ->select((new LicensedMedia)->qualifyColumn('id'));
        $episodesQuery = Episode::query()
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
            ->select([
                'id',
                'season_id',
                'number',
                'kind',
                'sort_order',
                'title',
                'released_at',
                'summary',
                'publication_status',
                'audience',
                'available_from',
                'available_until',
                'deleted_at',
            ])
            ->withCount([
                'licensedMedia as available_media_count' => fn (Builder $query): Builder => $query
                    ->whereIn((new LicensedMedia)->qualifyColumn('id'), clone $availableMediaIds)
                    ->where('catalog_title_id', $catalogTitle->id),
            ]);

        if ($withMedia) {
            $episodesQuery->with([
                'licensedMedia' => function (Relation $relation) use ($availableMediaIds, $catalogTitle): void {
                    if (! $relation instanceof HasMany) {
                        throw new LogicException('Episode media eager loading requires a has-many relation.');
                    }

                    $relation
                        ->whereIn((new LicensedMedia)->qualifyColumn('id'), clone $availableMediaIds)
                        ->where('catalog_title_id', $catalogTitle->id)
                        ->select([
                            'id',
                            'catalog_title_id',
                            'season_id',
                            'episode_id',
                            'title',
                            'storage_disk',
                            'path',
                            'playback_url',
                            'duration_seconds',
                            'quality',
                            'translation_name',
                            'variant_type',
                            'variant_name',
                            'variant_key',
                            'has_subtitles',
                            'format',
                            'file_size_bytes',
                            'file_size_checked_at',
                            'file_size_check_status',
                            'source_url',
                            'status',
                            'published_at',
                            'audience',
                            'available_from',
                            'available_until',
                            'check_status',
                            'health_status',
                            'last_http_status',
                            'checked_at',
                            'deleted_at',
                        ])
                        ->latest('published_at')
                        ->latest();
                },
            ]);
        }

        $episodes = $episodesQuery
            ->orderBy('kind')
            ->orderBy('sort_order')
            ->orderBy('number')
            ->orderBy('id')
            ->get();

        $episodes->each(function (Episode $episode) use ($catalogTitle, $season): void {
            $episode->setRelation('season', $season);

            if (! $episode->relationLoaded('licensedMedia')) {
                $episode->setRelation('licensedMedia', collect());
            }

            $episode->licensedMedia->each(function (LicensedMedia $media) use ($catalogTitle, $season, $episode): void {
                $media->setRelation('catalogTitle', $catalogTitle);
                $media->setRelation('season', $season);
                $media->setRelation('episode', $episode);
            });
        });
        $season->setRelation('episodes', $episodes);

        return $episodes;
    }

    /** @return Collection<int, LicensedMedia> */
    public function mediaForEpisode(
        CatalogTitle $catalogTitle,
        Season $season,
        Episode $episode,
        ?User $user,
    ): Collection {
        if ((int) $season->catalog_title_id !== $catalogTitle->id || (int) $episode->season_id !== $season->id) {
            return collect();
        }

        $mediaItems = $this->playbackMedia($catalogTitle, $user)
            ->where('season_id', $season->id)
            ->where('episode_id', $episode->id)
            ->latest('published_at')
            ->latest()
            ->get();

        $mediaItems->each(function (LicensedMedia $media) use ($catalogTitle, $season, $episode): void {
            $media->setRelation('catalogTitle', $catalogTitle);
            $media->setRelation('season', $season);
            $media->setRelation('episode', $episode);
        });

        return $mediaItems;
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
            ->withoutKnownFailures()
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
            'storage_disk',
            'path',
            'playback_url',
            'duration_seconds',
            'quality',
            'translation_name',
            'variant_type',
            'variant_name',
            'variant_key',
            'has_subtitles',
            'format',
            'file_size_bytes',
            'file_size_checked_at',
            'file_size_check_status',
            'source_url',
            'status',
            'published_at',
            'audience',
            'available_from',
            'available_until',
            'check_status',
            'health_status',
            'last_http_status',
            'checked_at',
            'deleted_at',
        ]);
    }

    private function adjacentEpisode(
        CatalogTitle $catalogTitle,
        ?User $user,
        Episode $current,
        bool $next,
    ): ?Episode {
        $episode = new Episode;
        $season = new Season;
        $episodeTable = $episode->getTable();
        $seasonTable = $season->getTable();
        $seasonKind = $this->releaseKindValue($current->getAttribute('season_order_kind'));
        $episodeKind = $this->releaseKindValue($current->kind);

        if ($seasonKind === null || $episodeKind === null) {
            return null;
        }

        $order = [
            [$seasonTable.'.sort_order', (int) $current->getAttribute('season_order_sort')],
            ["CASE WHEN {$seasonTable}.number IS NULL THEN 1 ELSE 0 END", $current->getAttribute('season_order_number') === null ? 1 : 0],
            ["COALESCE({$seasonTable}.number, 0)", (int) ($current->getAttribute('season_order_number') ?? 0)],
            [$seasonTable.'.id', (int) $current->getAttribute('season_order_id')],
            [$episodeTable.'.sort_order', (int) $current->sort_order],
            [$episodeTable.'.number', $current->number],
            [$episodeTable.'.id', $current->id],
        ];
        $query = $this->watchableEpisodes($catalogTitle, $user)
            ->where($season->qualifyColumn('kind'), $seasonKind)
            ->where($episode->qualifyColumn('kind'), $episodeKind)
            ->reorder();
        $operator = $next ? '>' : '<';
        $direction = $next ? 'asc' : 'desc';

        $query->where(function (Builder $query) use ($order, $operator): void {
            foreach ($order as $index => [$expression, $value]) {
                $query->orWhere(function (Builder $branch) use ($order, $index, $expression, $operator, $value): void {
                    foreach (array_slice($order, 0, $index) as [$prefixExpression, $prefixValue]) {
                        $branch->whereRaw("{$prefixExpression} = ?", [$prefixValue]);
                    }

                    $branch->whereRaw("{$expression} {$operator} ?", [$value]);
                });
            }
        });

        foreach ($order as [$expression]) {
            $query->orderByRaw("{$expression} {$direction}");
        }

        return $query->first();
    }

    private function releaseKindValue(ReleaseKind|string|null $kind): ?string
    {
        if ($kind instanceof ReleaseKind) {
            return $kind->value;
        }

        return is_string($kind) ? ReleaseKind::tryFrom($kind)?->value : null;
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
        $url = trim($media->playback_url ?: $media->path);

        return $url !== '' ? $url : null;
    }
}
