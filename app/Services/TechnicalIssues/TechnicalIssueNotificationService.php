<?php

declare(strict_types=1);

namespace App\Services\TechnicalIssues;

use App\Enums\TechnicalIssueNotificationType;
use App\Models\TechnicalIssue;
use App\Models\TechnicalIssueNotificationPreference;
use App\Models\User;
use App\Notifications\TechnicalIssueActivityNotification;
use App\Support\DeterministicUuid;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Throwable;

final class TechnicalIssueNotificationService
{
    public function submitted(int $issueId): void
    {
        $this->safely(function () use ($issueId): void {
            $issue = TechnicalIssue::query()->with('requester:id,name')->find($issueId);

            if (! $issue instanceof TechnicalIssue) {
                return;
            }

            $admins = User::query()->whereIn('email', (array) config('seasonvar.admin_emails', []))->get(['id', 'name']);
            $recipients = $admins->when($issue->requester instanceof User, fn ($users) => $users->push($issue->requester))->unique('id');

            foreach ($recipients as $recipient) {
                if ($issue->requester instanceof User && $recipient->is($issue->requester) && ! $this->enabled($recipient, 'requester')) {
                    continue;
                }

                $this->deliver($recipient, $issue, TechnicalIssueNotificationType::Submitted);
            }
        });
    }

    public function changed(
        int $issueId,
        TechnicalIssueNotificationType $kind,
        ?int $actorId = null,
        ?string $canonicalPublicId = null,
    ): void {
        $this->safely(function () use ($issueId, $kind, $actorId, $canonicalPublicId): void {
            $issue = TechnicalIssue::query()
                ->with(['requester:id,name', 'followers.user:id,name', 'confirmations.user:id,name', 'assignedTo:id,name'])
                ->find($issueId);

            if (! $issue instanceof TechnicalIssue) {
                return;
            }

            $recipients = [];

            if ($kind === TechnicalIssueNotificationType::Assigned) {
                if ($issue->assignedTo instanceof User) {
                    $recipients[$issue->assignedTo->id] = ['user' => $issue->assignedTo, 'role' => 'assignee'];
                }
            } else {
                if ($issue->requester instanceof User) {
                    $recipients[$issue->requester->id] = ['user' => $issue->requester, 'role' => 'requester'];
                }

                foreach ($issue->followers as $follower) {
                    if ($follower->user instanceof User && ! isset($recipients[$follower->user->id])) {
                        $recipients[$follower->user->id] = ['user' => $follower->user, 'role' => 'follower'];
                    }
                }

                foreach ($issue->confirmations as $confirmation) {
                    if ($confirmation->user instanceof User && ! isset($recipients[$confirmation->user->id])) {
                        $recipients[$confirmation->user->id] = ['user' => $confirmation->user, 'role' => 'confirmer'];
                    }
                }
            }

            foreach ($recipients as $recipient) {
                $user = $recipient['user'];

                if ($actorId === $user->id || ! $this->enabled($user, $recipient['role'], $kind)) {
                    continue;
                }

                $this->deliver($user, $issue, $kind, $canonicalPublicId);
            }
        });
    }

    private function enabled(User $user, string $role, ?TechnicalIssueNotificationType $kind = null): bool
    {
        if ($role === 'assignee') {
            return true;
        }

        $preference = TechnicalIssueNotificationPreference::query()->find($user->id)
            ?? new TechnicalIssueNotificationPreference(['user_id' => $user->id]);

        if (in_array($kind, [TechnicalIssueNotificationType::SupportReply, TechnicalIssueNotificationType::Clarification], true)
            && ! $preference->support_replies) {
            return false;
        }

        return match ($role) {
            'requester' => $preference->requester_updates,
            'follower' => $preference->follower_updates,
            default => $preference->confirmer_updates,
        };
    }

    private function deliver(
        User $recipient,
        TechnicalIssue $issue,
        TechnicalIssueNotificationType $kind,
        ?string $canonicalPublicId = null,
    ): void {
        $notification = new TechnicalIssueActivityNotification(
            kind: $kind,
            issuePublicId: $issue->public_id,
            publicNumber: $issue->public_number,
            issueType: $issue->type->value,
            status: $issue->status->value,
            revision: $issue->version,
            canonicalPublicId: $canonicalPublicId,
        );
        $notification->id = DeterministicUuid::from(
            'seasonvar.technical-issue.notification',
            implode(':', [$recipient->id, $issue->public_id, $issue->version, $kind->value, $canonicalPublicId]),
        );

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
