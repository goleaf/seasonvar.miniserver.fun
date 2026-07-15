<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Models\CatalogCollection;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin CatalogCollection */
final class CatalogCollectionResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $owner = $this->relationLoaded('owner') ? $this->owner : null;
        $ownerPublicId = $owner?->getAttribute('public_id');
        $ownerPublicId = is_string($ownerPublicId) && $ownerPublicId !== '' ? $ownerPublicId : null;
        $coverUrl = $this->cover_path !== null && $this->cover_version > 0
            ? route('collections.cover', ['publicId' => $this->public_id, 'version' => $this->cover_version])
            : ($this->getAttribute('fallback_poster_url') ?: null);

        return [
            'id' => $this->public_id,
            'slug' => $this->slug,
            'name' => $this->display_name,
            'description' => $this->display_description,
            'type' => $this->type->value,
            'visibility' => $this->visibility->value,
            'content_locale' => $this->content_locale,
            'featured' => $this->is_featured,
            'sort' => $this->sort_mode->value,
            'cover_url' => $coverUrl,
            'item_count' => (int) ($this->visible_items_count ?? 0),
            'owner' => $owner === null ? null : array_filter([
                'id' => $ownerPublicId,
                'name' => $owner->name,
                'collections_url' => $ownerPublicId === null
                    ? null
                    : route('profiles.collections', ['userPublicId' => $ownerPublicId]),
            ], static fn (mixed $value): bool => $value !== null),
            'published_at' => $this->published_at?->toJSON(),
            'updated_at' => $this->updated_at?->toJSON(),
            'links' => [
                'self' => route('api.v1.collections.show', ['collectionSlug' => $this->slug]),
                'web' => route('collections.show', ['collectionSlug' => $this->slug]),
            ],
        ];
    }
}
