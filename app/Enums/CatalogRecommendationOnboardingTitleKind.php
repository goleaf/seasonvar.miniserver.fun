<?php

declare(strict_types=1);

namespace App\Enums;

enum CatalogRecommendationOnboardingTitleKind: string
{
    case Liked = 'liked';
    case Excluded = 'excluded';
}
