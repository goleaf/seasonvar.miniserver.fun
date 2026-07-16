<?php

declare(strict_types=1);

namespace App\Enums;

enum UserProfileReportStatus: string
{
    case Open = 'open';
    case Resolved = 'resolved';
    case Dismissed = 'dismissed';
}
