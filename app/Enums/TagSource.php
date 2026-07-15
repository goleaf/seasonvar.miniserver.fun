<?php

declare(strict_types=1);

namespace App\Enums;

enum TagSource: string
{
    case System = 'system';
    case Editorial = 'editorial';
    case Seasonvar = 'seasonvar';
    case Legacy = 'legacy';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
