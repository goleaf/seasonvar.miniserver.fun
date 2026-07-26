<?php

declare(strict_types=1);

namespace App\Enums;

enum CatalogRecommendationFreshnessPreference: string
{
    case Newer = 'newer';
    case Balanced = 'balanced';
    case Proven = 'proven';
}
