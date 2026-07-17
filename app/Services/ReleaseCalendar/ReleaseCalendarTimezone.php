<?php

declare(strict_types=1);

namespace App\Services\ReleaseCalendar;

use App\ValueObjects\AccountTimezone;
use InvalidArgumentException;

final class ReleaseCalendarTimezone
{
    public function public(): string
    {
        $candidates = array_unique([
            (string) config('release-calendar.default_timezone', ''),
            (string) config('account-settings.default_timezone', ''),
            (string) config('app.timezone', ''),
            'UTC',
        ]);

        foreach ($candidates as $timezone) {
            try {
                return AccountTimezone::from($timezone)->value;
            } catch (InvalidArgumentException) {
                continue;
            }
        }

        return 'UTC';
    }
}
