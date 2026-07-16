<?php

declare(strict_types=1);

namespace App\Services\ContentRequests;

use App\Enums\ContentRequestNotificationType;
use App\Enums\ContentRequestStatus;
use App\Models\ContentRequest;
use App\Models\ContentRequestNotificationPreference;
use App\Models\User;
use App\Notifications\ContentRequestActivityNotification;
use App\Support\DeterministicUuid;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Throwable;

final class ContentRequestNotificationService
{
    public function submitted(ContentRequest $request): void
    {
        $this->safely(function () use ($request): void {
            $request->loadMissing('requester:id,name');
            $recipients = User::query()
                ->whereIn('email', (array) config('seasonvar.admin_emails', []))
                ->get(['id', 'name'])
                ->when($request->requester instanceof User, fn ($users) => $users->push($request->requester))
                ->unique('id');

            foreach ($recipients as $recipient) {
                if ($request->requester instanceof User
                    && $recipient->is($request->requester)
                    && ! $this->enabled($recipient, 'requester')) {
                    continue;
                }

                $this->deliver(
                    $recipient,
                    'submitted:'.$request->public_id,
                    new ContentRequestActivityNotification(
                        ContentRequestNotificationType::Submitted,
                        $request->public_id,
                        $request->status->value,
                    ),
                );
            }
        });
    }

    /** @param array<int, string> $recipients */
    public function merged(ContentRequest $source, ContentRequest $canonical, ?User $actor, array $recipients): void
    {
        $this->safely(function () use ($source, $canonical, $actor, $recipients): void {
            User::query()->whereKey(array_keys($recipients))->get(['id', 'name'])->each(function (User $recipient) use ($source, $canonical, $actor, $recipients): void {
                if (($actor !== null && $recipient->is($actor)) || ! $this->enabled($recipient, $recipients[$recipient->id] ?? 'voted')) {
                    return;
                }

                $this->deliver(
                    $recipient,
                    'merge:'.$source->public_id.':'.$canonical->public_id.':'.$source->version,
                    new ContentRequestActivityNotification(
                        ContentRequestNotificationType::Merged,
                        $source->public_id,
                        ContentRequestStatus::Merged->value,
                        $canonical->public_id,
                    ),
                );
            });
        });
    }

    public function statusChanged(ContentRequest $request, ?User $actor = null): void
    {
        $this->safely(function () use ($request, $actor): void {
            $request->loadMissing(['requester:id,name', 'votes.user:id,name', 'followers.user:id,name', 'mergedInto:id,public_id']);
            $kind = match ($request->status) {
                ContentRequestStatus::ClarificationNeeded => ContentRequestNotificationType::Clarification,
                ContentRequestStatus::PartiallyCompleted => ContentRequestNotificationType::PartialCompletion,
                ContentRequestStatus::Completed => ContentRequestNotificationType::Completed,
                ContentRequestStatus::Merged, ContentRequestStatus::Duplicate => ContentRequestNotificationType::Merged,
                default => ContentRequestNotificationType::StatusChanged,
            };
            $roles = [];

            if ($request->requester instanceof User) {
                $roles[$request->requester->id] = ['user' => $request->requester, 'role' => 'requester'];
            }

            foreach ($request->followers as $follow) {
                if ($follow->user instanceof User && ! isset($roles[$follow->user->id])) {
                    $roles[$follow->user->id] = ['user' => $follow->user, 'role' => 'followed'];
                }
            }

            foreach ($request->votes as $vote) {
                if ($vote->user instanceof User && ! isset($roles[$vote->user->id])) {
                    $roles[$vote->user->id] = ['user' => $vote->user, 'role' => 'voted'];
                }
            }

            foreach ($roles as $recipient) {
                $user = $recipient['user'];

                if (($actor !== null && $user->is($actor)) || ! $this->enabled($user, $recipient['role'])) {
                    continue;
                }

                $key = implode(':', [$request->public_id, $request->version, $kind->value]);
                $this->deliver(
                    $user,
                    $key,
                    new ContentRequestActivityNotification(
                        $kind,
                        $request->public_id,
                        $request->status->value,
                        $request->mergedInto?->public_id,
                    ),
                );
            }
        });
    }

    private function enabled(User $user, string $role): bool
    {
        $preference = ContentRequestNotificationPreference::query()->find($user->id)
            ?? new ContentRequestNotificationPreference(['user_id' => $user->id]);

        return match ($role) {
            'requester' => $preference->requester_updates,
            'followed' => $preference->followed_updates,
            default => $preference->voted_updates,
        };
    }

    private function deliver(User $recipient, string $key, ContentRequestActivityNotification $notification): void
    {
        $notification->id = DeterministicUuid::from('seasonvar.content-request.notification', $recipient->id.':'.$key);

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
