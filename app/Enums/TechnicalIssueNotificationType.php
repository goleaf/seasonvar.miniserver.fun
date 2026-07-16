<?php

declare(strict_types=1);

namespace App\Enums;

enum TechnicalIssueNotificationType: string
{
    case Submitted = 'submitted';
    case Clarification = 'clarification';
    case SupportReply = 'support_reply';
    case StatusChanged = 'status_changed';
    case Resolved = 'resolved';
    case ResolutionVerified = 'resolution_verified';
    case Closed = 'closed';
    case Reopened = 'reopened';
    case Rejected = 'rejected';
    case Merged = 'merged';
    case Assigned = 'assigned';
}
