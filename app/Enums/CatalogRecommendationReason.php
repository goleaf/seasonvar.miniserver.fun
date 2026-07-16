<?php

declare(strict_types=1);

namespace App\Enums;

enum CatalogRecommendationReason: string
{
    case BecauseHistory = 'because_history';
    case BecauseWatchlist = 'because_watchlist';
    case BecauseStatus = 'because_status';
    case BecauseCollection = 'because_collection';
    case BecausePersonalTags = 'because_personal_tags';
    case BecauseRating = 'because_rating';
    case SimilarGenres = 'similar_genres';
    case SimilarTags = 'similar_tags';
    case SharedActor = 'shared_actor';
    case SharedDirector = 'shared_director';
    case SharedStudio = 'shared_studio';
    case SharedTranslation = 'shared_translation';
    case RelatedStory = 'related_story';
    case Editorial = 'editorial';
    case Trending = 'trending';
    case Popular = 'popular';
    case TopRated = 'top_rated';
    case RecentlyAdded = 'recently_added';
    case RecentlyUpdated = 'recently_updated';
    case Upcoming = 'upcoming';
    case Random = 'random';
}
