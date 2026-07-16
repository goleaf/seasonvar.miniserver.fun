<?php

declare(strict_types=1);

namespace App\Enums;

enum TechnicalIssueSort: string
{
    case RecentlyUpdated = 'recently_updated';
    case Newest = 'newest';
    case Oldest = 'oldest';
    case Priority = 'priority';
    case Severity = 'severity';

    public function label(): string
    {
        return __("issues.sort.{$this->value}");
    }
}
