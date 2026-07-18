<?php

declare(strict_types=1);

namespace App\Services\Catalog;

use App\Enums\ReleaseScheduleStatus;
use App\Models\CatalogTitle;
use App\Models\CatalogTitleUpdateState;
use App\Models\ReleaseScheduleEntry;
use App\Models\User;
use App\Services\ReleaseCalendar\ReleaseCalendarSchema;
use App\Services\ReleaseCalendar\ReleaseScheduleVisibility;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

final readonly class CatalogPersonalUpdateService
{
    public function __construct(
        private PersonalLibrarySchema $schema,
        private ReleaseCalendarSchema $releaseCalendarSchema,
        private ReleaseScheduleVisibility $visibility,
    ) {}

    public function acknowledge(
        User $user,
        CatalogTitle $catalogTitle,
        bool $enforceRateLimit = true,
    ): ?CatalogTitleUpdateState {
        if (! $this->schema->ready() || ! $this->releaseCalendarSchema->ready()) {
            return null;
        }

        Gate::forUser($user)->authorize('interact', $catalogTitle);
        if ($enforceRateLimit) {
            $this->hitLimit($user);
        }
        $latestReleaseId = $this->latestVisibleReleaseId($user, $catalogTitle);

        return DB::transaction(function () use ($user, $catalogTitle, $latestReleaseId): CatalogTitleUpdateState {
            $now = now();

            CatalogTitleUpdateState::query()->insertOrIgnore([
                'user_id' => $user->id,
                'catalog_title_id' => $catalogTitle->id,
                'acknowledged_release_id' => $latestReleaseId,
                'acknowledged_at' => $now,
                'version' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $state = CatalogTitleUpdateState::query()
                ->whereBelongsTo($user)
                ->whereBelongsTo($catalogTitle)
                ->lockForUpdate()
                ->firstOrFail();

            if ($latestReleaseId <= $state->acknowledged_release_id) {
                return $state;
            }

            $state->forceFill([
                'acknowledged_release_id' => $latestReleaseId,
                'acknowledged_at' => $now,
                'version' => $state->version + 1,
            ])->save();

            return $state;
        }, attempts: 3);
    }

    private function latestVisibleReleaseId(User $user, CatalogTitle $catalogTitle): int
    {
        $query = ReleaseScheduleEntry::query()
            ->where('release_schedule_entries.catalog_title_id', $catalogTitle->id)
            ->where('release_schedule_entries.status', ReleaseScheduleStatus::Released->value)
            ->whereNotNull('release_schedule_entries.released_at');
        $this->visibility->constrain($query, $user);

        return (int) $query->max('release_schedule_entries.id');
    }

    private function hitLimit(User $user): void
    {
        $key = 'personal-library:acknowledge-updates:'.$user->id;

        if (RateLimiter::tooManyAttempts($key, 30)) {
            throw ValidationException::withMessages([
                'updates' => __('library.errors.rate_limited'),
            ]);
        }

        RateLimiter::hit($key, 60);
    }
}
