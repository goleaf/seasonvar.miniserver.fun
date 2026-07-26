<?php

declare(strict_types=1);

namespace App\Enums;

enum CatalogMetadataField: string
{
    case Title = 'title';
    case OriginalTitle = 'original_title';
    case Type = 'type';
    case Year = 'year';
    case Description = 'description';
    case PosterUrl = 'poster_url';
    case Genres = 'genres';
    case Countries = 'countries';

    public function isTaxonomy(): bool
    {
        return $this === self::Genres || $this === self::Countries;
    }
}
