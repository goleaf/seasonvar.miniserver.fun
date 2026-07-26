<?php

declare(strict_types=1);

namespace App\Services\Collections;

use App\Enums\CatalogCollectionMode;
use App\Enums\CatalogCollectionVisibility;
use App\Models\CatalogCollection;
use App\Models\User;
use App\Services\Comments\CommentTargetLifecycleService;
use Illuminate\Support\Facades\Schema;

final class CatalogCollectionAccountService
{
    public function __construct(
        private readonly CatalogCollectionCacheInvalidator $cache,
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
            ->with([
                'translations:id,catalog_collection_id,locale,name,description,seo_title,seo_description',
                'category:id,public_id,parent_id,slug,position,is_active',
                'category.translations:id,catalog_collection_category_id,locale,name',
                'category.parent:id,public_id,parent_id,slug,position,is_active',
                'category.parent.translations:id,catalog_collection_category_id,locale,name',
                'items' => fn ($query) => $query
                    ->whereIn(
                        'catalog_collection_id',
                        CatalogCollection::query()
                            ->select('id')
                            ->where('mode', CatalogCollectionMode::Manual->value),
                    )
                    ->select(['id', 'catalog_collection_id', 'catalog_title_id', 'position', 'created_at'])
                    ->with('catalogTitleWithTrashed:id,slug,title,original_title')
                    ->orderBy('position')
                    ->orderBy('id'),
            ])
            ->orderBy('created_at')
            ->orderBy('id')
            ->get()
            ->map(function (CatalogCollection $collection): array {
                $shareable = ! $collection->isSmart() && in_array($collection->visibility, [
                    CatalogCollectionVisibility::Public,
                    CatalogCollectionVisibility::Unlisted,
                ], true) && $collection->deleted_at === null;

                return [
                    'public_id' => $collection->public_id,
                    'name' => $collection->name,
                    'description' => $collection->description,
                    'type' => $collection->type->value,
                    'mode' => $collection->mode->value,
                    'visibility' => $collection->visibility->value,
                    'moderation_status' => $collection->moderation_status->value,
                    'sort_mode' => $collection->sort_mode->value,
                    'smart_rules_version' => $collection->smart_rules_version,
                    'smart_rules' => $collection->isSmart()
                        ? $collection->smartRules()?->toArray()
                        : null,
                    'content_locale' => $collection->content_locale,
                    'category' => $collection->category === null ? null : [
                        'slug' => $collection->category->slug,
                        'name' => $collection->category->display_name,
                        'parent' => $collection->category->parent === null ? null : [
                            'slug' => $collection->category->parent->slug,
                            'name' => $collection->category->parent->display_name,
                        ],
                    ],
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
                    'items' => $collection->isSmart() ? [] : $collection->items->map(function ($item): array {
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
            ->select('id')
            ->lockForUpdate()
            ->get();

        $this->comments->retireCollections($collections->modelKeys());

        CatalogCollection::query()
            ->withTrashed()
            ->whereKey($collections->modelKeys())
            ->forceDelete();

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
