<?php

declare(strict_types=1);

namespace App\Enums;

enum CatalogRecommendationFeedback: string
{
    case MoreLikeThis = 'more_like_this';
    case NotInterested = 'not_interested';
    case Blacklisted = 'blacklisted';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /** @return list<self> */
    public static function negativeCases(): array
    {
        return [
            self::NotInterested,
            self::Blacklisted,
        ];
    }

    /** @return list<string> */
    public static function negativeValues(): array
    {
        return array_column(self::negativeCases(), 'value');
    }

    public function isNegative(): bool
    {
        return in_array($this, self::negativeCases(), true);
    }
}
