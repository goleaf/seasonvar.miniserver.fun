<?php

declare(strict_types=1);

namespace App\Services\Seasonvar;

use DOMNode;
use DOMXPath;
use Illuminate\Support\Str;
use InvalidArgumentException;

final readonly class SeasonvarMediaCandidateParser
{
    private const MEDIA_EXTENSIONS = [
        'm3u8',
        'm3u',
        'mp4',
        'm4v',
        'mov',
        'webm',
        'mkv',
        'avi',
    ];

    public function __construct(private SeasonvarUrl $seasonvarUrl) {}

    /**
     * @return list<array{url: string, title: string|null, season_number: int|null, episode_number: int|null, source_url: string|null, kind: string}>
     */
    public function parse(
        string $html,
        DOMXPath $xpath,
        string $baseUrl,
        int $seasonNumber,
    ): array {
        $items = [];

        foreach (
            $xpath->query('//*[@src or @href or @data or @data-src or @data-file or @data-url]')
                ?: [] as $node
        ) {
            foreach (
                ['src', 'href', 'data', 'data-src', 'data-file', 'data-url'] as $attribute
            ) {
                $value = $node->attributes?->getNamedItem($attribute)?->nodeValue;

                if ($value !== null) {
                    $this->addCandidate(
                        $items,
                        $value,
                        $baseUrl,
                        $this->titleFromNode($node),
                        $seasonNumber,
                    );
                }
            }
        }

        foreach ($this->urlsFromText($html) as $url) {
            $this->addCandidate($items, $url, $baseUrl, null, $seasonNumber);
        }

        foreach ($this->playlistUrls($html) as $url) {
            $this->addPlaylistCandidate($items, $url, $baseUrl, $seasonNumber);
        }

        return array_values($items);
    }

    /**
     * @param  array<string, array{url: string, title: string|null, season_number: int|null, episode_number: int|null, source_url: string|null, kind: string}>  $items
     */
    private function addCandidate(
        array &$items,
        string $rawUrl,
        string $baseUrl,
        ?string $title,
        int $seasonNumber,
    ): void {
        if (count($items) >= $this->maxCollectionItems()) {
            return;
        }

        $url = $this->cleanUrl($rawUrl);

        if ($url === null || ! $this->looksLikeMediaUrl($url)) {
            return;
        }

        $normalized = $this->normalizeRelative($url, $baseUrl);

        if (! $this->looksLikeMediaUrl($normalized)) {
            return;
        }

        $numbers = $this->numbers($normalized.' '.$title, $seasonNumber);
        $key = Str::lower($normalized);
        $candidate = [
            'url' => $normalized,
            'title' => $this->firstNonEmpty([$title, $this->fileName($normalized)]),
            'season_number' => $numbers['season_number'],
            'episode_number' => $numbers['episode_number'],
            'source_url' => $baseUrl,
            'kind' => $this->kind($normalized),
        ];

        if (! isset($items[$key])
            || ($items[$key]['episode_number'] === null
                && $candidate['episode_number'] !== null)
        ) {
            $items[$key] = $candidate;
        }
    }

    /**
     * @param  array<string, array{url: string, title: string|null, season_number: int|null, episode_number: int|null, source_url: string|null, kind: string}>  $items
     */
    private function addPlaylistCandidate(
        array &$items,
        string $rawUrl,
        string $baseUrl,
        int $seasonNumber,
    ): void {
        if (count($items) >= $this->maxCollectionItems()) {
            return;
        }

        $url = $this->cleanUrl($rawUrl);

        if ($url === null || ! $this->looksLikePlaylistUrl($url)) {
            return;
        }

        $normalized = $this->normalizeRelative($url, $baseUrl);

        if (! $this->looksLikePlaylistUrl($normalized)) {
            return;
        }

        $items[Str::lower($normalized)] ??= [
            'url' => $normalized,
            'title' => 'Плейлист Seasonvar',
            'season_number' => $seasonNumber,
            'episode_number' => null,
            'source_url' => $baseUrl,
            'kind' => 'seasonvar_playlist',
        ];
    }

    private function titleFromNode(DOMNode $node): ?string
    {
        foreach (['title', 'alt', 'data-title', 'aria-label'] as $attribute) {
            $title = $this->stringValue(
                $node->attributes?->getNamedItem($attribute)?->nodeValue,
            );

            if ($title !== null) {
                return $title;
            }
        }

        $text = $this->stringValue($node->textContent);

        return $text !== null && Str::length($text) <= 160 ? $text : null;
    }

    /** @return list<string> */
    private function urlsFromText(string $html): array
    {
        $text = html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = str_replace(['\/', '\u002F', '\x2F'], '/', $text);
        $extensions = implode(
            '|',
            array_map(
                fn (string $extension): string => preg_quote($extension, '~'),
                self::MEDIA_EXTENSIONS,
            ),
        );
        $pattern = '~(?:(?:https?:)?//|/|[A-Za-z0-9._-]+/)?[A-Za-z0-9._\~:/?#\[\]@!$&()*+,;=%-]+\.(?:'
            .$extensions
            .')(?:\?[A-Za-z0-9._\~:/?#\[\]@!$&()*+,;=%-]*)?~iu';
        $count = preg_match_all($pattern, $text, $matches);

        if ($count === false || $count === 0) {
            return [];
        }

        return collect($matches[0])
            ->map(fn (string $url): string => trim($url, "\"'()[]{};,"))
            ->filter(fn (string $url): bool => $url !== '')
            ->unique(fn (string $url): string => Str::lower($url))
            ->values()
            ->all();
    }

    /** @return list<string> */
    private function playlistUrls(string $html): array
    {
        $text = html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = str_replace(['\/', '\u002F', '\x2F'], '/', $text);
        $count = preg_match_all(
            '~[\'"](?<url>(?:(?:https?:)?//[^\'"]+)?/playls2/[^\'"]*?/plist\.txt(?:\?[^\'"]*)?)[\'"]~iu',
            $text,
            $matches,
        );

        if ($count === false || $count === 0) {
            return [];
        }

        return collect($matches['url'])
            ->map(fn (string $url): string => trim($url))
            ->filter(fn (string $url): bool => $url !== '')
            ->unique(fn (string $url): string => Str::lower($url))
            ->values()
            ->all();
    }

    private function cleanUrl(string $url): ?string
    {
        $url = html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $url = str_replace(['\/', '\u002F', '\x2F'], '/', $url);
        $url = trim($url, " \t\n\r\0\x0B\"'()[]{};,");

        return $url !== '' ? $url : null;
    }

    private function looksLikeMediaUrl(string $url): bool
    {
        $extension = Str::lower(
            pathinfo((string) parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION),
        );

        return in_array($extension, self::MEDIA_EXTENSIONS, true);
    }

    private function looksLikePlaylistUrl(string $url): bool
    {
        return preg_match(
            '~/playls2/.+?/plist\.txt$~iu',
            (string) parse_url($url, PHP_URL_PATH),
        ) === 1;
    }

    /** @return array{season_number: int|null, episode_number: int|null} */
    private function numbers(string $value, int $fallbackSeasonNumber): array
    {
        $value = $this->normalizeNumberText($value);
        $seasonNumber = null;
        $episodeNumber = null;

        foreach ([
            '/\bs(?<season>\d{1,2})\s*e(?<episode>\d{1,3})\b/iu',
            '/\b(?<season>\d{1,2})x(?<episode>\d{1,3})\b/iu',
            '/(?<season>\d{1,2})\s*(?:сезон|sezon|season)\D{0,30}(?<episode>\d{1,3})\s*(?:серия|seriya|episode|ep)?/iu',
            '/(?<episode>\d{1,3})\s*(?:серия|seriya|episode|ep)\D{0,30}(?<season>\d{1,2})\s*(?:сезон|sezon|season)/iu',
        ] as $pattern) {
            if (preg_match($pattern, $value, $matches) === 1) {
                $seasonNumber = (int) $matches['season'];
                $episodeNumber = (int) $matches['episode'];

                break;
            }
        }

        if ($seasonNumber === null
            && preg_match(
                '/(?<season>\d{1,2})\s*(?:сезон|sezon|season)\b/iu',
                $value,
                $matches,
            ) === 1
        ) {
            $seasonNumber = (int) $matches['season'];
        }

        if ($episodeNumber === null) {
            foreach ([
                '/(?:^|[^\d])(?<episode>\d{1,3})[_\-\s]*(?:серия|seriya|episode|ep)(?:[^\d]|$)/iu',
                '/(?:серия|seriya|episode|ep)[_\-\s]*(?<episode>\d{1,3})(?:[^\d]|$)/iu',
                '/(?:^|[^\d])e(?<episode>\d{1,3})(?:[^\d]|$)/iu',
                '/[#?&](?:episode|seriya|e)=(?<episode>\d{1,3})(?:[^\d]|$)/iu',
            ] as $pattern) {
                if (preg_match($pattern, $value, $matches) === 1) {
                    $episodeNumber = (int) $matches['episode'];

                    break;
                }
            }
        }

        return [
            'season_number' => $seasonNumber ?: $fallbackSeasonNumber,
            'episode_number' => $episodeNumber ?: null,
        ];
    }

    private function normalizeNumberText(string $value): string
    {
        return trim(
            preg_replace(
                '/\s+/u',
                ' ',
                str_replace(['_', '.', '-'], ' ', urldecode($value)),
            ) ?: '',
        );
    }

    private function kind(string $url): string
    {
        $extension = Str::lower(
            pathinfo((string) parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION),
        );

        return in_array($extension, ['m3u', 'm3u8'], true) ? 'playlist' : 'file';
    }

    private function fileName(string $url): string
    {
        $name = basename((string) parse_url($url, PHP_URL_PATH));

        return urldecode($name !== '' ? $name : 'видео');
    }

    /** @param list<mixed> $values */
    private function firstNonEmpty(array $values): ?string
    {
        foreach ($values as $value) {
            $string = $this->stringValue($value);

            if ($string !== null) {
                return $string;
            }
        }

        return null;
    }

    private function stringValue(mixed $value): ?string
    {
        if (! is_string($value) && ! is_numeric($value)) {
            return null;
        }

        $value = html_entity_decode((string) $value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $value = (string) Str::of($value)->replace("\xc2\xa0", ' ')->squish();

        return $value !== '' ? $value : null;
    }

    private function normalizeRelative(string $url, string $baseUrl): string
    {
        try {
            return $this->seasonvarUrl->normalize($url, $baseUrl);
        } catch (InvalidArgumentException) {
            return $url;
        }
    }

    private function maxCollectionItems(): int
    {
        return max(
            100,
            min(10_000, (int) config('seasonvar.import.parser_max_collection_items', 5000)),
        );
    }
}
