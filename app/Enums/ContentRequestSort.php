<?php

declare(strict_types=1);

namespace App\Enums;

enum ContentRequestSort: string
{
    case MostVoted = 'most_voted';
    case Newest = 'newest';
    case Oldest = 'oldest';
    case RecentlyUpdated = 'recently_updated';
    case Title = 'title';

    public function label(): string
    {
        return __('requests.sorts.'.$this->value);
    }
}
