<?php

namespace App\Services\Media;

use Illuminate\Support\Str;

class ExternalMediaMetadata
{
    public function quality(?string $title, string $url): ?string
    {
        $value = $this->normalizedValue(($title ?? '').' '.urldecode($url));

        if (preg_match('/(?<quality>4320p|2160p|1440p|1080p|720p|576p|540p|480p|360p|240p)/iu', $value, $matches) === 1) {
            return Str::lower($matches['quality']);
        }

        if (preg_match('/(?<width>\d{3,5})\s*[xх]\s*(?<height>\d{3,5})/u', $value, $matches) === 1) {
            $height = (int) $matches['height'];

            if ($height >= 200 && $height <= 4320) {
                return $height.'p';
            }
        }

        return match (true) {
            preg_match('/\b(?:8k)\b/u', $value) === 1 => '4320p',
            preg_match('/\b(?:4k|uhd|ultra\s*hd)\b/u', $value) === 1 => '2160p',
            preg_match('/\b(?:2k|qhd)\b/u', $value) === 1 => '1440p',
            preg_match('/\b(?:full\s*hd|fhd)\b/u', $value) === 1 => '1080p',
            preg_match('/\b(?:hd|hdtv|hdrip|web[\s.-]*dl|web[\s.-]*rip|bdrip)\b/u', $value) === 1 => '720p',
            preg_match('/\b(?:sd|dvd|sat[\s.-]*rip|tv[\s.-]*rip|dvd[\s.-]*rip|dvdrip|vhs[\s.-]*rip|vhsrip)\b/u', $value) === 1 => '480p',
            default => null,
        };
    }

    public function format(string $url): string
    {
        $format = Str::lower(pathinfo((string) parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION));

        return $format !== '' ? $format : 'stream';
    }

    public function translationName(?string $title): ?string
    {
        if ($title === null) {
            return null;
        }

        if (preg_match('/(?:озвучка|перевод)\s*[:\-]\s*(?<name>[^,;|]{2,80})/iu', $title, $matches) === 1) {
            return Str::substr(Str::squish($matches['name']), 0, 120);
        }

        if (preg_match('/\[(?<name>[^\]]{2,80})\]/u', $title, $matches) === 1) {
            return Str::substr(Str::squish($matches['name']), 0, 120);
        }

        return null;
    }

    public function isTrailer(?string $title, string $url): bool
    {
        $value = $this->normalizedValue(($title ?? '').' '.urldecode($url));
        $path = Str::lower((string) parse_url($url, PHP_URL_PATH));

        return str_contains($path, '/trailers/')
            || preg_match('/\b(?:trailer|trailers|preview|teaser|promo|анонс|трейлер|промо)\b/u', $value) === 1;
    }

    public function sourceMediaKey(
        string $source,
        string|int|null $catalogIdentity,
        ?int $seasonNumber,
        ?int $episodeNumber,
        ?string $sourceUrl,
        string $playbackUrl,
        ?string $title,
        ?string $quality,
        string $format,
    ): string {
        $sourceIdentity = $sourceUrl ?: 'direct:'.hash('sha256', $playbackUrl);

        return hash('sha256', implode('|', [
            Str::lower($source),
            (string) ($catalogIdentity ?? ''),
            $seasonNumber ?? '',
            $episodeNumber ?? '',
            $this->isTrailer($title, $playbackUrl) ? 'trailer' : 'episode',
            $sourceIdentity,
            Str::slug((string) ($title ?? '')),
            $quality ?? '',
            $format,
        ]));
    }

    private function normalizedValue(string $value): string
    {
        $value = Str::lower($value);
        $value = str_replace(['_', '.', '-'], ' ', $value);

        return Str::squish($value);
    }
}
