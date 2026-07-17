<?php

declare(strict_types=1);

namespace App\Enums;

enum ContentRequestStatus: string
{
    case Submitted = 'submitted';
    case PendingReview = 'pending_review';
    case ClarificationNeeded = 'clarification_needed';
    case Approved = 'approved';
    case Planned = 'planned';
    case InProgress = 'in_progress';
    case PartiallyCompleted = 'partially_completed';
    case Completed = 'completed';
    case Rejected = 'rejected';
    case Duplicate = 'duplicate';
    case Merged = 'merged';
    case Cancelled = 'cancelled';
    case Withdrawn = 'withdrawn';

    public function label(): string
    {
        return __('requests.statuses.'.$this->value.'.label');
    }

    public function description(): string
    {
        return __('requests.statuses.'.$this->value.'.description');
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::Completed, self::Rejected, self::Duplicate, self::Merged, self::Cancelled, self::Withdrawn], true);
    }

    public function isOpen(): bool
    {
        return ! $this->isTerminal();
    }

    public function canEngage(): bool
    {
        return $this->isOpen();
    }

    public function canRequesterEdit(): bool
    {
        return in_array($this, [self::Submitted, self::PendingReview, self::ClarificationNeeded], true);
    }

    public function requiresDedicatedAction(): bool
    {
        return in_array($this, [
            self::ClarificationNeeded,
            self::Duplicate,
            self::Merged,
            self::Withdrawn,
        ], true);
    }

    /** @return list<self> */
    public function transitions(): array
    {
        return match ($this) {
            self::Submitted => [self::PendingReview, self::ClarificationNeeded, self::Approved, self::Rejected, self::Merged, self::Withdrawn],
            self::PendingReview => [self::ClarificationNeeded, self::Approved, self::Rejected, self::Merged, self::Withdrawn],
            self::ClarificationNeeded => [self::PendingReview, self::Rejected, self::Merged, self::Withdrawn],
            self::Approved => [self::Planned, self::InProgress, self::Rejected, self::Merged, self::Cancelled],
            self::Planned => [self::InProgress, self::PartiallyCompleted, self::Completed, self::Merged, self::Cancelled],
            self::InProgress => [self::PartiallyCompleted, self::Completed, self::Merged, self::Cancelled],
            self::PartiallyCompleted => [self::InProgress, self::Completed, self::Merged, self::Cancelled],
            default => [],
        };
    }
}
