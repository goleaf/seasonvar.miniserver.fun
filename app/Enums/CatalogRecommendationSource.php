<?php

declare(strict_types=1);

namespace App\Enums;

enum CatalogRecommendationSource: string
{
    case UserHistory = 'user_history';
    case UserWatchlist = 'user_watchlist';
    case UserStatuses = 'user_statuses';
    case UserCollections = 'user_collections';
    case UserTags = 'user_tags';
    case UserRatings = 'user_ratings';
    case ContentSimilarity = 'content_similarity';
    case Editorial = 'editorial';
    case Popularity = 'popularity';
    case Trending = 'trending';
    case Rating = 'rating';
    case CatalogPublication = 'catalog_publication';
    case ContentUpdate = 'content_update';
    case ReleaseCalendar = 'release_calendar';
    case Random = 'random';
    case ImportedProvider = 'imported_provider';
}
