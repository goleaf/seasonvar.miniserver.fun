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
    case NewForYou = 'new_for_you';
    case SimilarGenres = 'similar_genres';
    case SimilarTheme = 'similar_theme';
    case SimilarTags = 'similar_tags';
    case SharedActor = 'shared_actor';
    case SharedDirector = 'shared_director';
    case SharedNetwork = 'shared_network';
    case SharedStudio = 'shared_studio';
    case SharedTranslation = 'shared_translation';
    case SameCountry = 'same_country';
    case SameStatus = 'same_status';
    case SimilarAgeRating = 'similar_age_rating';
    case NearbyYear = 'nearby_year';
    case ImportedRelation = 'imported_relation';
    case SharedEditorialCollection = 'shared_editorial_collection';
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
