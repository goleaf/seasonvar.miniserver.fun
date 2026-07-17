<?php

declare(strict_types=1);

namespace App\Enums;

enum ReleaseCalendarView: string
{
    case Upcoming = 'upcoming';
    case Day = 'day';
    case Week = 'week';
    case Month = 'month';
    case Recent = 'recent';
    case Personal = 'personal';

    public function label(): string
    {
        return __('calendar.views.'.$this->value);
    }
}
