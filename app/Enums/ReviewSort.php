<?php

declare(strict_types=1);

namespace App\Enums;

enum ReviewSort: string
{
    case Newest = 'newest';
    case Oldest = 'oldest';
    case MostHelpful = 'most_helpful';
    case HighestRated = 'highest_rated';
    case LowestRated = 'lowest_rated';

    public function label(): string
    {
        return __('reviews.sort.'.$this->value);
    }
}
