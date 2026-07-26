<?php

declare(strict_types=1);

namespace App\Enums;

use App\DTOs\CatalogSmartCollectionRules;

enum CatalogSmartCollectionPreset: string
{
    case NewKoreanThrillers = 'new_korean_thrillers';
    case ShortCompletedComedies = 'short_completed_comedies';
    case LibraryNewEpisodes = 'library_new_episodes';
    case UnwatchedFavoriteActor = 'unwatched_favorite_actor';
    case DroppedThreeMonths = 'dropped_three_months';
    case LibraryAvailableVideo = 'library_available_video';

    public function rules(): CatalogSmartCollectionRules
    {
        return CatalogSmartCollectionRules::fromInput(match ($this) {
            self::NewKoreanThrillers => [
                'country_slug' => 'iuznaia-koreia',
                'genre_slug' => 'trillery',
                'imdb_min' => 8,
                'year_from' => now()->year - 1,
            ],
            self::ShortCompletedComedies => [
                'genre_slug' => 'komediia',
                'completion' => CatalogSmartCollectionCompletion::Completed->value,
                'episodes_max' => 8,
                'max_episode_minutes' => 60,
            ],
            self::LibraryNewEpisodes => [
                'in_library' => true,
                'has_new_episodes' => true,
            ],
            self::UnwatchedFavoriteActor => [
                'unwatched' => true,
            ],
            self::DroppedThreeMonths => [
                'in_library' => true,
                'watch_status' => CatalogWatchStatus::Dropped->value,
                'watch_status_older_days' => 90,
            ],
            self::LibraryAvailableVideo => [
                'in_library' => true,
                'video_available' => true,
            ],
        });
    }

    public function label(): string
    {
        return __("collections.smart.presets.{$this->value}.label");
    }

    public function description(): string
    {
        return __("collections.smart.presets.{$this->value}.description");
    }
}
