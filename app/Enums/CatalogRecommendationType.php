<?php

declare(strict_types=1);

namespace App\Enums;

enum CatalogRecommendationType: string
{
    case Personalized = 'personalized';
    case Similar = 'similar';
    case Related = 'related';
    case Editorial = 'editorial';
    case Trending = 'trending';
    case Popular = 'popular';
    case TopRated = 'top_rated';
    case RecentlyAdded = 'recently_added';
    case RecentlyUpdated = 'recently_updated';
    case Upcoming = 'upcoming';
    case Random = 'random';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /** @return list<self> */
    public static function publicCases(): array
    {
        return [
            self::Trending,
            self::Popular,
            self::TopRated,
            self::RecentlyAdded,
            self::RecentlyUpdated,
            self::Upcoming,
            self::Editorial,
            self::Random,
        ];
    }

    public function isPersonal(): bool
    {
        return $this === self::Personalized;
    }

    public function isIndexable(): bool
    {
        return in_array($this, [
            self::Trending,
            self::Popular,
            self::TopRated,
            self::RecentlyAdded,
            self::RecentlyUpdated,
        ], true);
    }
}
