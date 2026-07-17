<?php

declare(strict_types=1);

namespace App\Actions\ReleaseCalendar;

use App\Models\ReleaseCalendarNotificationPreference;
use App\Models\User;
use App\Services\ReleaseCalendar\ReleaseCalendarCacheInvalidator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;

final readonly class UpdateReleaseCalendarNotificationPreferences
{
    public function __construct(private ReleaseCalendarCacheInvalidator $cache) {}

    /** @param array<string, bool> $preferences */
    public function handle(User $user, array $preferences): ReleaseCalendarNotificationPreference
    {
        Gate::forUser($user)->authorize('update-account-settings');
        $unknown = array_diff(array_keys($preferences), ReleaseCalendarNotificationPreference::FIELDS);

        if ($unknown !== [] || collect($preferences)->contains(fn (mixed $value): bool => ! is_bool($value))) {
            throw new InvalidArgumentException('Invalid release calendar notification preference payload.');
        }

        $preference = ReleaseCalendarNotificationPreference::query()->updateOrCreate(
            ['user_id' => $user->id],
            $preferences,
        );
        $invalidate = fn () => $this->cache->userChanged($user->id);
        DB::transactionLevel() > 0 ? DB::afterCommit($invalidate) : $invalidate();

        return $preference;
    }
}
