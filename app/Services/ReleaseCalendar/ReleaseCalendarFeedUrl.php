<?php

declare(strict_types=1);

namespace App\Services\ReleaseCalendar;

use App\Models\ReleaseCalendarFeed;

final readonly class ReleaseCalendarFeedUrl
{
    public function private(ReleaseCalendarFeed $feed): string
    {
        return route('calendar.feed', ['privateToken' => $feed->token_secret]);
    }

    public function apple(ReleaseCalendarFeed $feed): string
    {
        return preg_replace('#^https?://#', 'webcal://', $this->private($feed), 1)
            ?? $this->private($feed);
    }

    public function google(): string
    {
        return 'https://calendar.google.com/calendar/u/0/r/settings/addbyurl';
    }
}
