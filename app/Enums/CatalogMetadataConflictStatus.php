<?php

declare(strict_types=1);

namespace App\Enums;

enum CatalogMetadataConflictStatus: string
{
    case Open = 'open';
    case Resolved = 'resolved';
}
