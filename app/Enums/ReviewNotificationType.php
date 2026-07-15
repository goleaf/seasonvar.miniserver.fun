<?php

declare(strict_types=1);

namespace App\Enums;

enum ReviewNotificationType: string
{
    case Helpful = 'helpful';
    case Moderation = 'moderation';
    case ReportResolved = 'report_resolved';
}
