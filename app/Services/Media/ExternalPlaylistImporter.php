<?php

namespace App\Services\Media;

use App\Models\CatalogTitle;
use App\Models\Episode;
use App\Models\LicensedMedia;
use App\Models\Season;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

class ExternalPlaylistImporter
{
    /**
     * @var list<string>
     */
    private array $mediaExtensions = ['mp4', 'm4v', 'mov', 'webm', 'mkv', 'avi', 'm3u', 'm3u8'];

    /**
     * @return array{
     *     total: int,
     *     imported: int,
     *     updated: int,
     *     skipped: int,
     *     unmatched: int,
     *     items: list<array<string, mixed>>
     * }
     */
    public function importFromUrl(string $playlistUrl, ?CatalogTitle $forcedTitle = null, int $limit = 500, bool $dryRun = false): array
    {
        $safePlaylistUrl = $this->safeExternalUrl($playlistUrl);
        $response = Http::timeout(10)
            ->connectTimeout(5)
            ->retry([100, 300, 700])
            ->get($safePlaylistUrl);

        if (! $response->successful()) {
            throw new RuntimeException('Плейлист вернул HTTP '.$response->status().'.');
        }

        return $this->importFromContent($response->body(), $safePlaylistUrl, $forcedTitle, $limit, $dryRun);
    }

    /**
     * @return array{
     *     total: int,
     *     imported: int,
     *     updated: int,
     *     skipped: int,
     *     unmatched: int,
     *     items: list<array<string, mixed>>
     * }
     */
    public function importFromContent(string $content, string $baseUrl, ?CatalogTitle $forcedTitle = null, int $limit = 500, bool $dryRun = false): array
    {
        $entries = array_slice($this->parse($content, $baseUrl), 0, max(1, $limit));
        $titles = $forcedTitle === null
            ? CatalogTitle::query()->with(['seasons.episodes'])->get()
            : new EloquentCollection([$forcedTitle->loadMissing(['seasons.episodes'])]);
        $result = [
            'total' => count($entries),
            'imported' => 0,
            'updated' => 0,
            'skipped' => 0,
            'unmatched' => 0,
            'items' => [],
        ];

        foreach ($entries as $entry) {
            if (! $this->isMediaFileUrl($entry['url'])) {
                $result['skipped']++;
                $result['items'][] = $entry + [
                    'status' => 'skipped',
                    'reason' => 'это не видеофайл',
                ];

                continue;
            }

            $match = $this->matchEntry($entry, $titles, $forcedTitle !== null);

            if ($match === null) {
                $result['unmatched']++;
                $result['items'][] = $entry + [
                    'status' => 'unmatched',
                    'reason' => 'сериал не найден по имени файла',
                ];

                continue;
            }

            if ($dryRun) {
                $result['items'][] = $entry + [
                    'status' => 'dry-run',
                    'catalog_title' => $match['catalogTitle']->title,
                    'season' => $match['season']?->number,
                    'episode' => $match['episode']?->number,
                ];

                continue;
            }

            $sourceMediaKey = $this->sourceMediaKey($match['catalogTitle'], $entry, $baseUrl);
            $media = LicensedMedia::query()
                ->where('catalog_title_id', $match['catalogTitle']->id)
                ->where('source_media_key', $sourceMediaKey)
                ->first()
                ?? LicensedMedia::query()
                    ->where('catalog_title_id', $match['catalogTitle']->id)
                    ->where('playback_url', $entry['url'])
                    ->first()
                ?? new LicensedMedia([
                    'catalog_title_id' => $match['catalogTitle']->id,
                    'source_media_key' => $sourceMediaKey,
                ]);
            $wasRecentlyCreated = ! $media->exists;

            $media->fill([
                'catalog_title_id' => $match['catalogTitle']->id,
                'season_id' => $match['season']?->id,
                'episode_id' => $match['episode']?->id,
                'title' => $entry['title'],
                'storage_disk' => 'external_playlist',
                'path' => $entry['url'],
                'playback_url' => $entry['url'],
                'source_media_key' => $sourceMediaKey,
                'source_url' => $baseUrl,
                'quality' => $this->mediaQuality($entry['title'], $entry['url']),
                'format' => $this->mediaFormat($entry['url']),
                'check_status' => 'not_checked',
                'status' => 'published',
                'published_at' => $media->published_at ?? now(),
            ])->save();

            $result[$wasRecentlyCreated ? 'imported' : 'updated']++;
            $result['items'][] = $entry + [
                'status' => $wasRecentlyCreated ? 'imported' : 'updated',
                'licensed_media_id' => $media->id,
                'catalog_title' => $match['catalogTitle']->title,
                'season' => $match['season']?->number,
                'episode' => $match['episode']?->number,
            ];
        }

        return $result;
    }

    /**
     * @return list<array{
     *     title: string,
     *     url: string,
     *     file_name: string,
     *     title_key: string,
     *     season_number: int|null,
     *     episode_number: int|null
     * }>
     */
    public function parse(string $content, string $baseUrl): array
    {
        $entries = [];
        $pendingTitle = null;

        foreach (preg_split('/\R/u', $content) ?: [] as $line) {
            $line = trim($line);

            if ($line === '') {
                continue;
            }

            if (Str::startsWith($line, '#EXTINF')) {
                $pendingTitle = $this->titleFromExtinf($line);

                continue;
            }

            if (Str::startsWith($line, '#')) {
                continue;
            }

            try {
                $url = $this->safeExternalUrl($this->absoluteUrl($line, $baseUrl));
            } catch (InvalidArgumentException) {
                $pendingTitle = null;

                continue;
            }

            $fileName = $this->fileNameFromUrl($url);
            $rawTitle = $pendingTitle ?: $fileName;
            $parts = $this->titleParts($rawTitle, $fileName);

            $entries[] = [
                'title' => $this->cleanDisplayTitle($rawTitle, $fileName),
                'url' => $url,
                'file_name' => $fileName,
                'title_key' => $parts['title_key'],
                'season_number' => $parts['season_number'],
                'episode_number' => $parts['episode_number'],
            ];
            $pendingTitle = null;
        }

        return $entries;
    }

    /**
     * @param  EloquentCollection<int, CatalogTitle>  $titles
     * @return array{catalogTitle: CatalogTitle, season: Season|null, episode: Episode|null}|null
     */
    private function matchEntry(array $entry, EloquentCollection $titles, bool $forceFirstTitle = false): ?array
    {
        $catalogTitle = $forceFirstTitle
            ? $titles->first()
            : $this->matchCatalogTitle($entry, $titles);

        if ($catalogTitle === null) {
            return null;
        }

        $season = $this->matchSeason($catalogTitle, $entry);
        $episode = $season === null || $entry['episode_number'] === null
            ? null
            : $season->episodes->firstWhere('number', $entry['episode_number']);

        return [
            'catalogTitle' => $catalogTitle,
            'season' => $season,
            'episode' => $episode,
        ];
    }

    /**
     * @param  EloquentCollection<int, CatalogTitle>  $titles
     */
    private function matchCatalogTitle(array $entry, EloquentCollection $titles): ?CatalogTitle
    {
        $entryKey = $entry['title_key'];
        $fileKey = Str::slug($this->normalizeTitleText($entry['file_name']));
        $bestTitle = null;
        $bestScore = 0;

        foreach ($titles as $title) {
            $titleKeys = array_filter([
                $title->slug,
                Str::slug($title->title),
                $title->original_title ? Str::slug($title->original_title) : null,
            ]);

            foreach ($titleKeys as $titleKey) {
                $score = match (true) {
                    $entryKey === $titleKey => 100,
                    $fileKey === $titleKey => 95,
                    Str::startsWith($entryKey, $titleKey.'-') => 85,
                    Str::startsWith($fileKey, $titleKey.'-') => 80,
                    default => 0,
                };

                if ($score > $bestScore) {
                    $bestScore = $score;
                    $bestTitle = $title;
                }
            }
        }

        return $bestScore >= 80 ? $bestTitle : null;
    }

    private function matchSeason(CatalogTitle $catalogTitle, array $entry): ?Season
    {
        if ($entry['season_number'] !== null) {
            return $catalogTitle->seasons->firstWhere('number', $entry['season_number']);
        }

        if ($entry['episode_number'] === null) {
            return null;
        }

        $matchingSeasons = $catalogTitle->seasons
            ->filter(fn (Season $season): bool => $season->episodes->contains('number', $entry['episode_number']))
            ->values();

        return $matchingSeasons->count() === 1
            ? $matchingSeasons->first()
            : null;
    }

    /**
     * @return array{title_key: string, season_number: int|null, episode_number: int|null}
     */
    private function titleParts(string $rawTitle, string $fileName): array
    {
        $value = $this->normalizeTitleText($rawTitle.' '.$this->stripExtension($fileName));
        $seasonNumber = null;
        $episodeNumber = null;
        $patterns = [
            '/\bs(?<season>\d{1,2})\s*e(?<episode>\d{1,3})\b/iu',
            '/\b(?<season>\d{1,2})x(?<episode>\d{1,3})\b/iu',
            '/(?<season>\d{1,2})\s*(?:сезон|sezon|season)\D{0,20}(?<episode>\d{1,3})\s*(?:серия|seriya|episode|ep)?/iu',
            '/(?<episode>\d{1,3})\s*(?:серия|seriya|episode|ep)\D{0,20}(?<season>\d{1,2})\s*(?:сезон|sezon|season)/iu',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $value, $matches) === 1) {
                $seasonNumber = (int) $matches['season'];
                $episodeNumber = (int) $matches['episode'];
                $value = preg_replace($pattern, ' ', $value) ?: $value;

                break;
            }
        }

        if ($seasonNumber === null && preg_match('/(?<season>\d{1,2})\s*(?:сезон|sezon|season)\b/iu', $value, $matches) === 1) {
            $seasonNumber = (int) $matches['season'];
            $value = preg_replace('/(?<season>\d{1,2})\s*(?:сезон|sezon|season)\b/iu', ' ', $value) ?: $value;
        }

        if ($episodeNumber === null && preg_match('/(?<episode>\d{1,3})\s*(?:серия|seriya|episode|ep)\b/iu', $value, $matches) === 1) {
            $episodeNumber = (int) $matches['episode'];
            $value = preg_replace('/(?<episode>\d{1,3})\s*(?:серия|seriya|episode|ep)\b/iu', ' ', $value) ?: $value;
        }

        $value = preg_replace('/\b(?:1080p|720p|480p|2160p|web-dl|hdtv|x264|x265|hevc|aac|rus|ru|eng)\b/iu', ' ', $value) ?: $value;

        return [
            'title_key' => Str::slug($this->normalizeTitleText($value)),
            'season_number' => $seasonNumber,
            'episode_number' => $episodeNumber,
        ];
    }

    private function titleFromExtinf(string $line): ?string
    {
        if (preg_match('/,(?<title>.+)$/u', $line, $matches) !== 1) {
            return null;
        }

        return trim($matches['title']);
    }

    private function cleanDisplayTitle(string $rawTitle, string $fileName): string
    {
        $title = $this->normalizeTitleText($rawTitle);

        if ($title === '') {
            $title = $this->normalizeTitleText($this->stripExtension($fileName));
        }

        return $title !== '' ? $title : 'Файл плейлиста';
    }

    private function normalizeTitleText(string $value): string
    {
        $value = urldecode($value);
        $value = $this->stripExtension($value);
        $value = str_replace(['_', '.', '-'], ' ', $value);

        return trim(preg_replace('/\s+/u', ' ', $value) ?: '');
    }

    private function fileNameFromUrl(string $url): string
    {
        $path = (string) parse_url($url, PHP_URL_PATH);
        $fileName = basename($path);

        return urldecode($fileName !== '' ? $fileName : 'playlist-file');
    }

    private function stripExtension(string $value): string
    {
        return preg_replace('/\.(?:'.implode('|', $this->mediaExtensions).')$/iu', '', $value) ?: $value;
    }

    private function sourceMediaKey(CatalogTitle $catalogTitle, array $entry, string $baseUrl): string
    {
        return hash('sha256', implode('|', [
            'external_playlist',
            $catalogTitle->source_url_hash ?: $catalogTitle->id,
            $baseUrl,
            $entry['season_number'] ?? '',
            $entry['episode_number'] ?? '',
            Str::slug($entry['title']),
            $this->mediaQuality($entry['title'], $entry['url']) ?? '',
            $this->mediaFormat($entry['url']),
        ]));
    }

    private function mediaQuality(string $title, string $url): ?string
    {
        $value = Str::lower($title.' '.$url);

        if (preg_match('/(?<quality>2160p|1440p|1080p|720p|480p|360p|240p)/iu', $value, $matches) === 1) {
            return Str::lower($matches['quality']);
        }

        return null;
    }

    private function mediaFormat(string $url): string
    {
        return Str::lower(pathinfo((string) parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION));
    }

    private function isMediaFileUrl(string $url): bool
    {
        $path = (string) parse_url($url, PHP_URL_PATH);
        $extension = Str::lower(pathinfo($path, PATHINFO_EXTENSION));

        return in_array($extension, $this->mediaExtensions, true);
    }

    private function absoluteUrl(string $url, string $baseUrl): string
    {
        if (parse_url($url, PHP_URL_SCHEME) !== null) {
            return $url;
        }

        $baseScheme = (string) parse_url($baseUrl, PHP_URL_SCHEME);
        $baseHost = (string) parse_url($baseUrl, PHP_URL_HOST);
        $basePort = parse_url($baseUrl, PHP_URL_PORT);
        $origin = $baseScheme.'://'.$baseHost.($basePort ? ':'.$basePort : '');

        if (Str::startsWith($url, '//')) {
            return $baseScheme.':'.$url;
        }

        if (Str::startsWith($url, '/')) {
            return $origin.$url;
        }

        $basePath = (string) parse_url($baseUrl, PHP_URL_PATH);
        $directory = Str::beforeLast($basePath, '/');

        return $origin.$directory.'/'.$url;
    }

    public function safeExternalUrl(string $url): string
    {
        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            throw new InvalidArgumentException('Неверная ссылка.');
        }

        $scheme = Str::lower((string) parse_url($url, PHP_URL_SCHEME));
        $host = Str::lower((string) parse_url($url, PHP_URL_HOST));

        if (! in_array($scheme, ['http', 'https'], true) || $host === '') {
            throw new InvalidArgumentException('Разрешены только http/https ссылки.');
        }

        if ($this->isBlockedHost($host)) {
            throw new InvalidArgumentException('Этот хост заблокирован.');
        }

        return $url;
    }

    private function isBlockedHost(string $host): bool
    {
        if ($host === 'localhost' || Str::endsWith($host, '.local')) {
            return true;
        }

        $addresses = filter_var($host, FILTER_VALIDATE_IP) !== false
            ? [$host]
            : (gethostbynamel($host) ?: []);

        foreach ($addresses as $address) {
            if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                return true;
            }
        }

        return false;
    }
}
