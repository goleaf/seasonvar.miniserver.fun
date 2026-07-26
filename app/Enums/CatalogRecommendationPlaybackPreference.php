<?php

declare(strict_types=1);

namespace App\Enums;

enum CatalogRecommendationPlaybackPreference: string
{
    case Any = 'any';
    case Dubbed = 'dubbed';
    case Subtitles = 'subtitles';
}
