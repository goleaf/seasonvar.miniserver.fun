<?php

declare(strict_types=1);

namespace App\Enums;

enum CatalogPopularityPeriod: string
{
    case Day = 'day';
    case Week = 'week';
    case Month = 'month';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    public function days(): int
    {
        return match ($this) {
            self::Day => 1,
            self::Week => 7,
            self::Month => 30,
        };
    }
}
