<?php

declare(strict_types=1);

namespace App\Enums;

enum CatalogRecommendationCompletionPreference: string
{
    case Any = 'any';
    case Completed = 'completed';
    case Ongoing = 'ongoing';
}
