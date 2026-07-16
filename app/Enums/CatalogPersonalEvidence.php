<?php

declare(strict_types=1);

namespace App\Enums;

enum CatalogPersonalEvidence: string
{
    case MeaningfulProgress = 'meaningful_progress';
    case CompletedDepth = 'completed_depth';
    case Watchlist = 'watchlist';
    case PlannedStatus = 'planned_status';
    case WatchingStatus = 'watching_status';
    case CompletedStatus = 'completed_status';
    case Rating = 'rating';
    case Collection = 'collection';
    case PersonalTag = 'personal_tag';
}
