<?php

namespace App\Services\Media;

use App\Services\Seasonvar\SeasonvarRelationMetadataNormalizer;
use Illuminate\Support\Str;

class ExternalMediaMetadata
{
    private readonly SeasonvarRelationMetadataNormalizer $relationMetadata;

    public function __construct(?SeasonvarRelationMetadataNormalizer $relationMetadata = null)
    {
        $this->relationMetadata = $relationMetadata ?? new SeasonvarRelationMetadataNormalizer;
    }

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
            preg_match('/(?:^|[^\pL\pN])sd(?:$|[^\pL\pN]|\p{Cyrillic})/u', $value) === 1 => '480p',
            preg_match('/\b(?:sd|dvd|sat[\s.-]*rip|tv[\s.-]*rip|dvd[\s.-]*rip|dvdrip|vhs[\s.-]*rip|vhsrip)\b/u', $value) === 1 => '480p',
            default => null,
        };
    }

    public function format(string $url): string
    {
        $format = Str::lower(pathinfo((string) parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION));

        return $format !== '' ? $format : 'stream';
    }

    public function translationName(?string $title, ?string $sourceUrl = null): ?string
    {
        if ($title === null) {
            return $this->relationMetadata->translation($this->translationNameFromSourceUrl($sourceUrl));
        }

        if ($this->hasSubtitles($title, $sourceUrl)) {
            return null;
        }

        if (preg_match('/(?:озвучка|перевод)\s*[:\-]\s*(?<name>[^,;|]{2,80})/iu', $title, $matches) === 1) {
            return $this->relationMetadata->translation(Str::substr(Str::squish($matches['name']), 0, 120));
        }

        if (preg_match('/\[(?<name>[^\]]{2,80})\]/u', $title, $matches) === 1) {
            return $this->relationMetadata->translation(Str::substr(Str::squish($matches['name']), 0, 120));
        }

        return $this->relationMetadata->translation(
            $this->inferredTranslationName($title)
                ?? $this->translationNameFromSourceUrl($sourceUrl),
        );
    }

    public function hasSubtitles(?string $title, ?string $sourceUrl = null, ?string $url = null): bool
    {
        $value = $this->normalizedValue(implode(' ', array_filter([
            $title,
            $sourceUrl ? urldecode($sourceUrl) : null,
            $url ? urldecode($url) : null,
        ])));

        return str_contains($value, 'субтитр')
            || preg_match('/\b(?:subtitles?|subs?)\b/u', $value) === 1;
    }

    /**
     * @return array{variant_type: string, variant_name: string|null, variant_key: string, has_subtitles: bool}
     */
    public function playbackVariant(?string $title, ?string $sourceUrl, string $url): array
    {
        $hasSubtitles = $this->hasSubtitles($title, $sourceUrl, $url);
        $variantType = $this->playbackVariantType($title, $sourceUrl, $url, $hasSubtitles);
        $variantName = match ($variantType) {
            'subtitles' => 'Субтитры',
            'original' => 'Оригинал',
            'trailer' => 'Трейлер',
            default => $this->translationName($title, $sourceUrl),
        };

        return [
            'variant_type' => $variantType,
            'variant_name' => $variantName,
            'variant_key' => $this->playbackVariantKey($variantType, $variantName),
            'has_subtitles' => $hasSubtitles,
        ];
    }

    public function playbackVariantKey(string $variantType, ?string $variantName): string
    {
        $nameKey = Str::slug((string) ($variantName ?: 'default'));

        return $variantType.'-'.($nameKey !== '' ? $nameKey : 'default');
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

    private function playbackVariantType(?string $title, ?string $sourceUrl, string $url, bool $hasSubtitles): string
    {
        if ($this->isTrailer($title, $url)) {
            return 'trailer';
        }

        if ($hasSubtitles) {
            return 'subtitles';
        }

        if ($this->translationName($title, $sourceUrl) === 'Оригинал') {
            return 'original';
        }

        $value = $this->normalizedValue(implode(' ', array_filter([
            $title,
            $sourceUrl ? urldecode($sourceUrl) : null,
            urldecode($url),
        ])));

        if (preg_match('/\b(?:original|originals?|оригинал|без\s+перевода)\b/u', $value) === 1) {
            return 'original';
        }

        return 'voiceover';
    }

    private function translationNameFromSourceUrl(?string $sourceUrl): ?string
    {
        if ($sourceUrl === null) {
            return null;
        }

        $path = urldecode((string) parse_url($sourceUrl, PHP_URL_PATH));

        if (preg_match('~/trans(?<name>[^/]*)/~u', $path, $matches) !== 1) {
            return null;
        }

        $name = Str::squish($matches['name']);

        if ($name === '' || preg_match('/^\d+$/', $name) === 1 || $this->hasSubtitles($name)) {
            return null;
        }

        return Str::substr($name, 0, 120);
    }

    private function inferredTranslationName(string $title): ?string
    {
        if (preg_match('/\.(?:mp4|m4v|m3u8|webm|mov|mkv|avi)(?:$|[?#])/iu', $title) === 1) {
            return null;
        }

        if (preg_match('/(?:4320p|2160p|1440p|1080p|720p|576p|540p|480p|360p|240p|full\s*hd|fhd|hd|sd|dvd|\[|\]|озвучка|перевод)/iu', $title) !== 1) {
            return null;
        }

        $name = preg_replace('/^\s*\d{1,3}\s*(?:серия|серии|episode|ep)?\s*/iu', ' ', $title) ?? $title;
        $name = preg_replace('/\bs\d{1,2}\s*e\d{1,3}\b/iu', ' ', $name) ?? $name;
        $name = preg_replace('/(?:full\s*hd|fhd|hd|sd)(?=\p{Cyrillic})/iu', ' ', $name) ?? $name;
        $name = preg_replace('/\b(?:4320p|2160p|1440p|1080p|720p|576p|540p|480p|360p|240p|full\s*hd|fhd|hd|sd|dvd|mp4|m3u8|webm|mov)\b/iu', ' ', $name) ?? $name;
        $name = str_replace(['/', '|', '[', ']', '(', ')'], ' ', $name);
        $name = Str::squish($name);

        if ($name === ''
            || Str::length($name) < 2
            || Str::length($name) > 80
            || preg_match('/^(?:видео|video|серия|episode|season|сезон|файл|file)$/iu', $name) === 1
            || preg_match('/[\pL]/u', $name) !== 1
        ) {
            return null;
        }

        return Str::substr($name, 0, 120);
    }
}
