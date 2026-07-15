<?php

declare(strict_types=1);

namespace App\Enums;

enum CatalogCollectionSort: string
{
    case Manual = 'manual';
    case RecentlyAdded = 'added_desc';
    case OldestAdded = 'added_asc';
    case Title = 'title_asc';
    case ReleaseYear = 'year_desc';
    case Rating = 'rating_desc';
    case RecentlyUpdated = 'updated_desc';

    public function label(): string
    {
        return __("collections.sort.{$this->value}");
    }
}
