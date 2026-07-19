<?php

declare(strict_types=1);

namespace App\Services\ReleaseCalendar;

use App\Enums\CatalogRecommendationFeedback;
use App\Enums\ReleaseCalendarNotificationType;
use App\Enums\ReleaseScheduleStatus;
use App\Models\ReleaseCalendarNotificationPreference;
use App\Models\ReleaseCalendarSubscription;
use App\Models\ReleaseScheduleEntry;
use App\Models\User;
use App\Notifications\ReleaseCalendarActivityNotification;
use App\Support\DeterministicUuid;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Throwable;

final class ReleaseCalendarNotificationService
{
    public function __construct(
        private readonly ReleaseScheduleVisibility $visibility,
    ) {}

    public function announced(ReleaseScheduleEntry $entry): void
    {
        $this->dispatch(
            $entry,
            $entry->status === ReleaseScheduleStatus::Released
                ? ReleaseCalendarNotificationType::Released
                : ReleaseCalendarNotificationType::Announced,
        );
    }

    public function changed(ReleaseScheduleEntry $entry): void
    {
        $kind = match ($entry->status) {
            ReleaseScheduleStatus::Released => ReleaseCalendarNotificationType::Released,
            ReleaseScheduleStatus::Postponed, ReleaseScheduleStatus::Delayed => ReleaseCalendarNotificationType::Postponed,
            ReleaseScheduleStatus::Cancelled => ReleaseCalendarNotificationType::Cancelled,
            default => ReleaseCalendarNotificationType::DateChanged,
        };

        $correction = $entry->corrections()->latest('revision')->first();
        $this->dispatch(
            $entry,
            $kind,
            $correction?->previous_starts_at?->toIso8601String() ?? $correction?->previous_date_value?->toDateString(),
            $correction?->new_starts_at?->toIso8601String() ?? $correction?->new_date_value?->toDateString(),
        );
    }

    private function dispatch(
        ReleaseScheduleEntry $entry,
        ReleaseCalendarNotificationType $kind,
        ?string $previousDate = null,
        ?string $newDate = null,
    ): void {
        if (! $entry->is_public || ! $entry->notifications_enabled) {
            return;
        }

        $this->safely(function () use ($entry, $kind, $previousDate, $newDate): void {
            $subscriptionField = $kind === ReleaseCalendarNotificationType::DateChanged
                ? 'date_change_notifications'
                : $entry->entry_type->notificationPreference();

            $subscriptions = ReleaseCalendarSubscription::query()
                ->where('catalog_title_id', $entry->catalog_title_id)
                ->where($subscriptionField, true)
                ->whereDoesntHave('user.catalogTitleStates', fn ($state) => $state
                    ->where('catalog_title_id', $entry->catalog_title_id)
                    ->whereIn('recommendation_feedback', [
                        CatalogRecommendationFeedback::NotInterested->value,
                        CatalogRecommendationFeedback::Blacklisted->value,
                    ]));
            $recipient = (clone $subscriptions)->with('user:id,name')->first()?->user;

            if (! $recipient instanceof User
                || ! $this->visibility->constrain(ReleaseScheduleEntry::query()->whereKey($entry->id), $recipient)->exists()) {
                return;
            }

            $subscriptions
                ->with([
                    'user:id,name',
                    'user.releaseCalendarNotificationPreference' => fn ($query) => $query->select([
                        'user_id',
                        ...ReleaseCalendarNotificationPreference::FIELDS,
                    ]),
                ])
                ->chunkById(200, function ($subscriptions) use ($entry, $kind, $subscriptionField, $previousDate, $newDate): void {
                    foreach ($subscriptions as $subscription) {
                        $recipient = $subscription->user;

                        if (! $recipient instanceof User || ! $this->preferenceEnabled($recipient, $entry, $kind, $subscriptionField)) {
                            continue;
                        }

                        $notification = new ReleaseCalendarActivityNotification(
                            $kind,
                            $entry->public_id,
                            $entry->entry_type->value,
                            $entry->status->value,
                            $entry->revision,
                            $previousDate,
                            $newDate,
                        );
                        $notification->id = DeterministicUuid::from(
                            'seasonvar.release-calendar.notification',
                            implode(':', [$recipient->id, $entry->public_id, $entry->revision, $kind->value]),
                        );
                        $this->deliver($recipient, $notification);
                    }
                });
        });
    }

    private function preferenceEnabled(User $user, ReleaseScheduleEntry $entry, ReleaseCalendarNotificationType $kind, string $field): bool
    {
        $preference = $user->releaseCalendarNotificationPreference
            ?? new ReleaseCalendarNotificationPreference(['user_id' => $user->id]);

        if ($kind === ReleaseCalendarNotificationType::Cancelled) {
            return $preference->cancelled_notifications;
        }

        if ($kind === ReleaseCalendarNotificationType::Postponed) {
            return $preference->postponed_notifications;
        }

        return (bool) $preference->getAttribute($field);
    }

    private function deliver(User $recipient, ReleaseCalendarActivityNotification $notification): void
    {
        DB::transaction(function () use ($recipient, $notification): void {
            $locked = User::query()->lockForUpdate()->find($recipient->id);

            if (! $locked instanceof User || $locked->notifications()->whereKey($notification->id)->exists()) {
                return;
            }

            try {
                $locked->notify($notification);
            } catch (QueryException $exception) {
                if (! $locked->notifications()->whereKey($notification->id)->exists()) {
                    throw $exception;
                }
            }
        }, attempts: 3);
    }

    private function safely(callable $operation): void
    {
        try {
            $operation();
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
