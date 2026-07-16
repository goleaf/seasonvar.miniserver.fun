<?php

declare(strict_types=1);

namespace App\Services\Media;

use App\DTOs\ExternalMediaFileSizeResultData;
use App\DTOs\LicensedMediaFileSizeSourceData;
use App\Enums\MediaFileSizeMetadataWriteStatus;
use App\Models\LicensedMedia;
use App\Services\Catalog\CatalogCacheInvalidator;
use Illuminate\Support\Str;

final class LicensedMediaFileSizeMetadataWriter
{
    private const MATERIAL_ATTRIBUTES = [
        'file_size_bytes',
        'file_size_check_status',
        'file_size_source',
        'file_size_http_status',
        'file_size_check_error',
    ];

    public function __construct(
        private readonly CatalogCacheInvalidator $cache,
    ) {}

    public function snapshot(LicensedMedia $media): LicensedMediaFileSizeSourceData
    {
        return new LicensedMediaFileSizeSourceData(
            mediaId: (int) $media->getKey(),
            catalogTitleId: $media->catalog_title_id,
            playbackUrl: $media->playback_url,
            path: $media->path,
            format: $media->format,
        );
    }

    public function writeIfSourceMatches(
        LicensedMedia $media,
        LicensedMediaFileSizeSourceData $source,
        ExternalMediaFileSizeResultData $result,
    ): MediaFileSizeMetadataWriteStatus {
        if ((int) $media->getKey() !== $source->mediaId) {
            return MediaFileSizeMetadataWriteStatus::SourceChanged;
        }

        $attributes = $this->attributes($result);
        $before = $media->only(self::MATERIAL_ATTRIBUTES);
        $updated = LicensedMedia::query()
            ->whereKey($source->mediaId)
            ->where('catalog_title_id', $source->catalogTitleId)
            ->where('playback_url', $source->playbackUrl)
            ->where('path', $source->path)
            ->where('format', $source->format)
            ->update($attributes);

        if ($updated !== 1) {
            return MediaFileSizeMetadataWriteStatus::SourceChanged;
        }

        $media->forceFill($attributes);

        if ($before === $media->only(self::MATERIAL_ATTRIBUTES)) {
            return MediaFileSizeMetadataWriteStatus::Unchanged;
        }

        if ($source->catalogTitleId !== null && $source->catalogTitleId > 0) {
            $this->cache->titlePlaybackMetadataChanged($source->catalogTitleId);
        }

        return MediaFileSizeMetadataWriteStatus::Changed;
    }

    /** @return array<string, mixed> */
    private function attributes(ExternalMediaFileSizeResultData $result): array
    {
        $error = collect([$result->errorCategory, $result->safeErrorMessage])
            ->filter(fn (?string $value): bool => is_string($value) && $value !== '')
            ->implode(': ');

        return [
            'file_size_bytes' => $result->bytes,
            'file_size_checked_at' => $result->checkedAt,
            'file_size_check_status' => $result->status,
            'file_size_source' => Str::limit($result->source, 64, ''),
            'file_size_http_status' => $result->httpStatus,
            'file_size_check_error' => $error !== '' ? Str::limit($error, 255, '') : null,
        ];
    }
}
