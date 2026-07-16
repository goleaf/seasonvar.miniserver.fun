<?php

declare(strict_types=1);

namespace App\Enums;

enum CatalogDiscoverySort: string
{
    case Relevance = 'relevance';
    case Trending = 'trending';
    case Popularity = 'popularity';
    case Rating = 'rating';
    case Newest = 'newest';
    case LatestUpdate = 'latest_update';
    case Title = 'title';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
