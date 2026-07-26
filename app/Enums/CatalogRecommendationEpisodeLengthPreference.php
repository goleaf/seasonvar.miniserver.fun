<?php

declare(strict_types=1);

namespace App\Enums;

enum CatalogRecommendationEpisodeLengthPreference: string
{
    case Any = 'any';
    case Short = 'short';
    case Long = 'long';
}
