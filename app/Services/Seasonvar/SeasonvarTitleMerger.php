<?php

namespace App\Services\Seasonvar;

use App\Models\CatalogTitle;
use App\Models\CatalogTitleAlias;
use App\Models\CatalogTitleRating;
use App\Models\CatalogTitleReview;
use App\Models\CatalogTitleSlug;
use App\Models\Episode;
use App\Models\LicensedMedia;
use App\Models\Season;
use App\Services\Catalog\Search\CatalogSearchIndexer;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SeasonvarTitleMerger
{
    private const CATALOG_RELATIONS = [
        'taxonomies',
        'genres',
        'countries',
        'actors',
        'directors',
        'ageRatings',
        'translations',
        'statuses',
        'networks',
        'studios',
        'tags',
    ];

    public function __construct(
        private readonly SeasonvarImportGroupKey $groupKeys,
        private readonly CatalogSearchIndexer $searchIndexer,
    ) {}

    /**
     * @param  (callable(string, array<string, mixed>): void)|null  $progress
     * @return array{groups: int, titles: int, seasons: int, episodes: int}
     */
    public function merge(?callable $progress = null): array
    {
        $groups = $this->duplicateTitleGroups();
        $result = [
            'groups' => $groups->count(),
            'titles' => 0,
            'seasons' => 0,
            'episodes' => 0,
        ];

        foreach ($groups as $group) {
            $titles = CatalogTitle::query()
                ->with([...self::CATALOG_RELATIONS, 'aliases', 'ratings', 'reviews', 'seasons.episodes'])
                ->whereKey($group->pluck('id'))
                ->orderBy('id')
                ->get();

            if ($titles->count() < 2) {
                continue;
            }

            $groupResult = DB::transaction(fn (): array => $this->mergeGroup($titles));
            $result['titles'] += $groupResult['titles'];
            $result['seasons'] += $groupResult['seasons'];
            $result['episodes'] += $groupResult['episodes'];

            $canonical = $titles->first();

            if ($canonical !== null) {
                $this->searchIndexer->synchronizeTitleIds([$canonical->id]);
            }

            $this->report($progress, 'seasonvar-title-merged', [
                'catalog_title_id' => $canonical?->id,
                'title' => $canonical?->title,
                'slug' => $canonical?->slug,
                'merged_titles' => $groupResult['titles'],
                'merged_seasons' => $groupResult['seasons'],
                'merged_episodes' => $groupResult['episodes'],
            ]);
        }

        $this->report($progress, 'seasonvar-title-merge-complete', $result);

        return $result;
    }

    /**
     * Merge already-imported season-page duplicates into a chosen canonical public title.
     *
     * This is intentionally stricter than title matching: a duplicate must belong to the same
     * source/type/title family and share at least one concrete season source URL hash.
     *
     * @param  (callable(string, array<string, mixed>): void)|null  $progress
     * @return array{groups: int, titles: int, seasons: int, episodes: int}
     */
    public function mergeForCanonicalSlug(string $slug, ?callable $progress = null): array
    {
        $canonical = CatalogTitle::query()
            ->where('slug', $slug)
            ->firstOrFail();
        $duplicates = $this->seasonFamilyDuplicatesFor($canonical);
        $result = [
            'groups' => $duplicates->isEmpty() ? 0 : 1,
            'titles' => 0,
            'seasons' => 0,
            'episodes' => 0,
        ];

        if ($duplicates->isEmpty()) {
            $this->report($progress, 'seasonvar-title-merge-complete', $result);

            return $result;
        }

        $orderedIds = collect([$canonical->id])
            ->merge($duplicates->modelKeys())
            ->values();
        $titlesById = CatalogTitle::query()
            ->with([...self::CATALOG_RELATIONS, 'aliases', 'ratings', 'reviews', 'seasons.episodes'])
            ->whereKey($orderedIds)
            ->get()
            ->keyBy('id');
        $titles = new EloquentCollection($orderedIds
            ->map(fn (int $id): ?CatalogTitle => $titlesById->get($id))
            ->filter()
            ->values()
            ->all());

        if ($titles->count() < 2) {
            $this->report($progress, 'seasonvar-title-merge-complete', $result);

            return $result;
        }

        $groupResult = DB::transaction(fn (): array => $this->mergeGroup($titles));

        $result['titles'] = $groupResult['titles'];
        $result['seasons'] = $groupResult['seasons'];
        $result['episodes'] = $groupResult['episodes'];

        $this->searchIndexer->synchronizeTitleIds($orderedIds);
        $canonical->refresh();

        $this->report($progress, 'seasonvar-title-merged', [
            'catalog_title_id' => $canonical->id,
            'title' => $canonical->title,
            'slug' => $canonical->slug,
            'merged_titles' => $groupResult['titles'],
            'merged_seasons' => $groupResult['seasons'],
            'merged_episodes' => $groupResult['episodes'],
        ]);
        $this->report($progress, 'seasonvar-title-merge-complete', $result);

        return $result;
    }

    /**
     * @return Collection<int, Collection<int, CatalogTitle>>
     */
    private function duplicateTitleGroups(): Collection
    {
        return CatalogTitle::query()
            ->whereNull('external_id')
            ->orderBy('source_id')
            ->orderBy('id')
            ->get()
            ->groupBy(fn (CatalogTitle $title): string => implode('|', [
                $title->source_id,
                $title->type,
                $this->groupKeys->forUrl($title->source_url, $title->source_url_hash),
            ]))
            ->filter(fn (Collection $titles): bool => $titles->count() > 1)
            ->sortByDesc(fn (Collection $titles): int => $titles->count())
            ->values();
    }

    /**
     * @return EloquentCollection<int, CatalogTitle>
     */
    private function seasonFamilyDuplicatesFor(CatalogTitle $canonical): EloquentCollection
    {
        $seasonHashes = Season::query()
            ->where('catalog_title_id', $canonical->id)
            ->whereNotNull('source_url_hash')
            ->pluck('source_url_hash')
            ->unique()
            ->values();

        if ($seasonHashes->isEmpty()) {
            return new EloquentCollection;
        }

        $canonicalTitleKey = $this->normalizedSeriesTitleKey($canonical->title);

        $duplicates = CatalogTitle::query()
            ->where('source_id', $canonical->source_id)
            ->where('type', $canonical->type)
            ->where('id', '!=', $canonical->id)
            ->whereHas('seasons', fn ($query) => $query->whereIn('source_url_hash', $seasonHashes))
            ->orderBy('id')
            ->get()
            ->filter(fn (CatalogTitle $title): bool => $this->normalizedSeriesTitleKey($title->title) === $canonicalTitleKey)
            ->values();

        return new EloquentCollection($duplicates->all());
    }

    /**
     * @param  EloquentCollection<int, CatalogTitle>  $titles
     * @return array{titles: int, seasons: int, episodes: int}
     */
    private function mergeGroup(EloquentCollection $titles): array
    {
        $canonical = $titles->firstOrFail();
        $mergedTitles = 0;
        $mergedSeasons = 0;
        $movedEpisodes = 0;
        $relationIds = $this->relationIds($canonical);

        foreach ($titles->slice(1) as $duplicate) {
            foreach ($this->relationIds($duplicate) as $relation => $ids) {
                $relationIds[$relation] = array_values(array_unique([
                    ...$relationIds[$relation],
                    ...$ids,
                ]));
            }

            foreach ($duplicate->seasons as $season) {
                $targetSeason = Season::query()->firstOrCreate(
                    [
                        'catalog_title_id' => $canonical->id,
                        'kind' => $season->kind,
                        'number' => $season->number,
                    ],
                    [
                        'source_page_id' => $season->source_page_id,
                        'title' => $season->title,
                        'source_url' => $season->source_url,
                        'source_url_hash' => $season->source_url_hash,
                        'sort_order' => $season->sort_order,
                        'publication_status' => $season->publication_status,
                        'audience' => $season->audience,
                        'available_from' => $season->available_from,
                        'available_until' => $season->available_until,
                    ],
                );

                $targetSeason->fill([
                    'source_page_id' => $season->source_page_id ?? $targetSeason->source_page_id,
                    'title' => $season->title ?? $targetSeason->title,
                    'source_url' => $season->source_url ?? $targetSeason->source_url,
                    'source_url_hash' => $season->source_url_hash ?? $targetSeason->source_url_hash,
                    'latest_episode_released_at' => $season->latest_episode_released_at ?? $targetSeason->latest_episode_released_at,
                    'episodes_released' => $season->episodes_released ?? $targetSeason->episodes_released,
                    'episodes_total' => $season->episodes_total ?? $targetSeason->episodes_total,
                    'translation_name' => $season->translation_name ?? $targetSeason->translation_name,
                    'release_status_text' => $season->release_status_text ?? $targetSeason->release_status_text,
                ])->save();

                $movedEpisodes += $this->mergeEpisodes($season, $targetSeason, $canonical);
                $this->moveMediaForSeason($season->id, $canonical, $targetSeason);

                $season->forceDelete();
                $mergedSeasons++;
            }

            $this->mergeAliases($canonical, $duplicate);
            $this->mergeRatings($canonical, $duplicate);
            $this->mergeReviews($canonical, $duplicate);
            $this->moveLooseMedia($duplicate, $canonical);
            $this->preservePublicSlugs($canonical, $duplicate);

            $duplicate->forceDelete();
            $mergedTitles++;
        }

        foreach ($relationIds as $relation => $ids) {
            $canonical->{$relation}()->sync($ids);
        }

        $this->refreshCanonicalTitle($canonical, $titles);

        return [
            'titles' => $mergedTitles,
            'seasons' => $mergedSeasons,
            'episodes' => $movedEpisodes,
        ];
    }

    private function preservePublicSlugs(CatalogTitle $canonical, CatalogTitle $duplicate): void
    {
        CatalogTitleSlug::query()
            ->whereBelongsTo($duplicate)
            ->update(['catalog_title_id' => $canonical->id]);

        CatalogTitleSlug::query()->firstOrCreate([
            'slug' => $duplicate->slug,
        ], [
            'catalog_title_id' => $canonical->id,
        ]);
    }

    /**
     * @return array<string, list<int>>
     */
    private function relationIds(CatalogTitle $title): array
    {
        return collect(self::CATALOG_RELATIONS)
            ->mapWithKeys(fn (string $relation): array => [
                $relation => $title->{$relation}->pluck('id')->all(),
            ])
            ->all();
    }

    private function mergeEpisodes(Season $fromSeason, Season $targetSeason, CatalogTitle $canonical): int
    {
        $moved = 0;

        foreach ($fromSeason->episodes as $episode) {
            $targetEpisode = Episode::query()
                ->where('season_id', $targetSeason->id)
                ->where('kind', $episode->kind)
                ->where('number', $episode->number)
                ->first();

            if ($targetEpisode === null) {
                $episode->season_id = $targetSeason->id;
                $episode->save();
                $this->moveMediaForEpisode($episode->id, $canonical, $targetSeason, $episode);
                $moved++;

                continue;
            }

            $this->moveMediaForEpisode($episode->id, $canonical, $targetSeason, $targetEpisode);

            $targetEpisode->fill([
                'source_page_id' => $targetEpisode->source_page_id ?? $episode->source_page_id,
                'title' => $targetEpisode->title ?? $episode->title,
                'source_url' => $targetEpisode->source_url ?? $episode->source_url,
                'source_url_hash' => $targetEpisode->source_url_hash ?? $episode->source_url_hash,
                'released_at' => $targetEpisode->released_at ?? $episode->released_at,
                'summary' => $targetEpisode->summary ?? $episode->summary,
            ])->save();

            $episode->forceDelete();
            $moved++;
        }

        return $moved;
    }

    private function moveMediaForEpisode(int $oldEpisodeId, CatalogTitle $canonical, Season $targetSeason, Episode $targetEpisode): void
    {
        LicensedMedia::query()
            ->where('episode_id', $oldEpisodeId)
            ->get()
            ->each(fn (LicensedMedia $media): LicensedMedia => $this->moveMedia($media, $canonical, $targetSeason, $targetEpisode));
    }

    private function moveMediaForSeason(int $oldSeasonId, CatalogTitle $canonical, Season $targetSeason): void
    {
        LicensedMedia::query()
            ->where('season_id', $oldSeasonId)
            ->whereNull('episode_id')
            ->get()
            ->each(fn (LicensedMedia $media): LicensedMedia => $this->moveMedia($media, $canonical, $targetSeason, null));
    }

    private function moveLooseMedia(CatalogTitle $duplicate, CatalogTitle $canonical): void
    {
        LicensedMedia::query()
            ->where('catalog_title_id', $duplicate->id)
            ->get()
            ->each(fn (LicensedMedia $media): LicensedMedia => $this->moveMedia($media, $canonical, null, null));
    }

    private function moveMedia(LicensedMedia $media, CatalogTitle $canonical, ?Season $season, ?Episode $episode): LicensedMedia
    {
        $existing = $this->matchingCanonicalMedia($media, $canonical);

        if ($existing !== null && $existing->isNot($media)) {
            $existing->fill([
                'season_id' => $season?->id ?? $existing->season_id,
                'episode_id' => $episode?->id ?? $existing->episode_id,
                'title' => $existing->title ?: $media->title,
                'storage_disk' => $media->storage_disk ?: $existing->storage_disk,
                'path' => $media->path ?: $existing->path,
                'playback_url' => $media->playback_url ?: $existing->playback_url,
                'duration_seconds' => $media->duration_seconds ?? $existing->duration_seconds,
                'status' => $media->status === 'published' ? 'published' : $existing->status,
                'published_at' => $media->published_at ?? $existing->published_at,
                'source_url' => $media->source_url ?? $existing->source_url,
                'quality' => $media->quality ?? $existing->quality,
                'translation_name' => $media->translation_name ?? $existing->translation_name,
                'format' => $media->format ?? $existing->format,
                'check_status' => $media->check_status ?? $existing->check_status,
                'last_http_status' => $media->last_http_status ?? $existing->last_http_status,
                'checked_at' => $media->checked_at ?? $existing->checked_at,
            ])->save();
            $media->forceDelete();

            return $existing;
        }

        $media->fill([
            'catalog_title_id' => $canonical->id,
            'season_id' => $season?->id ?? $media->season_id,
            'episode_id' => $episode?->id ?? $media->episode_id,
        ])->save();

        return $media;
    }

    private function matchingCanonicalMedia(LicensedMedia $media, CatalogTitle $canonical): ?LicensedMedia
    {
        if ($media->source_media_key !== null) {
            $match = LicensedMedia::query()
                ->where('catalog_title_id', $canonical->id)
                ->where('source_media_key', $media->source_media_key)
                ->first();

            if ($match !== null) {
                return $match;
            }
        }

        if ($media->playback_url === null) {
            return null;
        }

        return LicensedMedia::query()
            ->where('catalog_title_id', $canonical->id)
            ->where('playback_url', $media->playback_url)
            ->first();
    }

    private function mergeAliases(CatalogTitle $canonical, CatalogTitle $duplicate): void
    {
        foreach ($duplicate->aliases as $alias) {
            CatalogTitleAlias::query()->updateOrCreate(
                [
                    'catalog_title_id' => $canonical->id,
                    'type' => $alias->type,
                    'name_hash' => $alias->name_hash,
                ],
                [
                    'name' => $alias->name,
                    'source' => $alias->source,
                ],
            );
            $alias->delete();
        }
    }

    private function mergeRatings(CatalogTitle $canonical, CatalogTitle $duplicate): void
    {
        foreach ($duplicate->ratings as $rating) {
            CatalogTitleRating::query()->updateOrCreate(
                [
                    'catalog_title_id' => $canonical->id,
                    'provider' => $rating->provider,
                ],
                [
                    'rating' => $rating->rating,
                    'votes' => $rating->votes,
                    'raw_value' => $rating->raw_value,
                ],
            );
            $rating->delete();
        }
    }

    private function mergeReviews(CatalogTitle $canonical, CatalogTitle $duplicate): void
    {
        foreach ($duplicate->reviews as $review) {
            CatalogTitleReview::query()->updateOrCreate(
                [
                    'catalog_title_id' => $canonical->id,
                    'body_hash' => $review->body_hash,
                ],
                [
                    'source_page_id' => $review->source_page_id,
                    'author' => $review->author,
                    'body' => $review->body,
                    'published_at' => $review->published_at,
                ],
            );
            $review->delete();
        }
    }

    /**
     * @param  EloquentCollection<int, CatalogTitle>  $titles
     */
    private function refreshCanonicalTitle(CatalogTitle $canonical, EloquentCollection $titles): void
    {
        $canonical->fill([
            'title' => $this->preferredTitle($canonical->title, $titles->pluck('title')->filter()->all()),
            'year' => $titles->pluck('year')->filter()->min() ?: $canonical->year,
            'poster_url' => $titles->pluck('poster_url')->filter()->first() ?: $canonical->poster_url,
            'description' => $titles->pluck('description')->filter()->first() ?: $canonical->description,
            'original_title' => $this->preferredOriginalTitle($canonical, $titles),
            'indexed_at' => $titles->pluck('indexed_at')->filter()->max() ?: $canonical->indexed_at,
            'relation_metadata_version' => $titles->min('relation_metadata_version') ?? 0,
        ])->save();
    }

    /**
     * @param  EloquentCollection<int, CatalogTitle>  $titles
     */
    private function preferredOriginalTitle(CatalogTitle $canonical, EloquentCollection $titles): ?string
    {
        return collect([$canonical->original_title, ...$titles->pluck('original_title')->all()])
            ->filter(fn (?string $title): bool => $title !== null && ! $this->containsCyrillic($title))
            ->first();
    }

    /**
     * @param  list<string>  $titles
     */
    private function preferredTitle(string $currentTitle, array $titles): string
    {
        return collect([$currentTitle, ...$titles])
            ->filter()
            ->sortBy(fn (string $title): int => Str::length($title))
            ->first() ?? $currentTitle;
    }

    private function normalizedSeriesTitleKey(string $title): string
    {
        return Str::lower($this->seriesTitleKey($title));
    }

    private function seriesTitleKey(string $title): string
    {
        $title = Str::squish($title);
        $parts = explode('/', $title, 2);

        if (count($parts) === 2 && $this->containsCyrillic($parts[0]) && $this->containsCyrillic($parts[1])) {
            return Str::squish($parts[0]);
        }

        return $title;
    }

    private function containsCyrillic(string $value): bool
    {
        return preg_match('/\p{Cyrillic}/u', $value) === 1;
    }

    /**
     * @param  (callable(string, array<string, mixed>): void)|null  $progress
     * @param  array<string, mixed>  $context
     */
    private function report(?callable $progress, string $event, array $context = []): void
    {
        if ($progress === null) {
            return;
        }

        $progress($event, $context);
    }
}
