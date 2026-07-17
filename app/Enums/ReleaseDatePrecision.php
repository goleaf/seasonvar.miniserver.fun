<?php

declare(strict_types=1);

namespace App\Enums;

enum ReleaseDatePrecision: string
{
    case ExactDateTime = 'exact_datetime';
    case ExactDate = 'exact_date';
    case Month = 'month';
    case Quarter = 'quarter';
    case Year = 'year';
    case DateRange = 'date_range';
    case Unknown = 'unknown';

    public function label(): string
    {
        return __('calendar.precisions.'.$this->value);
    }
}
