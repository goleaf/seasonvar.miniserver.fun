<?php

declare(strict_types=1);

namespace App\Enums;

enum ReleaseCalendarFeedScope: string
{
    case All = 'all';
    case Collection = 'collection';
    case NewEpisodes = 'new_episodes';
    case SeasonPremieres = 'season_premieres';
    case Title = 'title';
    case Translation = 'translation';
    case Subtitles = 'subtitles';

    public function label(): string
    {
        return __('calendar.feeds.scopes.'.$this->value);
    }

    public function usesPersonalCalendar(): bool
    {
        return in_array($this, [
            self::All,
            self::NewEpisodes,
            self::SeasonPremieres,
            self::Translation,
            self::Subtitles,
        ], true);
    }
}
