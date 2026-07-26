<?php

declare(strict_types=1);

namespace App\Enums;

enum CatalogQualityIssueCategory: string
{
    case CriticalErrors = 'critical_errors';
    case SuspiciousTags = 'suspicious_tags';
    case DataConflicts = 'data_conflicts';
    case MissingPoster = 'missing_poster';
    case MissingVideo = 'missing_video';
    case SuspiciousEpisodes = 'suspicious_episodes';
    case Stale = 'stale';
}
