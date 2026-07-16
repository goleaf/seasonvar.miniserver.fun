<?php

namespace App\Enums;

enum CatalogPublicationType: string
{
    case Serial = 'serial';
    case Show = 'show';
    case Anime = 'anime';
    case Documentary = 'documentary';
    case Unknown = 'unknown';

    public function label(): string
    {
        return (string) __("catalog.catalog.publication_types.{$this->value}");
    }

    /** @return list<string> */
    public function databaseValues(): array
    {
        return match ($this) {
            self::Serial => ['serial', 'series'],
            self::Show => ['show', 'tv_show'],
            self::Anime => ['anime'],
            self::Documentary => ['documentary'],
            self::Unknown => ['unknown'],
        };
    }
}
