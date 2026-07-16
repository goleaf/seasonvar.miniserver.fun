<?php

declare(strict_types=1);

namespace App\Enums;

enum ContentRequestNotificationType: string
{
    case Submitted = 'submitted';
    case StatusChanged = 'status_changed';
    case Clarification = 'clarification';
    case PartialCompletion = 'partial_completion';
    case Completed = 'completed';
    case Merged = 'merged';
}
