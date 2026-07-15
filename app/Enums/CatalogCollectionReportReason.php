<?php

declare(strict_types=1);

namespace App\Enums;

enum CatalogCollectionReportReason: string
{
    case Spam = 'spam';
    case Abuse = 'abuse';
    case Copyright = 'copyright';
    case Misleading = 'misleading';
    case Offensive = 'offensive';
    case ProhibitedImage = 'prohibited_image';
    case Duplicate = 'duplicate';
    case Other = 'other';

    public function label(): string
    {
        return __("collections.reports.reasons.{$this->value}");
    }
}
