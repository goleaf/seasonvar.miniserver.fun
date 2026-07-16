<?php

declare(strict_types=1);

namespace App\Enums;

enum CatalogCollectionSyncStatus: string
{
    case Running = 'running';
    case Completed = 'completed';
    case Partial = 'partial';
    case Failed = 'failed';
}
