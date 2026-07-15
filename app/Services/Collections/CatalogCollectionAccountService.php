<?php

declare(strict_types=1);

namespace App\Services\Collections;

use App\Enums\CatalogCollectionVisibility;
use App\Models\CatalogCollection;
use App\Models\User;
use App\Services\Comments\CommentTargetLifecycleService;
use App\Services\Storage\PrivateUploadStorage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

final class CatalogCollectionAccountService
{
    public function __construct(
        private readonly CatalogCollectionCacheInvalidator $cache,
        private readonly PrivateUploadStorage $uploads,
        private readonly CommentTargetLifecycleService $comments,
        private readonly CatalogCollectionSchema $schema,
    ) {}

    /** @return list<array<string, mixed>> */
    public function export(User $user): array
    {
        if (! $this->schema->available()) {
            return [];
        }

        return CatalogCollection::query()
            ->withTrashed()
            ->where('owner_id', $user->id)
            ->with(['translations', 'items' => fn ($query) => $query
                ->with('catalogTitleWithTrashed:id,slug,title,original_title')
                ->orderBy('position')
                ->orderBy('id')])
            ->orderBy('created_at')
            ->orderBy('id')
            ->get()
            ->map(function (CatalogCollection $collection): array {
                $shareable = in_array($collection->visibility, [
                    CatalogCollectionVisibility::Public,
                    CatalogCollectionVisibility::Unlisted,
                ], true) && $collection->deleted_at === null;

                return [
                    'public_id' => $collection->public_id,
                    'name' => $collection->name,
                    'description' => $collection->description,
                    'type' => $collection->type->value,
                    'visibility' => $collection->visibility->value,
                    'moderation_status' => $collection->moderation_status->value,
                    'sort_mode' => $collection->sort_mode->value,
                    'content_locale' => $collection->content_locale,
                    'translations' => $collection->translations->map(fn ($translation): array => [
                        'locale' => $translation->locale,
                        'name' => $translation->name,
                        'description' => $translation->description,
                        'seo_title' => $translation->seo_title,
                        'seo_description' => $translation->seo_description,
                    ])->all(),
                    'public_url' => $shareable ? route('collections.show', ['collectionSlug' => $collection->slug]) : null,
                    'created_at' => $collection->created_at?->toAtomString(),
                    'updated_at' => $collection->updated_at?->toAtomString(),
                    'deleted_at' => $collection->deleted_at?->toAtomString(),
                    'items' => $collection->items->map(function ($item): array {
                        $title = $item->catalogTitleWithTrashed;

                        return [
                            'title_slug' => $title?->slug,
                            'title' => $title?->title,
                            'original_title' => $title?->original_title,
                            'position' => (int) $item->position,
                            'added_at' => $item->created_at?->toAtomString(),
                        ];
                    })->all(),
                ];
            })
            ->all();
    }

    public function purgeOwned(User $user): void
    {
        if (! $this->schema->available()) {
            return;
        }

        $collections = CatalogCollection::query()
            ->withTrashed()
            ->where('owner_id', $user->id)
            ->select(['id', 'cover_disk', 'cover_path'])
            ->lockForUpdate()
            ->get();

        $this->comments->retireCollections($collections->modelKeys());

        CatalogCollection::query()
            ->withTrashed()
            ->whereKey($collections->modelKeys())
            ->forceDelete();

        $covers = $collections
            ->filter(fn (CatalogCollection $collection): bool => $collection->cover_disk === config('uploads.disk') && filled($collection->cover_path))
            ->map(fn (CatalogCollection $collection): string => (string) $collection->cover_path)
            ->all();

        DB::afterCommit(function () use ($covers): void {
            foreach ($covers as $cover) {
                try {
                    $this->uploads->delete($cover);
                } catch (Throwable $exception) {
                    report($exception);
                }
            }
        });
        $this->cache->changed();
    }

    public function ownerIdentityChanged(User $user): void
    {
        if (Schema::hasTable('catalog_collections')
            && CatalogCollection::query()->where('owner_id', $user->id)->exists()) {
            $this->cache->changed();
        }
    }
}
