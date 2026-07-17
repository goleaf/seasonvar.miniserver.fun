<?php

declare(strict_types=1);

namespace App\Enums;

enum ReleaseCalendarSort: string
{
    case Earliest = 'earliest';
    case Latest = 'latest';
    case Title = 'title';

    public function label(): string
    {
        return __('calendar.sorts.'.$this->value);
    }
}
