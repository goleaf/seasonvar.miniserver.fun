<?php

declare(strict_types=1);

namespace App\Support\Cache;

enum CacheDomain: string
{
    case Homepage = 'homepage';
    case CatalogPages = 'catalog-pages';
    case CatalogFacets = 'catalog-facets';
    case CatalogStats = 'catalog-stats';
    case TitleDetail = 'title-detail';
    case Recommendations = 'recommendations';
    case SearchSuggestions = 'search-suggestions';
    case Tags = 'tags';
    case Collections = 'collections';
    case ContentRequests = 'content-requests';
    case ReleaseCalendar = 'release-calendar';
    case HelpCenter = 'help-center';
    case Sitemap = 'sitemap';
    case Api = 'api';
    case Operational = 'operational';
}
