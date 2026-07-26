<?php

declare(strict_types=1);

namespace App\Enums;

enum CatalogRecommendationFeedbackReason: string
{
    case WatchedElsewhere = 'watched_elsewhere';
    case DislikeGenre = 'dislike_genre';
    case DislikeCountry = 'dislike_country';
    case DislikeActor = 'dislike_actor';
    case TooManyEpisodes = 'too_many_episodes';
    case Unfinished = 'unfinished';
    case TooOld = 'too_old';
    case LowRating = 'low_rating';
    case WrongMood = 'wrong_mood';
    case NotThisTitle = 'not_this_title';
    case NotSimilar = 'not_similar';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    public function requiresSubject(): bool
    {
        return $this->subjectType() !== null;
    }

    public function subjectType(): ?string
    {
        return match ($this) {
            self::DislikeGenre => 'genre',
            self::DislikeCountry => 'country',
            self::DislikeActor => 'actor',
            default => null,
        };
    }

    public function feedback(): CatalogRecommendationFeedback
    {
        return $this === self::NotThisTitle
            ? CatalogRecommendationFeedback::Blacklisted
            : CatalogRecommendationFeedback::NotInterested;
    }
}
