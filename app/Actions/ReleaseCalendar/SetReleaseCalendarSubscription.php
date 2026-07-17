<?php

declare(strict_types=1);

namespace App\Actions\ReleaseCalendar;

use App\Models\CatalogTitle;
use App\Models\ReleaseCalendarSubscription;
use App\Models\User;
use App\Services\ReleaseCalendar\ReleaseCalendarCacheInvalidator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

final readonly class SetReleaseCalendarSubscription
{
    public function __construct(private ReleaseCalendarCacheInvalidator $cache) {}

    public function handle(User $user, CatalogTitle $title, bool $enabled): bool
    {
        Gate::forUser($user)->authorize('update-account-settings');

        $changed = DB::transaction(function () use ($user, $title, $enabled): bool {
            $subscription = ReleaseCalendarSubscription::query()
                ->where('user_id', $user->id)
                ->where('catalog_title_id', $title->id)
                ->lockForUpdate()
                ->first();

            if ($enabled) {
                if ($subscription instanceof ReleaseCalendarSubscription) {
                    return false;
                }

                ReleaseCalendarSubscription::query()->create([
                    'user_id' => $user->id,
                    'catalog_title_id' => $title->id,
                ]);

                return true;
            }

            if (! $subscription instanceof ReleaseCalendarSubscription) {
                return false;
            }

            $subscription->delete();

            return true;
        }, attempts: 3);

        if ($changed) {
            $this->cache->userChanged($user->id);
        }

        return $enabled;
    }
}
