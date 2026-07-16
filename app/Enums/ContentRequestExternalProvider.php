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
}
