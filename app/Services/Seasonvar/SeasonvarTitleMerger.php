<?php

namespace App\Services\Seasonvar;

use App\Models\CatalogTitle;
use App\Models\CatalogTitleAlias;
use App\Models\CatalogTitleRating;
use App\Models\CatalogTitleSlug;
use App\Models\Episode;
use App\Models\LicensedMedia;
use App\Models\Season;
use App\Services\Api\V1\Sync\CatalogSyncChangePublisher;
use App\Services\Catalog\CatalogRecommendationCacheInvalidator;
use App\Services\Catalog\CatalogTitleRelationService;
use App\Services\Catalog\CatalogTitleUserDataMerger;
use App\Services\Catalog\Search\CatalogSearchIndexer;
use App\Services\Collections\CatalogCollectionItemService;
use App\Services\Comments\CommentTargetMergeService;
use App\Services\ContentRequests\ContentRequestTargetMergeService;
use App\Services\ReleaseCalendar\ReleaseCalendarTargetMergeService;
use App\Services\Reviews\ReviewMergeService;
use App\Services\Tags\TagCacheInvalidator;
use App\Services\Tags\TagTitleMergeService;
use App\Services\TechnicalIssues\TechnicalIssueTargetMergeService;
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
        private readonly CatalogSyncChangePublisher $syncChanges,
        private readonly CatalogCollectionItemService $collectionItems,
        private readonly CatalogTitleUserDataMerger $userData,
        private readonly CommentTargetMergeService $comments,
        private readonly ContentRequestTargetMergeService $contentRequests,
        private readonly TechnicalIssueTargetMergeService $technicalIssues,
        private readonly ReviewMergeService $reviews,
        private readonly TagTitleMergeService $tagTitles,
        private readonly TagCacheInvalidator $tagCache,
        private readonly CatalogTitleRelationService $titleRelations,
        private readonly CatalogRecommendationCacheInvalidator $recommendationCache,
        private readonly ReleaseCalendarTargetMergeService $releaseCalendar,
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
                ->with([...self::CATALOG_RELATIONS, 'aliases', 'ratings', 'seasons'])
                ->whereKey($group->pluck('id'))
                ->orderBy('id')
                ->get();

            if ($titles->count() < 2) {
                continue;
            }

            $duplicateSlugs = $titles->slice(1)->pluck('slug')->filter()->values();
            $groupResult = $this->mergeGroup($titles);
            $this->tagCache->publicChanged($titles->modelKeys());
            $this->recommendationCache->publicSignalsChanged('title-merge');
            $result['titles'] += $groupResult['titles'];
            $result['seasons'] += $groupResult['seasons'];
            $result['episodes'] += $groupResult['episodes'];

            $canonical = $titles->first();

            if ($canonical !== null) {
                $this->searchIndexer->synchronizeTitleIds([$canonical->id]);
                $this->syncChanges->publishUpsert($canonical);
            }

            foreach ($duplicateSlugs as $duplicateSlug) {
                $this->syncChanges->publishDelete((string) $duplicateSlug);
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
            ->with([...self::CATALOG_RELATIONS, 'aliases', 'ratings', 'seasons'])
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

        $duplicateSlugs = $titles->slice(1)->pluck('slug')->filter()->values();
        $groupResult = $this->mergeGroup($titles);
        $this->tagCache->publicChanged($titles->modelKeys());
        $this->recommendationCache->publicSignalsChanged('title-merge');

        $result['titles'] = $groupResult['titles'];
        $result['seasons'] = $groupResult['seasons'];
        $result['episodes'] = $groupResult['episodes'];

        $this->searchIndexer->synchronizeTitleIds($orderedIds);
        $canonical->refresh();
        $this->syncChanges->publishUpsert($canonical);

        foreach ($duplicateSlugs as $duplicateSlug) {
            $this->syncChanges->publishDelete((string) $duplicateSlug);
        }

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
        return $this->mergeOverlappingGroups(
            $this->legacyDuplicateTitleGroups()
                ->concat($this->duplicateSeasonFamilyGroups()),
        );
    }

    /**
     * @return Collection<int, Collection<int, CatalogTitle>>
     */
    private function legacyDuplicateTitleGroups(): Collection
    {
        return CatalogTitle::query()
            ->whereNull('external_id')
            ->orderBy('source_id')
            ->orderBy('id')
            ->get()
            ->toBase()
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
     * Seasonvar gives separate provider IDs to season pages of one series. Only treat titles as
     * one family when their normalized title/URL family matches and they share an exact season URL.
     *
     * @return Collection<int, Collection<int, CatalogTitle>>
     */
    private function duplicateSeasonFamilyGroups(): Collection
    {
        $duplicateSeasonHashes = DB::table('seasons as duplicate_seasons')
            ->select('duplicate_seasons.source_url_hash')
            ->join('catalog_titles as duplicate_titles', 'duplicate_titles.id', '=', 'duplicate_seasons.catalog_title_id')
            ->whereNull('duplicate_seasons.deleted_at')
            ->whereNull('duplicate_titles.deleted_at')
            ->whereNotNull('duplicate_seasons.source_url_hash')
            ->groupBy('duplicate_seasons.source_url_hash')
            ->havingRaw('COUNT(DISTINCT duplicate_seasons.catalog_title_id) > 1');
        $seasonHashesByTitle = DB::table('seasons')
            ->select(['seasons.catalog_title_id', 'seasons.source_url_hash'])
            ->join('catalog_titles', 'catalog_titles.id', '=', 'seasons.catalog_title_id')
            ->whereNull('seasons.deleted_at')
            ->whereNull('catalog_titles.deleted_at')
            ->whereIn('seasons.source_url_hash', $duplicateSeasonHashes)
            ->orderBy('seasons.catalog_title_id')
            ->get()
            ->groupBy(fn (object $row): int => (int) $row->catalog_title_id)
            ->map(fn (Collection $rows): Collection => $rows
                ->map(fn (object $row): string => (string) $row->source_url_hash)
                ->filter(fn (string $hash): bool => $hash !== '')
                ->unique()
                ->values());

        if ($seasonHashesByTitle->isEmpty()) {
            return collect();
        }

        return CatalogTitle::query()
            ->select(['id', 'source_id', 'type', 'title', 'source_url', 'source_url_hash'])
            ->whereKey($seasonHashesByTitle->keys())
            ->orderBy('source_id')
            ->orderBy('id')
            ->get()
            ->groupBy(fn (CatalogTitle $title): string => implode('|', [
                $title->source_id,
                $title->type,
                $this->normalizedSeriesTitleKey($title->title),
                $this->groupKeys->forUrl((string) $title->source_url, (string) $title->source_url_hash),
            ]))
            ->flatMap(fn (Collection $titles): Collection => $this->connectedSeasonFamilies($titles, $seasonHashesByTitle))
            ->values();
    }

    /**
     * @param  Collection<int, CatalogTitle>  $titles
     * @param  Collection<int|string, Collection<int, non-empty-string>>  $seasonHashesByTitle
     * @return Collection<int, Collection<int, CatalogTitle>>
     */
    private function connectedSeasonFamilies(
        Collection $titles,
        Collection $seasonHashesByTitle,
    ): Collection {
        $remaining = $titles->keyBy('id');
        $families = collect();

        while ($remaining->isNotEmpty()) {
            $seed = $remaining->shift();

            if (! $seed instanceof CatalogTitle) {
                continue;
            }

            $family = collect([$seed]);
            $seasonHashes = $seasonHashesByTitle->get($seed->id, collect());

            do {
                $matches = $remaining->filter(fn (CatalogTitle $title): bool => $seasonHashesByTitle
                    ->get($title->id, collect())
                    ->intersect($seasonHashes)
                    ->isNotEmpty());

                foreach ($matches as $id => $match) {
                    $family->push($match);
                    $seasonHashes = $seasonHashes
                        ->merge($seasonHashesByTitle->get($match->id, collect()))
                        ->unique();
                    $remaining->forget($id);
                }
            } while ($matches->isNotEmpty());

            if ($family->count() > 1) {
                $families->push($family->sortBy('id')->values());
            }
        }

        return $families;
    }

    /**
     * @param  Collection<int, Collection<int, CatalogTitle>>  $groups
     * @return Collection<int, Collection<int, CatalogTitle>>
     */
    private function mergeOverlappingGroups(Collection $groups): Collection
    {
        $titlesById = $groups
            ->flatMap(fn (Collection $group): Collection => $group)
            ->keyBy('id');
        $mergedIdGroups = [];

        foreach ($groups as $group) {
            $ids = $group->pluck('id')->map(fn (mixed $id): int => (int) $id)->values();
            $overlappingIndexes = collect($mergedIdGroups)
                ->filter(fn (Collection $existingIds): bool => $existingIds->intersect($ids)->isNotEmpty())
                ->keys();

            foreach ($overlappingIndexes as $index) {
                $ids = $ids->merge($mergedIdGroups[$index]);
                unset($mergedIdGroups[$index]);
            }

            $mergedIdGroups[] = $ids->unique()->sort()->values();
        }

        return collect($mergedIdGroups)
            ->map(fn (Collection $ids): Collection => $ids
                ->map(fn (int $id): ?CatalogTitle => $titlesById->get($id))
                ->filter()
                ->values())
            ->filter(fn (Collection $group): bool => $group->count() > 1)
            ->sortByDesc(fn (Collection $group): int => $group->count())
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

        foreach ($titles->slice(1) as $duplicate) {
            $seasons = $duplicate->seasons->values();

            foreach ($seasons->slice(0, -1) as $season) {
                $movedEpisodes += DB::transaction(
                    fn (): int => $this->mergeSeason($season, $canonical),
                    attempts: 3,
                );
                $season->unsetRelation('episodes');
                $mergedSeasons++;
            }

            $lastSeason = $seasons->last();
            $movedEpisodes += DB::transaction(function () use ($canonical, $duplicate, $lastSeason): int {
                $moved = $lastSeason instanceof Season
                    ? $this->mergeSeason($lastSeason, $canonical)
                    : 0;
                $canonical->load(self::CATALOG_RELATIONS);
                $relationIds = $this->relationIds($canonical);

                foreach ($this->relationIds($duplicate) as $relation => $ids) {
                    $relationIds[$relation] = array_values(array_unique([
                        ...$relationIds[$relation],
                        ...$ids,
                    ]));
                }

                $this->comments->moveTitle($duplicate, $canonical);
                $this->contentRequests->moveTitle($duplicate->id, $canonical->id);
                $this->technicalIssues->moveTitle($duplicate->id, $canonical->id);
                $this->releaseCalendar->moveTitle($duplicate, $canonical);
                $this->mergeAliases($canonical, $duplicate);
                $this->mergeRatings($canonical, $duplicate);
                $this->reviews->merge($canonical, $duplicate);
                $this->userData->moveTitle($duplicate, $canonical);
                $this->moveLooseMedia($duplicate, $canonical);
                $this->preservePublicSlugs($canonical, $duplicate);
                $this->collectionItems->mergeTitle($canonical, $duplicate);
                $this->tagTitles->moveTitle($canonical, $duplicate);
                $this->titleRelations->mergeTitle($canonical, $duplicate);

                foreach ($relationIds as $relation => $ids) {
                    $canonical->{$relation}()->sync($ids);
                }

                $this->refreshCanonicalTitle(
                    $canonical,
                    new EloquentCollection([$canonical, $duplicate]),
                );
                $duplicate->forceDelete();

                return $moved;
            }, attempts: 3);

            if ($lastSeason instanceof Season) {
                $lastSeason->unsetRelation('episodes');
                $mergedSeasons++;
            }

            $mergedTitles++;
        }

        return [
            'titles' => $mergedTitles,
            'seasons' => $mergedSeasons,
            'episodes' => $movedEpisodes,
        ];
    }

    private function mergeSeason(Season $season, CatalogTitle $canonical): int
    {
        $season->loadMissing('episodes');
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

        $targetSeason->loadMissing('episodes');
        $sourceEpisodeIds = $season->episodes->modelKeys();
        $sourceMedia = LicensedMedia::query()
            ->where('season_id', $season->id)
            ->orderBy('id')
            ->get();
        $mediaByEpisode = $sourceMedia->groupBy(
            static fn (LicensedMedia $media): int => (int) ($media->episode_id ?? 0),
        );
        $mediaLookup = $this->canonicalMediaLookup($sourceMedia, $canonical);
        $sourceMediaIds = $sourceMedia->modelKeys();
        $dependencyPresence = [
            'comments' => $this->comments->episodeIdsWithComments($sourceEpisodeIds),
            'content_requests' => $this->contentRequests->episodeIdsWithRequests($sourceEpisodeIds),
            'technical_issues' => $this->technicalIssues->episodeIdsWithIssues($sourceEpisodeIds),
            'release_calendar' => $this->releaseCalendar->episodeIdsWithEntries($sourceEpisodeIds),
            'user_data' => $this->userData->episodeIdsWithUserData($sourceEpisodeIds),
            'media_technical_issues' => $this->technicalIssues->mediaIdsWithIssues($sourceMediaIds),
            'media_release_calendar' => $this->releaseCalendar->mediaIdsWithEntries($sourceMediaIds),
        ];

        $movedEpisodes = $this->mergeEpisodes(
            $season,
            $targetSeason,
            $canonical,
            $mediaByEpisode,
            $mediaLookup,
            $dependencyPresence,
        );
        $this->moveMediaCollection(
            $mediaByEpisode->get(0, collect()),
            $canonical,
            $targetSeason,
            null,
            $mediaLookup,
            $dependencyPresence['media_technical_issues'],
            $dependencyPresence['media_release_calendar'],
        );
        $this->comments->moveSeason($season, $targetSeason);
        $this->contentRequests->moveSeason($season, $targetSeason);
        $this->technicalIssues->moveSeason($season->id, $targetSeason->id);
        $this->releaseCalendar->moveSeason($season, $targetSeason);
        $season->forceDelete();

        return $movedEpisodes;
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

    /**
     * @param  Collection<int|string, EloquentCollection<int, LicensedMedia>>  $mediaByEpisode
     * @param  array{source_key: array<string, LicensedMedia>, playback_url: array<string, LicensedMedia>}  $mediaLookup
     * @param  array{
     *     comments: array<int, bool>,
     *     content_requests: array<int, bool>,
     *     technical_issues: array<int, bool>,
     *     release_calendar: array<int, bool>,
     *     user_data: array<int, bool>,
     *     media_technical_issues: array<int, bool>,
     *     media_release_calendar: array<int, bool>
     * }  $dependencyPresence
     */
    private function mergeEpisodes(
        Season $fromSeason,
        Season $targetSeason,
        CatalogTitle $canonical,
        Collection $mediaByEpisode,
        array $mediaLookup,
        array $dependencyPresence,
    ): int {
        $moved = 0;
        $targetEpisodes = $targetSeason->episodes
            ->keyBy(fn (Episode $episode): string => $this->episodeIdentityKey($episode));

        foreach ($fromSeason->episodes as $episode) {
            $targetEpisode = $targetEpisodes->get($this->episodeIdentityKey($episode));

            if (! $targetEpisode instanceof Episode) {
                $episode->season_id = $targetSeason->id;
                $episode->save();
                $this->moveMediaCollection(
                    $mediaByEpisode->get($episode->id, collect()),
                    $canonical,
                    $targetSeason,
                    $episode,
                    $mediaLookup,
                    $dependencyPresence['media_technical_issues'],
                    $dependencyPresence['media_release_calendar'],
                );

                if (isset($dependencyPresence['user_data'][$episode->id])) {
                    $this->userData->moveEpisode($episode, $episode, $canonical);
                }

                $moved++;

                continue;
            }

            $this->moveMediaCollection(
                $mediaByEpisode->get($episode->id, collect()),
                $canonical,
                $targetSeason,
                $targetEpisode,
                $mediaLookup,
                $dependencyPresence['media_technical_issues'],
                $dependencyPresence['media_release_calendar'],
            );

            $targetEpisode->fill([
                'source_page_id' => $targetEpisode->source_page_id ?? $episode->source_page_id,
                'title' => $targetEpisode->title ?? $episode->title,
                'source_url' => $targetEpisode->source_url ?? $episode->source_url,
                'source_url_hash' => $targetEpisode->source_url_hash ?? $episode->source_url_hash,
                'released_at' => $targetEpisode->released_at ?? $episode->released_at,
                'summary' => $targetEpisode->summary ?? $episode->summary,
            ])->save();

            $targetEpisode->setRelation('season', $targetSeason);

            if (isset($dependencyPresence['comments'][$episode->id])) {
                $this->comments->moveEpisode($episode, $targetEpisode);
            }

            if (isset($dependencyPresence['content_requests'][$episode->id])) {
                $this->contentRequests->moveEpisode($episode, $targetEpisode);
            }

            if (isset($dependencyPresence['technical_issues'][$episode->id])) {
                $this->technicalIssues->moveEpisode($episode->id, $targetEpisode->id);
            }

            if (isset($dependencyPresence['release_calendar'][$episode->id])) {
                $this->releaseCalendar->moveEpisode($episode, $targetEpisode, $canonical, $targetSeason);
            }

            if (isset($dependencyPresence['user_data'][$episode->id])) {
                $this->userData->moveEpisode($episode, $targetEpisode, $canonical);
            }

            $episode->forceDelete();
            $moved++;
        }

        return $moved;
    }

    private function moveLooseMedia(CatalogTitle $duplicate, CatalogTitle $canonical): void
    {
        $media = LicensedMedia::query()
            ->where('catalog_title_id', $duplicate->id)
            ->whereNull('season_id')
            ->whereNull('episode_id')
            ->orderBy('id')
            ->get()
            ->values();
        $mediaIds = $media->modelKeys();

        $this->moveMediaCollection(
            $media,
            $canonical,
            null,
            null,
            $this->canonicalMediaLookup($media, $canonical),
            $this->technicalIssues->mediaIdsWithIssues($mediaIds),
            $this->releaseCalendar->mediaIdsWithEntries($mediaIds),
        );
    }

    /**
     * @param  Collection<int, LicensedMedia>  $mediaItems
     * @param  array{source_key: array<string, LicensedMedia>, playback_url: array<string, LicensedMedia>}  $mediaLookup
     * @param  array<int, bool>  $technicalIssueMediaIds
     * @param  array<int, bool>  $releaseCalendarMediaIds
     */
    private function moveMediaCollection(
        Collection $mediaItems,
        CatalogTitle $canonical,
        ?Season $season,
        ?Episode $episode,
        array $mediaLookup,
        array $technicalIssueMediaIds,
        array $releaseCalendarMediaIds,
    ): void {
        foreach ($mediaItems as $media) {
            $this->moveMedia(
                $media,
                $canonical,
                $season,
                $episode,
                $mediaLookup,
                isset($technicalIssueMediaIds[$media->id]),
                isset($releaseCalendarMediaIds[$media->id]),
            );
        }
    }

    /**
     * @param  array{source_key: array<string, LicensedMedia>, playback_url: array<string, LicensedMedia>}  $mediaLookup
     */
    private function moveMedia(
        LicensedMedia $media,
        CatalogTitle $canonical,
        ?Season $season,
        ?Episode $episode,
        array $mediaLookup,
        bool $hasTechnicalIssues,
        bool $hasReleaseCalendarEntries,
    ): LicensedMedia {
        $existing = $this->matchingCanonicalMedia($media, $mediaLookup);

        if ($existing !== null && $existing->isNot($media)) {
            if ($hasTechnicalIssues) {
                $this->technicalIssues->moveMedia($media->id, $existing->id);
            }

            $effectiveUrl = $media->playback_url ?: ($existing->playback_url ?: ($media->path ?: $existing->path));
            $effectiveUrlChanged = $existing->effectivePlaybackUrl() !== $effectiveUrl;

            $existing->fill([
                'season_id' => $season === null ? $existing->season_id : $season->id,
                'episode_id' => $episode === null ? $existing->episode_id : $episode->id,
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
            ]);

            if ($effectiveUrlChanged) {
                $existing->resetFileSizeInspection();
            } elseif ($media->hasKnownFileSize() && ! $existing->hasKnownFileSize()) {
                $existing->forceFill([
                    'file_size_bytes' => $media->file_size_bytes,
                    'file_size_checked_at' => $media->file_size_checked_at,
                    'file_size_check_status' => $media->file_size_check_status,
                    'file_size_source' => $media->file_size_source,
                    'file_size_http_status' => $media->file_size_http_status,
                    'file_size_check_error' => $media->file_size_check_error,
                ]);
            }

            $existing->save();

            if ($hasReleaseCalendarEntries) {
                $this->releaseCalendar->moveMedia($media, $existing);
            }

            $media->forceDelete();

            return $existing;
        }

        $media->fill([
            'catalog_title_id' => $canonical->id,
            'season_id' => $season === null ? $media->season_id : $season->id,
            'episode_id' => $episode === null ? $media->episode_id : $episode->id,
        ])->save();

        return $media;
    }

    /**
     * @param  Collection<int, LicensedMedia>  $sourceMedia
     * @return array{source_key: array<string, LicensedMedia>, playback_url: array<string, LicensedMedia>}
     */
    private function canonicalMediaLookup(Collection $sourceMedia, CatalogTitle $canonical): array
    {
        $sourceKeys = $sourceMedia->pluck('source_media_key')
            ->filter(static fn (mixed $key): bool => is_string($key) && $key !== '')
            ->unique()
            ->values();
        $playbackUrls = $sourceMedia->pluck('playback_url')
            ->filter(static fn (mixed $url): bool => is_string($url) && $url !== '')
            ->unique()
            ->values();

        if ($sourceKeys->isEmpty() && $playbackUrls->isEmpty()) {
            return ['source_key' => [], 'playback_url' => []];
        }

        $canonicalMedia = LicensedMedia::query()
            ->where('catalog_title_id', $canonical->id)
            ->where(function ($query) use ($sourceKeys, $playbackUrls): void {
                if ($sourceKeys->isNotEmpty()) {
                    $query->whereIn('source_media_key', $sourceKeys);
                }

                if ($playbackUrls->isNotEmpty()) {
                    $sourceKeys->isNotEmpty()
                        ? $query->orWhereIn('playback_url', $playbackUrls)
                        : $query->whereIn('playback_url', $playbackUrls);
                }
            })
            ->orderBy('id')
            ->get();
        $lookup = ['source_key' => [], 'playback_url' => []];

        foreach ($canonicalMedia as $media) {
            if (is_string($media->source_media_key) && $media->source_media_key !== '') {
                $lookup['source_key'][$media->source_media_key] ??= $media;
            }

            if (is_string($media->playback_url) && $media->playback_url !== '') {
                $lookup['playback_url'][$media->playback_url] ??= $media;
            }
        }

        return $lookup;
    }

    /**
     * @param  array{source_key: array<string, LicensedMedia>, playback_url: array<string, LicensedMedia>}  $mediaLookup
     */
    private function matchingCanonicalMedia(LicensedMedia $media, array $mediaLookup): ?LicensedMedia
    {
        if (is_string($media->source_media_key) && $media->source_media_key !== '') {
            $match = $mediaLookup['source_key'][$media->source_media_key] ?? null;

            if ($match instanceof LicensedMedia) {
                return $match;
            }
        }

        if (! is_string($media->playback_url) || $media->playback_url === '') {
            return null;
        }

        return $mediaLookup['playback_url'][$media->playback_url] ?? null;
    }

    private function episodeIdentityKey(Episode $episode): string
    {
        return $episode->kind->value.'|'.$episode->number;
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
