<?php

declare(strict_types=1);

namespace App\Enums;

enum CatalogCollectionQualityIssueSeverity: string
{
    case Critical = 'critical';
    case Warning = 'warning';
    case Notice = 'notice';
}
