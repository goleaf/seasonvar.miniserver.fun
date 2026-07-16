<?php

declare(strict_types=1);

namespace App\Enums;

enum CatalogWatchStatus: string
{
    case Planned = 'planned';
    case Watching = 'watching';
    case Completed = 'completed';
    case Dropped = 'dropped';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
