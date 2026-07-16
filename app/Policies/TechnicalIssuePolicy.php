<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\TechnicalIssue;
use App\Models\TechnicalIssueAttachment;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

final class TechnicalIssuePolicy
{
    public function create(User $user): bool
    {
        return (bool) config('technical-issues.enabled', true);
    }

    public function view(User $user, TechnicalIssue $issue): bool
    {
        return $this->manage($user, $issue)
            || $issue->requester_id === $user->id
            || $issue->followers()->where('user_id', $user->id)->exists()
            || $issue->confirmations()->where('user_id', $user->id)->exists()
            || $issue->mergedDuplicates()->where('requester_id', $user->id)->exists();
    }

    public function update(User $user, TechnicalIssue $issue): bool
    {
        return $issue->requester_id === $user->id && $issue->status->requesterCanEdit();
    }

    public function withdraw(User $user, TechnicalIssue $issue): bool
    {
        return $issue->requester_id === $user->id && $issue->status->requesterCanWithdraw();
    }

    public function engage(User $user, TechnicalIssue $issue): bool
    {
        return $user->hasVerifiedEmail() && $issue->status->isOpen();
    }

    public function confirm(User $user, TechnicalIssue $issue): bool
    {
        return $issue->requester_id !== $user->id
            && $this->engage($user, $issue)
            && $this->view($user, $issue);
    }

    public function follow(User $user, TechnicalIssue $issue): bool
    {
        return $this->engage($user, $issue) && $this->view($user, $issue);
    }

    public function reply(User $user, TechnicalIssue $issue): bool
    {
        return $this->manage($user, $issue)
            || ($issue->requester_id === $user->id && in_array($issue->status->value, [
                'submitted', 'triage_pending', 'clarification_needed', 'waiting_for_requester', 'reopened', 'in_progress',
            ], true));
    }

    public function verify(User $user, TechnicalIssue $issue): bool
    {
        return $issue->status->value === 'resolved'
            && ($this->manage($user, $issue)
                || $issue->requester_id === $user->id
                || $issue->confirmations()->where('user_id', $user->id)->exists());
    }

    public function reopen(User $user, TechnicalIssue $issue): bool
    {
        return in_array($issue->status->value, ['resolved', 'resolution_verified', 'closed'], true)
            && ($this->manage($user, $issue)
                || $issue->requester_id === $user->id
                || $issue->confirmations()->where('user_id', $user->id)->exists());
    }

    public function viewAttachment(User $user, TechnicalIssueAttachment $attachment): bool
    {
        $issue = $attachment->technicalIssue;

        if ($this->manage($user, $issue)) {
            return true;
        }

        if ($attachment->technical_issue_message_id === null) {
            return $attachment->uploader_id === $user->id;
        }

        return $attachment->message?->visibility?->value === 'requester_visible'
            && ($issue->requester_id === $user->id || $attachment->uploader_id === $user->id);
    }

    public function manage(User $user, TechnicalIssue $issue): bool
    {
        return Gate::forUser($user)->allows('manage-technical-issues');
    }
}
