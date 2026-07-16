<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Number;

final class HumanFileSizeFormatter
{
    private const UNITS = ['B', 'KB', 'MB', 'GB', 'TB'];

    public function format(?int $bytes, ?string $locale = null): ?string
    {
        if ($bytes === null) {
            return null;
        }

        $value = (float) $bytes;
        $unit = 0;

        while ($value >= 1024 && $unit < count(self::UNITS) - 1) {
            $value /= 1024;
            $unit++;
        }

        $maximumPrecision = match (true) {
            $unit === 0, $value >= 100 => 0,
            $value >= 10 => 1,
            default => 2,
        };

        return Number::format(
            $value,
            maxPrecision: $maximumPrecision,
            locale: $locale ?? app()->getLocale(),
        ).' '.self::UNITS[$unit];
    }
}
