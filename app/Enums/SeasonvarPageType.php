<?php

declare(strict_types=1);

namespace App\Enums;

enum SeasonvarPageType: string
{
    case Serial = 'serial';
    case Actor = 'actor';
    case Director = 'director';
    case Genre = 'genre';
    case Country = 'country';
    case Tag = 'tag';
    case Translation = 'translation';
    case Status = 'status';
    case Network = 'network';
    case Studio = 'studio';
    case StaticPage = 'static';
    case Rss = 'rss';
    case Search = 'search';
    case Sitemap = 'sitemap';
    case Unknown = 'unknown';

    public function label(): string
    {
        return match ($this) {
            self::Serial => 'сериал',
            self::Actor => 'актёр',
            self::Director => 'режиссёр',
            self::Genre => 'жанр',
            self::Country => 'страна',
            self::Tag => 'тег',
            self::Translation => 'перевод',
            self::Status => 'статус производства',
            self::Network => 'телесеть',
            self::Studio => 'студия',
            self::StaticPage => 'статическая страница',
            self::Rss => 'RSS',
            self::Search => 'поиск',
            self::Sitemap => 'карта сайта',
            self::Unknown => 'неизвестный тип',
        };
    }
}
