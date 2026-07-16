<?php

declare(strict_types=1);

namespace App\Enums;

enum CatalogRecommendationFeedback: string
{
    case NotInterested = 'not_interested';
    case Blacklisted = 'blacklisted';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
