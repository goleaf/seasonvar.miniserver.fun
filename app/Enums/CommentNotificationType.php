<?php

declare(strict_types=1);

namespace App\Enums;

enum CommentNotificationType: string
{
    case Reply = 'reply';
    case Reaction = 'reaction';
    case Moderation = 'moderation';
    case ReportResolved = 'report_resolved';
}
