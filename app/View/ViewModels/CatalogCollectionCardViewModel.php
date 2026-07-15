<?php

declare(strict_types=1);

namespace App\View\ViewModels;

use App\Enums\CatalogCollectionType;
use App\Models\CatalogCollection;
use App\Models\User;
use App\Services\Collections\CatalogCollectionCoverService;
use App\Support\PlainText;

final readonly class CatalogCollectionCardViewModel
{
    public string $url;

    public ?string $ownerUrl;

    public ?string $imageUrl;

    public string $name;

    public string $description;

    public int $itemCount;

    public string $updatedAt;

    public ?string $updatedAtIso;

    public bool $featured;

    public bool $editorial;

    public string $visibilityLabel;

    public string $moderationStatusLabel;

    public string $typeLabel;

    public ?string $ownerName;

    public string $imageAlt;

    public string $emptyImageLabel;

    public string $itemCountLabel;

    public string $featuredLabel;

    public function __construct(
        CatalogCollection $collection,
        CatalogCollectionCoverService $covers,
        public bool $management = false,
    ) {
        $owner = $collection->relationLoaded('owner') ? $collection->getRelation('owner') : null;
        $this->url = $management
            ? route('collections.edit', ['collectionPublicId' => $collection->public_id])
            : route('collections.show', ['collectionSlug' => $collection->slug]);
        $this->ownerUrl = ! $owner instanceof User || $owner->public_id === null
            ? null
            : route('profiles.collections', ['userPublicId' => $owner->public_id]);
        $this->imageUrl = $covers->url($collection)
            ?? (is_string($collection->getAttribute('fallback_poster_url')) ? $collection->getAttribute('fallback_poster_url') : null);
        $this->name = (string) $collection->display_name;
        $this->description = PlainText::clean($collection->display_description);
        $this->itemCount = (int) ($management
            ? ($collection->total_items_count ?? 0)
            : ($collection->visible_items_count ?? 0));
        $this->updatedAt = $collection->updated_at?->format('d.m.Y') ?? '';
        $this->updatedAtIso = $collection->updated_at?->toAtomString();
        $this->featured = (bool) $collection->is_featured;
        $this->editorial = $collection->type === CatalogCollectionType::Editorial;
        $this->visibilityLabel = $collection->visibility->label();
        $this->moderationStatusLabel = $collection->moderation_status->label();
        $this->typeLabel = $collection->type->label();
        $this->ownerName = $owner instanceof User ? $owner->name : null;
        $this->imageAlt = __('collections.accessibility.collection_cover', ['name' => $this->name]);
        $this->emptyImageLabel = __('collections.page.cover_missing');
        $this->itemCountLabel = trans_choice('collections.page.items', $this->itemCount, ['count' => $this->itemCount]);
        $this->featuredLabel = __('collections.page.featured');
    }
}
