<?php

declare(strict_types=1);

namespace App\Services\ReleaseCalendar;

use Illuminate\Support\Facades\Schema;

final class ReleaseCalendarSchema
{
    private ?bool $ready = null;

    private ?bool $feedsReady = null;

    public function ready(): bool
    {
        return $this->ready ??= (bool) config('release-calendar.enabled', true)
            && Schema::hasTable('release_schedule_entries')
            && Schema::hasTable('release_schedule_corrections')
            && Schema::hasTable('release_calendar_subscriptions')
            && Schema::hasTable('release_calendar_notification_preferences');
    }

    public function feedsReady(): bool
    {
        return $this->feedsReady ??= $this->ready()
            && Schema::hasTable('release_calendar_feeds');
    }
}
