<?php

declare(strict_types=1);

namespace App\Enums;

enum TechnicalIssueStatus: string
{
    case Submitted = 'submitted';
    case TriagePending = 'triage_pending';
    case ClarificationNeeded = 'clarification_needed';
    case Confirmed = 'confirmed';
    case Assigned = 'assigned';
    case InProgress = 'in_progress';
    case WaitingForExternalSource = 'waiting_for_external_source';
    case WaitingForRequester = 'waiting_for_requester';
    case Resolved = 'resolved';
    case ResolutionVerified = 'resolution_verified';
    case Closed = 'closed';
    case Reopened = 'reopened';
    case Rejected = 'rejected';
    case Merged = 'merged';
    case Withdrawn = 'withdrawn';

    public function label(): string
    {
        return __("issues.statuses.{$this->value}");
    }

    public function isOpen(): bool
    {
        return ! in_array($this, [
            self::Resolved,
            self::ResolutionVerified,
            self::Closed,
            self::Rejected,
            self::Merged,
            self::Withdrawn,
        ], true);
    }

    public function isTerminal(): bool
    {
        return ! $this->isOpen();
    }

    public function requesterCanEdit(): bool
    {
        return in_array($this, [
            self::Submitted,
            self::TriagePending,
            self::ClarificationNeeded,
            self::WaitingForRequester,
            self::Reopened,
        ], true);
    }

    public function requesterCanWithdraw(): bool
    {
        return in_array($this, [self::Submitted, self::TriagePending, self::ClarificationNeeded], true);
    }

    public function canTransitionTo(self $next): bool
    {
        if ($this === $next) {
            return true;
        }

        return in_array($next, match ($this) {
            self::Submitted => [self::TriagePending, self::ClarificationNeeded, self::Confirmed, self::Rejected, self::Withdrawn, self::Merged],
            self::TriagePending => [self::ClarificationNeeded, self::Confirmed, self::Rejected, self::Withdrawn, self::Merged],
            self::ClarificationNeeded => [self::TriagePending, self::Confirmed, self::Rejected, self::Withdrawn, self::Merged],
            self::Confirmed => [self::Assigned, self::InProgress, self::WaitingForExternalSource, self::Resolved, self::Rejected, self::Merged],
            self::Assigned => [self::InProgress, self::WaitingForRequester, self::WaitingForExternalSource, self::Resolved, self::Merged],
            self::InProgress => [self::WaitingForRequester, self::WaitingForExternalSource, self::Resolved, self::Merged],
            self::WaitingForExternalSource => [self::InProgress, self::Resolved, self::Merged],
            self::WaitingForRequester => [self::TriagePending, self::InProgress, self::Resolved, self::Rejected, self::Merged],
            self::Resolved => [self::ResolutionVerified, self::Closed, self::Reopened],
            self::ResolutionVerified => [self::Closed, self::Reopened],
            self::Closed => [self::Reopened],
            self::Reopened => [self::ClarificationNeeded, self::Confirmed, self::Assigned, self::InProgress, self::Resolved, self::Merged],
            self::Rejected, self::Merged, self::Withdrawn => [],
        }, true);
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $status): string => $status->value, self::cases());
    }
}
