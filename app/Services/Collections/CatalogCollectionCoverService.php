<?php

declare(strict_types=1);

namespace App\Services\Collections;

use App\Enums\CatalogCollectionModerationStatus;
use App\Enums\CatalogCollectionType;
use App\Enums\CatalogCollectionVisibility;
use App\Models\CatalogCollection;
use App\Models\User;
use App\Services\Comments\CommentTargetLifecycleService;
use App\Services\Storage\PrivateUploadStorage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Throwable;

final class CatalogCollectionCoverService
{
    public function __construct(
        private readonly PrivateUploadStorage $uploads,
        private readonly CatalogCollectionCacheInvalidator $cache,
        private readonly CommentTargetLifecycleService $comments,
        private readonly CatalogCollectionRateLimiter $rateLimiter,
    ) {}

    public function replace(User $actor, CatalogCollection $collection, UploadedFile $file): CatalogCollection
    {
        Gate::forUser($actor)->authorize('update', $collection);
        $this->rateLimiter->ensure($actor, 'cover', 'cover');
        $stored = $this->uploads->store($file, 'catalog-collections/'.$collection->public_id);

        try {
            [$oldDisk, $oldPath] = DB::transaction(function () use ($collection, $stored): array {
                $locked = CatalogCollection::query()->lockForUpdate()->findOrFail($collection->id);
                $oldDisk = $locked->cover_disk;
                $oldPath = $locked->cover_path;
                $locked->forceFill([
                    'cover_disk' => $stored->disk,
                    'cover_path' => $stored->path,
                    'cover_mime_type' => $stored->mimeType,
                    'cover_size' => $stored->size,
                    'cover_version' => $locked->cover_version + 1,
                    'content_version' => $locked->content_version + 1,
                ]);

                if ($locked->type === CatalogCollectionType::User
                    && $locked->visibility !== CatalogCollectionVisibility::Private) {
                    $locked->moderation_status = CatalogCollectionModerationStatus::Pending;
                    $locked->is_featured = false;
                    $locked->published_at = null;
                }

                $locked->save();

                return [$oldDisk, $oldPath];
            }, attempts: 3);
        } catch (Throwable $exception) {
            $this->uploads->delete($stored);

            throw $exception;
        }

        if ($oldDisk === config('uploads.disk') && is_string($oldPath) && $oldPath !== '') {
            $this->uploads->delete($oldPath);
        }

        $collection->refresh();
        $this->cache->changed($collection);

        return $collection;
    }

    public function remove(User $actor, CatalogCollection $collection): CatalogCollection
    {
        Gate::forUser($actor)->authorize('update', $collection);

        [$disk, $path] = DB::transaction(function () use ($collection): array {
            $locked = CatalogCollection::query()->lockForUpdate()->findOrFail($collection->id);
            $disk = $locked->cover_disk;
            $path = $locked->cover_path;
            $locked->forceFill([
                'cover_disk' => null,
                'cover_path' => null,
                'cover_mime_type' => null,
                'cover_size' => null,
                'cover_version' => $locked->cover_version + 1,
                'content_version' => $locked->content_version + 1,
            ]);

            if ($locked->type === CatalogCollectionType::User
                && $locked->visibility !== CatalogCollectionVisibility::Private) {
                $locked->moderation_status = CatalogCollectionModerationStatus::Pending;
                $locked->is_featured = false;
                $locked->published_at = null;
            }

            $locked->save();

            return [$disk, $path];
        }, attempts: 3);

        if ($disk === config('uploads.disk') && is_string($path) && $path !== '') {
            $this->uploads->delete($path);
        }

        $collection->refresh();
        $this->cache->changed($collection);

        return $collection;
    }

    public function deleteWithCollection(CatalogCollection $collection): void
    {
        DB::transaction(function () use ($collection): void {
            $locked = CatalogCollection::query()->withTrashed()->lockForUpdate()->findOrFail($collection->id);
            abort_unless($locked->trashed(), 404);
            $disk = $locked->cover_disk;
            $path = $locked->cover_path;
            $this->comments->retireCollection($locked);
            $locked->forceDelete();

            if ($disk === config('uploads.disk') && is_string($path) && $path !== '') {
                DB::afterCommit(function () use ($path): void {
                    $this->uploads->delete($path);
                });
            }
        }, attempts: 3);

        $this->cache->changed($collection);
    }

    public function url(CatalogCollection $collection): ?string
    {
        if ($collection->cover_path === null || $collection->cover_version < 1) {
            return null;
        }

        return route('collections.cover', [
            'publicId' => $collection->public_id,
            'version' => $collection->cover_version,
        ]);
    }
}
