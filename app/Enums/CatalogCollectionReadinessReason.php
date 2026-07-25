<?php

declare(strict_types=1);

namespace App\Enums;

enum CatalogCollectionReadinessReason: string
{
    case NotEditorial = 'not_editorial';
    case NotPublic = 'not_public';
    case NotApproved = 'not_approved';
    case NotPublished = 'not_published';
    case Deleted = 'deleted';
    case SourceMissing = 'source_missing';
    case InsufficientVisibleItems = 'insufficient_visible_items';
    case UnavailableItems = 'unavailable_items';

    public function label(): string
    {
        return __("collections.admin.readiness_reasons.{$this->value}");
    }
}
