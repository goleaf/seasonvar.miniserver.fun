<?php

declare(strict_types=1);

namespace App\Services\Media;

use App\Models\LicensedMedia;
use Illuminate\Support\Str;

final class ExternalMediaFileType
{
    /** @var array<string, string> */
    private const CONTENT_TYPE_EXTENSIONS = [
        'video/mp4' => 'mp4',
        'video/x-m4v' => 'm4v',
        'video/quicktime' => 'mov',
        'video/webm' => 'webm',
        'video/x-matroska' => 'mkv',
        'video/x-msvideo' => 'avi',
    ];

    /** @var list<string> */
    private const PLAYLIST_FORMATS = ['m3u', 'm3u8'];

    /** @var list<string> */
    private const PLAYLIST_CONTENT_TYPES = [
        'application/mpegurl',
        'application/vnd.apple.mpegurl',
        'application/x-mpegurl',
        'audio/mpegurl',
        'audio/x-mpegurl',
    ];

    public function effectiveUrl(LicensedMedia $media): ?string
    {
        $url = trim((string) ($media->playback_url ?: $media->path));

        return $url !== '' && parse_url($url, PHP_URL_SCHEME) !== null ? $url : null;
    }

    public function format(LicensedMedia $media, ?string $contentType = null): ?string
    {
        $stored = $this->normalizeFormat($media->format);

        if ($stored !== null && ($this->isDirectFormat($stored) || $this->isPlaylistFormat($stored))) {
            return $stored;
        }

        $extension = $this->urlFormat($media);

        if ($extension !== null && ($this->isDirectFormat($extension) || $this->isPlaylistFormat($extension))) {
            return $extension;
        }

        return self::CONTENT_TYPE_EXTENSIONS[$this->normalizedContentType($contentType)] ?? null;
    }

    public function trustedExtension(LicensedMedia $media, ?string $contentType = null): ?string
    {
        if ($this->isPlaylist($media, $contentType)) {
            return null;
        }

        $format = $this->format($media, $contentType);

        return $format !== null && $this->isDirectFormat($format) ? $format : null;
    }

    public function isDirect(LicensedMedia $media, ?string $contentType = null): bool
    {
        $format = $this->format($media, $contentType);

        return $format !== null && $this->isDirectFormat($format) && ! $this->isPlaylist($media, $contentType);
    }

    public function isPlaylist(LicensedMedia $media, ?string $contentType = null): bool
    {
        $stored = $this->normalizeFormat($media->format);
        $urlFormat = $this->urlFormat($media);

        return ($stored !== null && $this->isPlaylistFormat($stored))
            || ($urlFormat !== null && $this->isPlaylistFormat($urlFormat))
            || $this->isPlaylistContentType($contentType);
    }

    public function isHtmlContentType(?string $contentType): bool
    {
        return in_array($this->normalizedContentType($contentType), ['text/html', 'application/xhtml+xml'], true);
    }

    public function contentTypeForExtension(string $extension): string
    {
        return match ($this->normalizeFormat($extension)) {
            'mp4', 'm4v' => 'video/mp4',
            'mov' => 'video/quicktime',
            'webm' => 'video/webm',
            'mkv' => 'video/x-matroska',
            'avi' => 'video/x-msvideo',
            default => 'application/octet-stream',
        };
    }

    public function normalizedContentType(?string $contentType): string
    {
        return Str::lower(trim(Str::before((string) $contentType, ';')));
    }

    private function isDirectFormat(string $format): bool
    {
        return in_array($format, $this->directFormats(), true);
    }

    private function isPlaylistFormat(string $format): bool
    {
        return in_array($format, self::PLAYLIST_FORMATS, true);
    }

    private function isPlaylistContentType(?string $contentType): bool
    {
        return in_array($this->normalizedContentType($contentType), self::PLAYLIST_CONTENT_TYPES, true);
    }

    private function urlFormat(LicensedMedia $media): ?string
    {
        $url = $this->effectiveUrl($media);

        return $url === null
            ? null
            : $this->normalizeFormat(pathinfo((string) parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION));
    }

    /** @return list<string> */
    private function directFormats(): array
    {
        return collect((array) config('playback.downloads.allowed_formats', ['mp4', 'm4v', 'mov', 'webm', 'mkv', 'avi']))
            ->map(fn (mixed $format): string => Str::lower(trim((string) $format)))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    private function normalizeFormat(mixed $format): ?string
    {
        if (! is_string($format)) {
            return null;
        }

        $format = Str::lower(ltrim(trim($format), '.'));

        return $format !== '' ? $format : null;
    }
}
