<?php

declare(strict_types=1);

namespace App\Services\Collections\Import;

use App\DTOs\PreparedImportedCollectionCover;
use App\Models\CatalogCollection;
use App\Services\Collections\CatalogCollectionCacheInvalidator;
use App\Services\Crawler\PoliteHttpClient;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

final readonly class HdRezkaCollectionCoverImporter
{
    private const int DEFAULT_MAX_SOURCE_BYTES = 2_097_152;

    private const int MAX_SOURCE_BYTES = 10_485_760;

    public function __construct(
        private HdRezkaCollectionUrlGuard $urlGuard,
        private PoliteHttpClient $http,
        private CatalogCollectionCacheInvalidator $cache,
    ) {}

    public function prepare(string $sourceUrl): ?PreparedImportedCollectionCover
    {
        $url = $this->urlGuard->absolute($sourceUrl, HdRezkaCollectionUrlGuard::PURPOSE_COVER);
        $maximumBytes = $this->boundedConfig(
            'catalog-collection-imports.hdrezka.cover.max_source_bytes',
            self::DEFAULT_MAX_SOURCE_BYTES,
            1,
            self::MAX_SOURCE_BYTES,
        );

        try {
            $response = $this->http->get(
                $url,
                delaySeconds: $this->boundedConfig(
                    'catalog-collection-imports.hdrezka.delay_seconds',
                    3,
                    0,
                    60,
                ),
                headers: ['Accept' => 'image/avif,image/webp,image/png,image/jpeg;q=0.9,*/*;q=0.1'],
                maxResponseBytes: $maximumBytes,
                httpVersion: (string) config('catalog-collection-imports.hdrezka.http_version', '2.0'),
            );
        } catch (Throwable) {
            return null;
        }

        if (! $response->successful()) {
            return null;
        }

        $declaredMime = mb_strtolower(trim(explode(';', $response->header('Content-Type'))[0] ?? ''));

        if (! in_array($declaredMime, ['image/jpeg', 'image/png', 'image/webp'], true)) {
            return null;
        }

        $bytes = $response->body();

        if ($bytes === '' || strlen($bytes) > $maximumBytes) {
            return null;
        }

        $imageInfo = @getimagesizefromstring($bytes);
        $actualMime = is_array($imageInfo) ? ($imageInfo['mime'] ?? null) : null;
        $width = is_array($imageInfo) ? (int) ($imageInfo[0] ?? 0) : 0;
        $height = is_array($imageInfo) ? (int) ($imageInfo[1] ?? 0) : 0;
        $maximumDimension = $this->boundedConfig(
            'catalog-collection-imports.hdrezka.cover.max_source_dimension',
            8000,
            1,
            20_000,
        );
        $maximumPixels = $this->boundedConfig(
            'catalog-collection-imports.hdrezka.cover.max_source_pixels',
            32_000_000,
            1,
            100_000_000,
        );

        if (! in_array($actualMime, ['image/jpeg', 'image/png', 'image/webp'], true)
            || $actualMime !== $declaredMime
            || $width < 1
            || $height < 1
            || $width > $maximumDimension
            || $height > $maximumDimension
            || $width * $height > $maximumPixels) {
            return null;
        }

        $source = @imagecreatefromstring($bytes);

        if ($source === false) {
            return null;
        }

        try {
            $maximumWidth = $this->boundedConfig(
                'catalog-collection-imports.hdrezka.cover.max_width',
                1280,
                1,
                4096,
            );
            $maximumHeight = $this->boundedConfig(
                'catalog-collection-imports.hdrezka.cover.max_height',
                720,
                1,
                4096,
            );
            $scale = min(1, $maximumWidth / $width, $maximumHeight / $height);
            $targetWidth = max(1, (int) floor($width * $scale));
            $targetHeight = max(1, (int) floor($height * $scale));
            $target = imagecreatetruecolor($targetWidth, $targetHeight);

            if ($target === false) {
                return null;
            }

            try {
                imagealphablending($target, false);
                imagesavealpha($target, true);
                $transparent = imagecolorallocatealpha($target, 0, 0, 0, 127);
                imagefilledrectangle($target, 0, 0, $targetWidth, $targetHeight, $transparent);

                if (! imagecopyresampled(
                    $target,
                    $source,
                    0,
                    0,
                    0,
                    0,
                    $targetWidth,
                    $targetHeight,
                    $width,
                    $height,
                )) {
                    return null;
                }

                $webp = $this->encodeWebp($target);
            } finally {
                unset($target);
            }
        } finally {
            unset($source);
        }

        if ($webp === null || $webp === '') {
            return null;
        }

        $webpInfo = @getimagesizefromstring($webp);

        if (! is_array($webpInfo)
            || ($webpInfo['mime'] ?? null) !== 'image/webp'
            || (int) ($webpInfo[0] ?? 0) !== $targetWidth
            || (int) ($webpInfo[1] ?? 0) !== $targetHeight) {
            return null;
        }

        return new PreparedImportedCollectionCover(
            bytes: $webp,
            contentHash: hash('sha256', $webp),
            mimeType: 'image/webp',
            size: strlen($webp),
            width: $targetWidth,
            height: $targetHeight,
        );
    }

    public function apply(CatalogCollection $collection, PreparedImportedCollectionCover $cover): bool
    {
        $disk = (string) config('uploads.disk', 'uploads');

        if ((string) config('uploads.visibility', 'private') !== 'private') {
            throw new RuntimeException('Импортированные обложки должны храниться приватно.');
        }

        $path = "catalog-collections/{$collection->public_id}/imported/{$cover->contentHash}.webp";
        $storage = Storage::disk($disk);
        $alreadyExisted = $storage->exists($path);

        if (! $storage->put($path, $cover->bytes, ['visibility' => 'private'])) {
            throw new RuntimeException('Не удалось сохранить импортированную обложку коллекции.');
        }

        try {
            $changed = DB::transaction(function () use ($collection, $cover, $disk, $path): bool {
                $locked = CatalogCollection::query()->lockForUpdate()->findOrFail($collection->getKey());

                if ($locked->cover_disk === $disk
                    && $locked->cover_path === $path
                    && $locked->cover_mime_type === $cover->mimeType
                    && (int) $locked->cover_size === $cover->size) {
                    return false;
                }

                $oldDisk = $locked->cover_disk;
                $oldPath = $locked->cover_path;
                $locked->forceFill([
                    'cover_disk' => $disk,
                    'cover_path' => $path,
                    'cover_mime_type' => $cover->mimeType,
                    'cover_size' => $cover->size,
                    'cover_version' => (int) $locked->cover_version + 1,
                    'content_version' => (int) $locked->content_version + 1,
                ])->save();

                if ($oldDisk === $disk
                    && is_string($oldPath)
                    && $oldPath !== $path
                    && $this->isOwnedImportedPath($oldPath, (string) $locked->public_id)) {
                    DB::afterCommit(fn (): bool => $this->deleteBestEffort($disk, $oldPath));
                }

                return true;
            }, attempts: 3);
        } catch (Throwable $exception) {
            if (! $alreadyExisted) {
                $this->deleteBestEffort($disk, $path);
            }

            throw $exception;
        }

        if ($changed) {
            $collection->refresh();
            $this->cache->changed($collection);
        }

        return $changed;
    }

    private function encodeWebp(\GdImage $image): ?string
    {
        $bufferLevel = ob_get_level();
        ob_start();

        try {
            $encoded = imagewebp(
                $image,
                null,
                $this->boundedConfig('catalog-collection-imports.hdrezka.cover.quality', 82, 1, 100),
            );
            $bytes = ob_get_clean();
        } catch (Throwable) {
            $bytes = false;
            $encoded = false;
        } finally {
            while (ob_get_level() > $bufferLevel) {
                ob_end_clean();
            }
        }

        return $encoded && is_string($bytes) && $bytes !== '' ? $bytes : null;
    }

    private function boundedConfig(string $key, int $default, int $minimum, int $maximum): int
    {
        return max($minimum, min($maximum, (int) config($key, $default)));
    }

    private function isOwnedImportedPath(string $path, string $publicId): bool
    {
        return str_starts_with($path, "catalog-collections/{$publicId}/imported/")
            && str_ends_with($path, '.webp');
    }

    private function deleteBestEffort(string $disk, string $path): bool
    {
        try {
            return Storage::disk($disk)->delete($path);
        } catch (Throwable $exception) {
            report($exception);

            return false;
        }
    }
}
