<?php

namespace App\Services\Media;

use App\Models\CatalogTitle;
use App\Models\Episode;
use App\Models\LicensedMedia;
use App\Models\Season;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

class LicensedMediaAutoAttacher
{
    /**
     * Create a new class instance.
     */
    public function __construct(private readonly ExternalPlaylistImporter $playlistImporter) {}

    /**
     * @param  (callable(string, array<string, mixed>): void)|null  $progress
     * @return array{attached: int, updated: int, skipped: int, failed: int}
     */
    public function attachForTitle(CatalogTitle $catalogTitle, int $limit = 500, ?callable $progress = null): array
    {
        $catalogTitle->loadMissing(['seasons.episodes', 'licensedMedia']);
        $result = $this->emptyResult();
        $result = $this->mergeResult($result, $this->attachConfiguredEpisodeUrls($catalogTitle, $limit, $progress));
        $result = $this->mergeResult($result, $this->importConfiguredPlaylists($catalogTitle, $limit, $progress));

        $this->report($progress, 'licensed-media-auto-attach-complete', [
            'catalog_title_id' => $catalogTitle->id,
            'slug' => $catalogTitle->slug,
            'attached' => $result['attached'],
            'updated' => $result['updated'],
            'skipped' => $result['skipped'],
            'failed' => $result['failed'],
        ]);

        return $result;
    }

    /**
     * @param  (callable(string, array<string, mixed>): void)|null  $progress
     * @return array{attached: int, updated: int, skipped: int, failed: int}
     */
    public function attachRecent(int $limit, ?callable $progress = null): array
    {
        $titles = CatalogTitle::query()
            ->with(['seasons.episodes', 'licensedMedia'])
            ->whereHas('seasons.episodes')
            ->latest('indexed_at')
            ->limit(max(1, $limit))
            ->get();
        $result = $this->emptyResult();

        foreach ($titles as $catalogTitle) {
            $result = $this->mergeResult($result, $this->attachForTitle($catalogTitle, $limit, $progress));
        }

        return $result;
    }

    /**
     * @param  (callable(string, array<string, mixed>): void)|null  $progress
     * @return array{attached: int, updated: int, skipped: int, failed: int}
     */
    private function attachConfiguredEpisodeUrls(CatalogTitle $catalogTitle, int $limit, ?callable $progress = null): array
    {
        $baseUrl = trim((string) config('licensed_media.remote_base_url'));
        $patterns = collect(config('licensed_media.episode_path_patterns', []))
            ->filter(fn (mixed $pattern): bool => is_string($pattern) && trim($pattern) !== '')
            ->values();

        if ($baseUrl === '' || $patterns->isEmpty()) {
            $this->report($progress, 'licensed-media-auto-attach-skipped', [
                'catalog_title_id' => $catalogTitle->id,
                'reason' => 'базовая ссылка медиа или шаблоны серий не настроены',
            ]);

            return $this->emptyResult();
        }

        $result = $this->emptyResult();
        $episodes = $this->episodesForTitle($catalogTitle)->take(max(1, $limit));

        foreach ($episodes as $episode) {
            if ($this->episodeHasMedia($catalogTitle, $episode)) {
                $result['skipped']++;

                continue;
            }

            $season = $episode->season;

            if ($season === null) {
                $result['skipped']++;

                continue;
            }

            $playbackUrl = $this->firstPlaybackUrl($baseUrl, $patterns, $catalogTitle, $season, $episode);

            if ($playbackUrl === null) {
                $result['failed']++;

                continue;
            }

            $wasExisting = LicensedMedia::query()
                ->where('catalog_title_id', $catalogTitle->id)
                ->where('playback_url', $playbackUrl)
                ->exists();

            LicensedMedia::query()->updateOrCreate(
                [
                    'catalog_title_id' => $catalogTitle->id,
                    'playback_url' => $playbackUrl,
                ],
                [
                    'season_id' => $season->id,
                    'episode_id' => $episode->id,
                    'title' => $this->mediaTitle($catalogTitle, $season, $episode),
                    'storage_disk' => 'remote_pattern',
                    'path' => $playbackUrl,
                    'status' => 'published',
                    'published_at' => now(),
                ],
            );

            $result[$wasExisting ? 'updated' : 'attached']++;
            $catalogTitle->licensedMedia->push(new LicensedMedia([
                'episode_id' => $episode->id,
                'playback_url' => $playbackUrl,
            ]));

            $this->report($progress, 'licensed-media-attached', [
                'catalog_title_id' => $catalogTitle->id,
                'season_number' => $season->number,
                'episode_number' => $episode->number,
                'playback_url' => $playbackUrl,
            ]);
        }

        return $result;
    }

    /**
     * @param  (callable(string, array<string, mixed>): void)|null  $progress
     * @return array{attached: int, updated: int, skipped: int, failed: int}
     */
    private function importConfiguredPlaylists(CatalogTitle $catalogTitle, int $limit, ?callable $progress = null): array
    {
        $playlistUrls = collect(config('licensed_media.playlist_urls', []))
            ->filter(fn (mixed $url): bool => is_string($url) && trim($url) !== '')
            ->unique()
            ->values();

        if ($playlistUrls->isEmpty()) {
            return $this->emptyResult();
        }

        $result = $this->emptyResult();

        foreach ($playlistUrls as $playlistUrl) {
            try {
                $playlistResult = $this->playlistImporter->importFromUrl((string) $playlistUrl, $catalogTitle, $limit);
                $result['attached'] += $playlistResult['imported'];
                $result['updated'] += $playlistResult['updated'];
                $result['skipped'] += $playlistResult['skipped'] + $playlistResult['unmatched'];

                $this->report($progress, 'licensed-media-playlist-import-complete', [
                    'catalog_title_id' => $catalogTitle->id,
                    'url' => $playlistUrl,
                    'imported' => $playlistResult['imported'],
                    'updated' => $playlistResult['updated'],
                    'skipped' => $playlistResult['skipped'],
                    'unmatched' => $playlistResult['unmatched'],
                ]);
            } catch (Throwable $exception) {
                $result['failed']++;
                $this->report($progress, 'licensed-media-auto-attach-failed', [
                    'catalog_title_id' => $catalogTitle->id,
                    'url' => $playlistUrl,
                    'exception' => $exception::class,
                    'message' => $exception->getMessage(),
                ]);
            }
        }

        return $result;
    }

    /**
     * @return Collection<int, Episode>
     */
    private function episodesForTitle(CatalogTitle $catalogTitle): Collection
    {
        return $catalogTitle->seasons
            ->sortBy('number')
            ->flatMap(fn (Season $season): Collection => $season->episodes
                ->sortBy('number')
                ->each(fn (Episode $episode): Episode => $episode->setRelation('season', $season))
                ->values())
            ->values();
    }

    private function episodeHasMedia(CatalogTitle $catalogTitle, Episode $episode): bool
    {
        return $catalogTitle->licensedMedia
            ->contains(fn (LicensedMedia $media): bool => (int) $media->episode_id === (int) $episode->id);
    }

    /**
     * @param  Collection<int, string>  $patterns
     */
    private function firstPlaybackUrl(string $baseUrl, Collection $patterns, CatalogTitle $catalogTitle, Season $season, Episode $episode): ?string
    {
        foreach ($patterns as $pattern) {
            $url = Str::finish($baseUrl, '/').ltrim($this->renderPattern($pattern, $catalogTitle, $season, $episode), '/');

            try {
                $safeUrl = $this->playlistImporter->safeExternalUrl($url);
            } catch (Throwable) {
                continue;
            }

            if (! (bool) config('licensed_media.verify_remote_files', false) || $this->remoteFileExists($safeUrl)) {
                return $safeUrl;
            }
        }

        return null;
    }

    private function renderPattern(string $pattern, CatalogTitle $catalogTitle, Season $season, Episode $episode): string
    {
        $extension = trim((string) config('licensed_media.default_extension', 'mp4'), '. ') ?: 'mp4';

        return strtr($pattern, [
            '{slug}' => $catalogTitle->slug,
            '{title_slug}' => Str::slug($catalogTitle->title),
            '{external_id}' => (string) ($catalogTitle->external_id ?: $catalogTitle->slug),
            '{season}' => (string) $season->number,
            '{season_pad2}' => str_pad((string) $season->number, 2, '0', STR_PAD_LEFT),
            '{episode}' => (string) $episode->number,
            '{episode_pad2}' => str_pad((string) $episode->number, 2, '0', STR_PAD_LEFT),
            '{extension}' => $extension,
        ]);
    }

    private function remoteFileExists(string $url): bool
    {
        try {
            return Http::timeout(5)
                ->connectTimeout(3)
                ->retry([100, 300])
                ->head($url)
                ->successful();
        } catch (Throwable) {
            return false;
        }
    }

    private function mediaTitle(CatalogTitle $catalogTitle, Season $season, Episode $episode): string
    {
        return sprintf('%s - %d сезон %d серия', $catalogTitle->title, $season->number, $episode->number);
    }

    /**
     * @return array{attached: int, updated: int, skipped: int, failed: int}
     */
    private function emptyResult(): array
    {
        return [
            'attached' => 0,
            'updated' => 0,
            'skipped' => 0,
            'failed' => 0,
        ];
    }

    /**
     * @param  array{attached: int, updated: int, skipped: int, failed: int}  $left
     * @param  array{attached: int, updated: int, skipped: int, failed: int}  $right
     * @return array{attached: int, updated: int, skipped: int, failed: int}
     */
    private function mergeResult(array $left, array $right): array
    {
        return [
            'attached' => $left['attached'] + $right['attached'],
            'updated' => $left['updated'] + $right['updated'],
            'skipped' => $left['skipped'] + $right['skipped'],
            'failed' => $left['failed'] + $right['failed'],
        ];
    }

    /**
     * @param  (callable(string, array<string, mixed>): void)|null  $progress
     * @param  array<string, mixed>  $context
     */
    private function report(?callable $progress, string $event, array $context = []): void
    {
        if ($progress !== null) {
            $progress($event, $context);
        }
    }
}
