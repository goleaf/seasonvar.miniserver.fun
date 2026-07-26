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
    case MissingCategory = 'missing_category';
    case InactiveCategory = 'inactive_category';
    case TooManyItems = 'too_many_items';
    case InsufficientVisibleItems = 'insufficient_visible_items';
    case UnavailableItems = 'unavailable_items';
    case StaleQuality = 'stale_quality';
    case LowQuality = 'low_quality';

    public function label(): string
    {
        return __("collections.admin.readiness_reasons.{$this->value}");
    }
}
