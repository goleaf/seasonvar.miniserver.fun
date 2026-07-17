<?php

declare(strict_types=1);

namespace App\Enums;

enum ReleaseCalendarNotificationType: string
{
    case Announced = 'announced';
    case DateChanged = 'date_changed';
    case Released = 'released';
    case Postponed = 'postponed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return __('calendar.notifications.'.$this->value);
    }
}
