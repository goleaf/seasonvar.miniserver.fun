<?php

declare(strict_types=1);

namespace App\Enums;

enum ReviewReportStatus: string
{
    case Open = 'open';
    case Reviewed = 'reviewed';
    case Resolved = 'resolved';
    case Dismissed = 'dismissed';

    public function label(): string
    {
        return __('reviews.reports.statuses.'.$this->value);
    }
}
