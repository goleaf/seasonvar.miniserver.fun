<?php

declare(strict_types=1);

namespace App\Enums;

enum ContentRequestExternalProvider: string
{
    case Imdb = 'imdb';
    case Tmdb = 'tmdb';
    case Tvdb = 'tvdb';
    case Kinopoisk = 'kinopoisk';
    case Seasonvar = 'seasonvar';

    public function label(): string
    {
        return __('requests.providers.'.$this->value);
    }

    public function publicUrl(string $identifier): ?string
    {
        return match ($this) {
            self::Imdb => 'https://www.imdb.com/title/'.rawurlencode($identifier).'/',
            self::Tmdb => 'https://www.themoviedb.org/tv/'.rawurlencode($identifier),
            self::Tvdb => 'https://thetvdb.com/search?query='.rawurlencode($identifier),
            self::Kinopoisk => 'https://www.kinopoisk.ru/film/'.rawurlencode($identifier).'/',
            self::Seasonvar => null,
        };
    }
}
