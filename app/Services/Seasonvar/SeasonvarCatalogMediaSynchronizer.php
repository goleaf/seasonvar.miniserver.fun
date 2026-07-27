<?php

declare(strict_types=1);

namespace App\Services\Seasonvar;

use App\DTOs\MediaHealthCheckResultData;
use App\DTOs\Seasonvar\SeasonvarMediaSyncResult;
use App\Enums\MediaHealthErrorCategory;
use App\Enums\MediaHealthStatus;
use App\Enums\ReleaseKind;
use App\Models\CatalogTitle;
use App\Models\Episode;
use App\Models\LicensedMedia;
use App\Models\Season;
use App\Services\Media\ExternalMediaMetadata;
use App\Services\Media\ExternalPlaylistImporter;
use App\Services\Media\MediaSourceHealthManager;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Throwable;

final readonly class SeasonvarCatalogMediaSynchronizer
{
    private const IDENTITY_QUERY_CHUNK = 300;

    public function __construct(
        private ExternalPlaylistImporter $playlistImporter,
        private MediaSourceHealthManager $mediaHealth,
        private ExternalMediaMetadata $mediaMetadata,
        private SeasonvarImportErrorSanitizer $errors,
        private SeasonvarCatalogRelationSyncer $relationSyncer,
        private SeasonvarRelationMetadataNormalizer $relationMetadata,
    ) {}

    /**
     * @param  array<int, Season>  $seasons
     * @param  list<array{url: string, title: string|null, season_number: int|null, episode_number: int|null, source_url: string|null, kind: string, storage_disk?: string, availability?: array<string, mixed>}>  $mediaItems
     * @param  (callable(string, array<string, mixed>): void)|null  $progress
     */
    public function synchronize(
        CatalogTitle $catalogTitle,
        array $seasons,
        array $mediaItems,
        ?callable $progress = null,
    ): SeasonvarMediaSyncResult {
        $this->report($progress, 'seasonvar-media-sync-started', [
            'catalog_title_id' => $catalogTitle->id,
            'candidates' => count($mediaItems),
        ]);

        if ($mediaItems === [] || $seasons === []) {
            return $this->complete(
                $catalogTitle,
                new SeasonvarMediaSyncResult(skipped: count($mediaItems)),
                $progress,
            );
        }

        $episodes = Episode::query()
            ->whereIn('season_id', collect($seasons)->pluck('id'))
            ->where('kind', ReleaseKind::Regular->value)
            ->get()
            ->keyBy(fn (Episode $episode): string => $episode->season_id.'|'.$episode->number);
        [$candidates, $skipped] = $this->normalizeCandidates(
            $catalogTitle,
            $seasons,
            $episodes,
            $mediaItems,
            $progress,
        );
        $existingMedia = $this->existingMedia($catalogTitle, $candidates);
        $bySourceKey = [];
        $byPlaybackUrl = [];

        foreach ($existingMedia->sortBy('id') as $media) {
            if (is_string($media->source_media_key) && $media->source_media_key !== '') {
                $bySourceKey[$media->source_media_key] ??= $media;
            }

            if (is_string($media->playback_url) && $media->playback_url !== '') {
                $byPlaybackUrl[$media->playback_url] ??= $media;
            }
        }

        $attached = 0;
        $updated = 0;

        foreach ($candidates as $candidate) {
            $media = $bySourceKey[$candidate['source_media_key']]
                ?? $byPlaybackUrl[$candidate['playback_url']]
                ?? new LicensedMedia([
                    'catalog_title_id' => $catalogTitle->id,
                    'source_media_key' => $candidate['source_media_key'],
                ]);
            $wasExisting = $media->exists;
            $effectiveUrlChanged = ! $wasExisting
                || $media->effectivePlaybackUrl() !== $candidate['playback_url'];
            $updates = $candidate['updates'];
            $updates['published_at'] = $media->published_at ?? $updates['published_at'];

            if ($wasExisting
                && ! $this->mediaAttributesChanged($media, $updates)
                && ! $this->mediaAvailabilityCheckDue($media)
            ) {
                $skipped++;
                $this->reportSkipped($catalogTitle, $media, $candidate, 'медиа уже актуально', $progress);

                continue;
            }

            if ($effectiveUrlChanged) {
                $media->resetFileSizeInspection();
            }

            $media->fill([
                ...$updates,
                'status' => $media->status ?: 'published',
            ])->save();

            if ($media->health_status !== MediaHealthStatus::Disabled
                && $candidate['availability'] instanceof MediaHealthCheckResultData) {
                $media = $this->mediaHealth->record($media, $candidate['availability']);
            }

            $wasExisting ? $updated++ : $attached++;
            $bySourceKey[$candidate['source_media_key']] = $media;
            $byPlaybackUrl[$candidate['playback_url']] = $media;
            $this->report(
                $progress,
                $media->wasRecentlyCreated ? 'seasonvar-media-attached' : 'seasonvar-media-updated',
                [
                    'catalog_title_id' => $catalogTitle->id,
                    'licensed_media_id' => $media->id,
                    'season_number' => $candidate['season_number'],
                    'episode_number' => $candidate['episode_number'],
                    'playback_url' => '[redacted-url]',
                    'quality' => $media->quality,
                    'format' => $media->format,
                    'check_status' => $media->check_status,
                    'http_status' => $media->last_http_status,
                ],
            );
        }

        return $this->complete(
            $catalogTitle,
            new SeasonvarMediaSyncResult(
                attached: $attached,
                updated: $updated,
                skipped: $skipped,
            ),
            $progress,
        );
    }

    /**
     * @param  (callable(string, array<string, mixed>): void)|null  $progress
     */
    public function syncTranslationsForTitle(
        CatalogTitle $catalogTitle,
        ?callable $progress = null,
    ): void {
        $taxonomies = $catalogTitle->licensedMedia()
            ->whereIn('variant_type', ['voiceover', 'original'])
            ->get(['variant_name', 'translation_name'])
            ->flatMap(
                fn (LicensedMedia $media): array => [
                    $media->variant_name,
                    $media->translation_name,
                ],
            )
            ->map(
                fn (mixed $name): ?string => is_string($name)
                    ? $this->relationMetadata->translation($name)
                    : null,
            )
            ->filter()
            ->unique(fn (string $name): string => Str::lower($name))
            ->map(fn (string $name): array => [
                'type' => 'translation',
                'name' => $name,
                'source_url' => null,
            ])
            ->values()
            ->all();

        if ($taxonomies !== []) {
            $this->relationSyncer->sync($catalogTitle, $taxonomies, $progress);
        }
    }

    /**
     * @param  array<int, Season>  $seasons
     * @param  Collection<string, Episode>  $episodes
     * @param  list<array{url: string, title: string|null, season_number: int|null, episode_number: int|null, source_url: string|null, kind: string, storage_disk?: string, availability?: array<string, mixed>}>  $mediaItems
     * @param  (callable(string, array<string, mixed>): void)|null  $progress
     * @return array{list<array<string, mixed>>, int}
     */
    private function normalizeCandidates(
        CatalogTitle $catalogTitle,
        array $seasons,
        Collection $episodes,
        array $mediaItems,
        ?callable $progress,
    ): array {
        $candidates = [];
        $sourceKeys = [];
        $playbackUrls = [];
        $skipped = 0;

        foreach ($mediaItems as $item) {
            if (! $this->isDirectPlayerMediaUrl($item['url'])) {
                $skipped++;

                continue;
            }

            try {
                $playbackUrl = $this->playlistImporter->safeExternalUrl($item['url']);
            } catch (Throwable $exception) {
                $skipped++;
                $this->report($progress, 'seasonvar-media-skipped', [
                    'catalog_title_id' => $catalogTitle->id,
                    'url' => '[redacted-url]',
                    'reason' => $this->errors->fromException($exception),
                ]);

                continue;
            }

            $seasonNumber = $item['season_number'];
            $episodeNumber = $item['episode_number'];
            $season = $seasonNumber !== null ? ($seasons[$seasonNumber] ?? null) : null;
            $episode = $season !== null && $episodeNumber !== null
                ? $episodes->get($season->id.'|'.$episodeNumber)
                : null;
            $isTrailer = $this->mediaMetadata->isTrailer($item['title'], $playbackUrl);

            if (($season === null || ! $episode instanceof Episode) && ! $isTrailer) {
                $skipped++;
                $this->report($progress, 'seasonvar-media-skipped', [
                    'catalog_title_id' => $catalogTitle->id,
                    'url' => '[redacted-url]',
                    'season_number' => $seasonNumber,
                    'episode_number' => $episodeNumber,
                    'reason' => 'серия для медиа не найдена',
                ]);

                continue;
            }

            $quality = $this->mediaMetadata->quality($item['title'], $playbackUrl);
            $format = $this->mediaMetadata->format($playbackUrl);
            $variant = $this->mediaMetadata->playbackVariant($item['title'], $item['source_url'], $playbackUrl);
            $sourceMediaKey = $this->sourceMediaKey(
                $catalogTitle,
                $season,
                $episode,
                $item,
                $playbackUrl,
                $quality,
                $format,
            );

            if (isset($sourceKeys[$sourceMediaKey]) || isset($playbackUrls[$playbackUrl])) {
                $skipped++;

                continue;
            }

            $sourceKeys[$sourceMediaKey] = true;
            $playbackUrls[$playbackUrl] = true;
            $candidates[] = [
                'source_media_key' => $sourceMediaKey,
                'playback_url' => $playbackUrl,
                'season_number' => $season?->number,
                'episode_number' => $episode?->number,
                'availability' => $this->preparedMediaAvailability($item),
                'updates' => [
                    'catalog_title_id' => $catalogTitle->id,
                    'season_id' => $season?->id,
                    'episode_id' => $episode?->id,
                    'title' => $item['title'] ?: $this->mediaTitle($catalogTitle, $season, $episode, $isTrailer),
                    'storage_disk' => ($item['storage_disk'] ?? null) === 'external_playlist'
                        ? 'external_playlist'
                        : 'seasonvar_parsed',
                    'path' => $playbackUrl,
                    'playback_url' => $playbackUrl,
                    'source_media_key' => $sourceMediaKey,
                    'source_url' => $item['source_url'],
                    'quality' => $quality,
                    'translation_name' => $this->mediaMetadata->translationName($item['title'], $item['source_url']),
                    'variant_type' => $variant['variant_type'],
                    'variant_name' => $variant['variant_name'],
                    'variant_key' => $variant['variant_key'],
                    'has_subtitles' => $variant['has_subtitles'],
                    'subtitle_language' => $variant['subtitle_language'],
                    'format' => $format,
                    'published_at' => now(),
                ],
            ];
        }

        return [$candidates, $skipped];
    }

    /**
     * @param  list<array<string, mixed>>  $candidates
     * @return EloquentCollection<int, LicensedMedia>
     */
    private function existingMedia(CatalogTitle $catalogTitle, array $candidates): EloquentCollection
    {
        $found = new EloquentCollection;

        foreach (collect($candidates)->pluck('source_media_key')->unique()->chunk(self::IDENTITY_QUERY_CHUNK) as $keys) {
            $found = $found->merge(LicensedMedia::withTrashed()
                ->where('catalog_title_id', $catalogTitle->id)
                ->whereIn('source_media_key', $keys)
                ->get());
        }

        foreach (collect($candidates)->pluck('playback_url')->unique()->chunk(self::IDENTITY_QUERY_CHUNK) as $urls) {
            $found = $found->merge(LicensedMedia::withTrashed()
                ->where('catalog_title_id', $catalogTitle->id)
                ->whereIn('playback_url', $urls)
                ->get());
        }

        return $found->unique('id')->values();
    }

    /**
     * @param  array<string, mixed>  $candidate
     * @param  (callable(string, array<string, mixed>): void)|null  $progress
     */
    private function reportSkipped(
        CatalogTitle $catalogTitle,
        LicensedMedia $media,
        array $candidate,
        string $reason,
        ?callable $progress,
    ): void {
        $this->report($progress, 'seasonvar-media-skipped', [
            'catalog_title_id' => $catalogTitle->id,
            'licensed_media_id' => $media->id,
            'season_number' => $candidate['season_number'],
            'episode_number' => $candidate['episode_number'],
            'playback_url' => '[redacted-url]',
            'reason' => $reason,
        ]);
    }

    /**
     * @param  array<string, mixed>  $updates
     */
    private function mediaAttributesChanged(LicensedMedia $media, array $updates): bool
    {
        foreach ($updates as $field => $value) {
            if ($this->comparableMediaValue($field, $media->{$field}) !== $this->comparableMediaValue($field, $value)) {
                return true;
            }
        }

        return false;
    }

    private function comparableMediaValue(string $field, mixed $value): mixed
    {
        if ($value instanceof Carbon) {
            return $value->toDateTimeString();
        }

        if (in_array($field, ['catalog_title_id', 'season_id', 'episode_id', 'duration_seconds'], true)) {
            return $value === null ? null : (int) $value;
        }

        return $value;
    }

    private function mediaAvailabilityCheckDue(LicensedMedia $media): bool
    {
        if (! (bool) config('seasonvar.media_check.enabled', true)
            || $media->health_status === MediaHealthStatus::Disabled) {
            return false;
        }

        if (! $media->exists
            || $media->checked_at === null
            || $media->check_status === null
            || $media->next_check_at === null) {
            return true;
        }

        return $media->next_check_at->isPast();
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function preparedMediaAvailability(array $item): ?MediaHealthCheckResultData
    {
        $availability = $item['availability'] ?? null;

        if (! is_array($availability)
            || ! isset($availability['available'], $availability['check_status'], $availability['checked_at'])
            || ! is_bool($availability['available'])
            || ! is_string($availability['check_status'])
            || ! is_string($availability['checked_at'])) {
            return null;
        }

        try {
            $checkedAt = Carbon::parse($availability['checked_at']);
        } catch (Throwable) {
            return null;
        }

        $errorCategory = isset($availability['error_category']) && is_string($availability['error_category'])
            ? MediaHealthErrorCategory::tryFrom($availability['error_category'])
            : null;

        return new MediaHealthCheckResultData(
            available: $availability['available'],
            checkStatus: $availability['check_status'],
            httpStatus: isset($availability['http_status']) && is_numeric($availability['http_status'])
                ? (int) $availability['http_status']
                : null,
            checkedAt: $checkedAt,
            latencyMs: isset($availability['latency_ms']) && is_numeric($availability['latency_ms'])
                ? (int) $availability['latency_ms']
                : null,
            errorCategory: $errorCategory,
            permanentFailure: (bool) ($availability['permanent_failure'] ?? false),
        );
    }

    /**
     * @param  array{source_url: string|null, title: string|null}  $item
     */
    private function sourceMediaKey(
        CatalogTitle $catalogTitle,
        ?Season $season,
        ?Episode $episode,
        array $item,
        string $playbackUrl,
        ?string $quality,
        string $format,
    ): string {
        return $this->mediaMetadata->sourceMediaKey(
            'seasonvar',
            $catalogTitle->source_url_hash ?: $catalogTitle->id,
            $season?->number,
            $episode?->number,
            $item['source_url'],
            $playbackUrl,
            $item['title'],
            $quality,
            $format,
        );
    }

    private function mediaTitle(
        CatalogTitle $catalogTitle,
        ?Season $season,
        ?Episode $episode,
        bool $isTrailer,
    ): string {
        if ($isTrailer) {
            return collect([
                $catalogTitle->title,
                $season !== null ? $season->number.' сезон' : null,
                'трейлер',
            ])->filter()->implode(' - ');
        }

        if ($season === null || $episode === null) {
            return $catalogTitle->title.' - видео';
        }

        return sprintf('%s - %d сезон %d серия', $catalogTitle->title, $season->number, $episode->number);
    }

    private function isDirectPlayerMediaUrl(string $url): bool
    {
        return in_array($this->mediaMetadata->format($url), ['mp4', 'm4v', 'mov', 'webm', 'mkv', 'avi', 'm3u8'], true);
    }

    /**
     * @param  (callable(string, array<string, mixed>): void)|null  $progress
     */
    private function complete(
        CatalogTitle $catalogTitle,
        SeasonvarMediaSyncResult $result,
        ?callable $progress,
    ): SeasonvarMediaSyncResult {
        $this->report($progress, 'seasonvar-media-sync-complete', [
            'catalog_title_id' => $catalogTitle->id,
            ...$result->toArray(),
        ]);

        return $result;
    }

    /**
     * @param  (callable(string, array<string, mixed>): void)|null  $progress
     * @param  array<string, mixed>  $context
     */
    private function report(?callable $progress, string $event, array $context): void
    {
        if ($progress !== null) {
            $progress($event, $context);
        }
    }
}
