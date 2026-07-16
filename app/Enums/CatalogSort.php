<?php

namespace App\Enums;

enum CatalogSort: string
{
    case Relevance = 'relevance';
    case Updated = 'updated';
    case YearDesc = 'year_desc';
    case YearAsc = 'year_asc';
    case EpisodesDesc = 'episodes_desc';
    case SeasonsDesc = 'seasons_desc';
    case VideoDesc = 'with_video';
    case TitleAsc = 'title_asc';
    case TitleDesc = 'title_desc';
    case KinopoiskRating = 'kinopoisk_desc';
    case ImdbRating = 'imdb_desc';
    case Popularity = 'popularity_desc';

    public function label(): string
    {
        return (string) __("catalog.catalog.sorts.{$this->value}");
    }

    public function icon(): string
    {
        return match ($this) {
            self::Relevance => 'fa-solid fa-arrow-down-wide-short',
            self::Updated => 'fa-solid fa-clock-rotate-left',
            self::YearDesc => 'fa-solid fa-calendar-days',
            self::YearAsc => 'fa-regular fa-calendar',
            self::EpisodesDesc => 'fa-solid fa-list-ol',
            self::SeasonsDesc => 'fa-solid fa-layer-group',
            self::VideoDesc => 'fa-solid fa-file-video',
            self::TitleAsc => 'fa-solid fa-arrow-down-a-z',
            self::TitleDesc => 'fa-solid fa-arrow-up-z-a',
            self::KinopoiskRating, self::ImdbRating => 'fa-solid fa-star',
            self::Popularity => 'fa-solid fa-fire',
        };
    }
}
