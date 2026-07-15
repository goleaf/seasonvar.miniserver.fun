<?php

declare(strict_types=1);

namespace App\Enums;

enum CatalogCollectionModerationStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Hidden = 'hidden';
    case Archived = 'archived';

    public function label(): string
    {
        return __("collections.moderation.{$this->value}");
    }

    public function isPubliclyViewable(): bool
    {
        return $this === self::Approved;
    }
}
