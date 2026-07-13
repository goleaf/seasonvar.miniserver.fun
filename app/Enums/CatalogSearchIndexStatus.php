<?php

declare(strict_types=1);

namespace App\Enums;

enum CatalogSearchIndexStatus: string
{
    case Building = 'building';
    case Ready = 'ready';
    case Stale = 'stale';
    case Failed = 'failed';
}
