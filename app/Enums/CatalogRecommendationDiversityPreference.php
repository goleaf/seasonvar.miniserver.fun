<?php

declare(strict_types=1);

namespace App\Enums;

enum CatalogRecommendationDiversityPreference: string
{
    case Focused = 'focused';
    case Balanced = 'balanced';
    case Varied = 'varied';
}
