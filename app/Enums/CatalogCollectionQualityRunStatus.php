<?php

declare(strict_types=1);

namespace App\Enums;

enum CatalogCollectionQualityRunStatus: string
{
    case Running = 'running';
    case Completed = 'completed';
    case Failed = 'failed';
    case DryRun = 'dry_run';
}
