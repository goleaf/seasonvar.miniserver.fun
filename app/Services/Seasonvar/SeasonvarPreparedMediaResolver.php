<?php

declare(strict_types=1);

namespace App\Services\Seasonvar;

use App\DTOs\MediaHealthCheckResultData;
use App\Services\Crawler\PoliteHttpClient;
use App\Services\Media\ExternalPlaylistImporter;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

final class SeasonvarPreparedMediaResolver
{
    public function __construct(
        private readonly ExternalPlaylistImporter $playlistImporter,
        private readonly PoliteHttpClient $httpClient,
        private readonly SeasonvarUrl $seasonvarUrl,
        private readonly SeasonvarMediaAvailabilityChecker $availability,
        private readonly SeasonvarImportErrorSanitizer $errors,
    ) {}

    /**
     * @param  list<array<string, mixed>>  $mediaItems
     * @param  (callable(string, array<string, mixed>): void)|null  $progress
     * @return array{media: list<array<string, mixed>>, warnings: list<array<string, mixed>>}
     */
    public function resolve(array $mediaItems, ?callable $progress = null): array
    {
        $resolved = [];
        $warnings = [];

        foreach ($mediaItems as $item) {
            try {
                $candidates = match ($item['kind'] ?? null) {
                    'seasonvar_playlist' => $this->parseSeasonvarPlaylistItem($item, $progress),
                    'playlist' => $this->parseExternalPlaylistItem($item, $progress),
                    default => [$item],
                };

                foreach ($candidates as $candidate) {
                    $prepared = $this->prepareDirectItem($candidate, $progress);

                    if ($prepared !== null) {
                        $resolved[] = $prepared;
                    }
                }
            } catch (Throwable $exception) {
                $warnings[] = [
                    'type' => 'media_resolution_failed',
                    'message' => $this->errors->fromException($exception),
                ];
            }
        }

        $resolved = collect($resolved)
            ->unique(fn (array $item): string => hash('sha256', Str::lower(implode('|', [
                $item['url'],
                $item['season_number'] ?? '',
                $item['episode_number'] ?? '',
                $item['title'] ?? '',
            ]))))
            ->values()
            ->all();

        return ['media' => $resolved, 'warnings' => $warnings];
    }

    /**
     * @param  array<string, mixed>  $item
     * @param  (callable(string, array<string, mixed>): void)|null  $progress
     * @return array<string, mixed>|null
     */
    private function prepareDirectItem(array $item, ?callable $progress): ?array
    {
        $url = isset($item['url']) && is_string($item['url'])
            ? $this->playlistImporter->safeExternalUrl($item['url'])
            : null;

        if ($url === null || ! $this->isDirectPlayerMediaUrl($url)) {
            return null;
        }

        $result = $this->availability->check($url, $progress);

        return [
            'url' => $url,
            'title' => isset($item['title']) && is_string($item['title']) ? $item['title'] : null,
            'season_number' => isset($item['season_number']) && is_numeric($item['season_number'])
                ? (int) $item['season_number']
                : null,
            'episode_number' => isset($item['episode_number']) && is_numeric($item['episode_number'])
                ? (int) $item['episode_number']
                : null,
            'source_url' => isset($item['source_url']) && is_string($item['source_url']) ? $item['source_url'] : null,
            'kind' => $this->parsedMediaExtension($url) === 'm3u8' ? 'playlist' : 'file',
            'storage_disk' => ($item['storage_disk'] ?? null) === 'external_playlist'
                ? 'external_playlist'
                : 'seasonvar_parsed',
            'availability' => $this->availabilityPayload($result),
        ];
    }

    /**
     * @param  array<string, mixed>  $playlistItem
     * @param  (callable(string, array<string, mixed>): void)|null  $progress
     * @return list<array<string, mixed>>
     */
    private function parseExternalPlaylistItem(array $playlistItem, ?callable $progress): array
    {
        $playlistUrl = $this->playlistImporter->safeExternalUrl((string) $playlistItem['url']);
        $response = $this->httpClient->get($playlistUrl, 0, $progress);

        if (! $response->successful()) {
            throw new RuntimeException('Плейлист вернул HTTP '.$response->status().'.');
        }

        $items = collect($this->playlistImporter->parse($response->body(), $playlistUrl))
            ->map(function (array $entry) use ($playlistItem, $playlistUrl): array {
                $isM3Playlist = $this->parsedMediaExtension($playlistUrl) === 'm3u';
                $title = $isM3Playlist
                    ? ($entry['title'] ?? null)
                    : collect([$playlistItem['title'] ?? null, $entry['title'] ?? null])
                        ->filter()
                        ->unique()
                        ->implode(' ');

                return [
                    'url' => $entry['url'],
                    'title' => $title !== '' ? $title : null,
                    'season_number' => $playlistItem['season_number'] ?? $entry['season_number'],
                    'episode_number' => $playlistItem['episode_number'] ?? $entry['episode_number'],
                    'source_url' => $playlistUrl,
                    'kind' => $this->parsedMediaExtension($entry['url']) === 'm3u8' ? 'playlist' : 'file',
                    'storage_disk' => $isM3Playlist ? 'external_playlist' : 'seasonvar_parsed',
                ];
            })
            ->values()
            ->all();

        return $items !== [] ? $items : [$playlistItem];
    }

    /**
     * @param  array<string, mixed>  $playlistItem
     * @param  (callable(string, array<string, mixed>): void)|null  $progress
     * @return list<array<string, mixed>>
     */
    private function parseSeasonvarPlaylistItem(array $playlistItem, ?callable $progress): array
    {
        $playlistUrl = $this->safeSeasonvarPlaylistUrl((string) $playlistItem['url']);
        $playlistSourceUrl = $this->stableSeasonvarPlaylistSourceUrl($playlistUrl);
        $response = $this->httpClient->get($playlistUrl, 0, $progress);

        if (! $response->successful()) {
            throw new RuntimeException('Плейлист Seasonvar вернул HTTP '.$response->status().'.');
        }

        $decoded = json_decode($response->body(), true);

        if (json_last_error() !== JSON_ERROR_NONE || ! is_array($decoded)) {
            throw new RuntimeException('Плейлист Seasonvar вернул некорректный JSON.');
        }

        $items = [];

        foreach ($this->flattenSeasonvarPlaylistEntries($decoded) as $entry) {
            $file = $entry['file'] ?? null;

            if (! is_string($file)) {
                continue;
            }

            $url = $this->decodeSeasonvarPlaylistFile($file);

            if ($url === null) {
                continue;
            }

            $title = $this->cleanSeasonvarPlaylistTitle($entry['title'] ?? null);
            $items[] = [
                'url' => $url,
                'title' => $title,
                'season_number' => isset($playlistItem['season_number']) ? (int) $playlistItem['season_number'] : null,
                'episode_number' => $this->seasonvarPlaylistEpisodeNumber($entry, $title),
                'source_url' => $playlistSourceUrl,
                'kind' => $this->parsedMediaExtension($url) === 'm3u8' ? 'playlist' : 'file',
            ];
        }

        return $items;
    }

    /** @return list<array<string, mixed>> */
    private function flattenSeasonvarPlaylistEntries(array $entries, int $depth = 0): array
    {
        if ($depth > 16) {
            return [];
        }

        $items = [];

        foreach ($entries as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            if (isset($entry['file']) && is_string($entry['file'])) {
                $items[] = $entry;
            }

            if (isset($entry['folder']) && is_array($entry['folder'])) {
                array_push($items, ...$this->flattenSeasonvarPlaylistEntries($entry['folder'], $depth + 1));
            }
        }

        return $items;
    }

    private function safeSeasonvarPlaylistUrl(string $url): string
    {
        $normalizedUrl = $this->seasonvarUrl->normalize($url, $this->seasonvarUrl->baseUrl());
        $host = Str::lower((string) parse_url($normalizedUrl, PHP_URL_HOST));
        $path = (string) parse_url($normalizedUrl, PHP_URL_PATH);

        if (! in_array($host, ['seasonvar.ru', 'www.seasonvar.ru'], true)
            || preg_match('~/playls2/.+?/plist\.txt$~iu', $path) !== 1) {
            throw new RuntimeException('Некорректная ссылка плейлиста Seasonvar.');
        }

        return $normalizedUrl;
    }

    private function stableSeasonvarPlaylistSourceUrl(string $url): string
    {
        $parts = parse_url($url);

        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])) {
            return $url;
        }

        parse_str((string) ($parts['query'] ?? ''), $query);
        unset($query['time']);
        ksort($query);
        $stableUrl = Str::lower((string) $parts['scheme']).'://'.Str::lower((string) $parts['host']);

        if (isset($parts['port'])) {
            $stableUrl .= ':'.$parts['port'];
        }

        $stableUrl .= $parts['path'] ?? '/';

        return $query === []
            ? $stableUrl
            : $stableUrl.'?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }

    private function decodeSeasonvarPlaylistFile(string $file): ?string
    {
        $value = html_entity_decode($file, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $value = str_replace(['\\/', '\\u002F', '\\x2F'], '/', $value);
        $value = trim($value, " \t\n\r\0\x0B\"'()[]{};,");

        if (Str::startsWith($value, ['http://', 'https://', '//'])) {
            return $this->normalizeDecodedSeasonvarMediaUrl($value);
        }

        $value = str_replace('//b2xvbG8=', '', ltrim($value, '#'));

        foreach (array_filter([$value, mb_substr($value, 1)]) as $candidate) {
            $decoded = base64_decode($candidate, true);

            if (is_string($decoded) && $decoded !== '') {
                $url = $this->normalizeDecodedSeasonvarMediaUrl($decoded);

                if ($url !== null) {
                    return $url;
                }
            }
        }

        return null;
    }

    private function normalizeDecodedSeasonvarMediaUrl(string $url): ?string
    {
        $url = trim($url);
        $url = Str::startsWith($url, '//') ? 'https:'.$url : $url;

        return filter_var($url, FILTER_VALIDATE_URL) !== false && $this->isDirectPlayerMediaUrl($url)
            ? $url
            : null;
    }

    private function cleanSeasonvarPlaylistTitle(mixed $value): ?string
    {
        if (! is_string($value) && ! is_numeric($value)) {
            return null;
        }

        $title = strip_tags(html_entity_decode((string) $value, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $title = (string) Str::of($title)->replace("\xc2\xa0", ' ')->squish();

        return $title !== '' ? $title : null;
    }

    /** @param array<string, mixed> $entry */
    private function seasonvarPlaylistEpisodeNumber(array $entry, ?string $title): ?int
    {
        if (isset($entry['id']) && is_numeric($entry['id']) && (int) $entry['id'] > 0) {
            return (int) $entry['id'];
        }

        if ($title !== null && preg_match('/(?<episode>\d{1,3})\s*(?:серия|seriya|episode|ep)\b/iu', $title, $matches) === 1) {
            return (int) $matches['episode'];
        }

        return null;
    }

    /** @return array<string, mixed> */
    private function availabilityPayload(MediaHealthCheckResultData $result): array
    {
        return [
            'available' => $result->available,
            'check_status' => $result->checkStatus,
            'http_status' => $result->httpStatus,
            'checked_at' => $result->checkedAt->toIso8601String(),
            'latency_ms' => $result->latencyMs,
            'error_category' => $result->errorCategory?->value,
            'permanent_failure' => $result->permanentFailure,
        ];
    }

    private function isDirectPlayerMediaUrl(string $url): bool
    {
        return in_array($this->parsedMediaExtension($url), ['mp4', 'm4v', 'mov', 'webm', 'mkv', 'avi', 'm3u8'], true);
    }

    private function parsedMediaExtension(string $url): string
    {
        return Str::lower(pathinfo((string) parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION));
    }
}
