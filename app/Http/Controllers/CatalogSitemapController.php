<?php

namespace App\Http\Controllers;

use App\Services\Catalog\CatalogSitemapResponder;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CatalogSitemapController extends Controller
{
    public function sitemap(CatalogSitemapResponder $sitemaps): StreamedResponse
    {
        return $sitemaps->index();
    }

    public function sitemapIndex(CatalogSitemapResponder $sitemaps): StreamedResponse
    {
        return $sitemaps->index();
    }

    public function sitemapStatic(CatalogSitemapResponder $sitemaps): StreamedResponse
    {
        return $sitemaps->staticPages();
    }

    public function sitemapTaxonomies(CatalogSitemapResponder $sitemaps): StreamedResponse
    {
        return $sitemaps->taxonomies();
    }

    public function sitemapLandings(CatalogSitemapResponder $sitemaps): StreamedResponse
    {
        return $sitemaps->landings();
    }

    public function sitemapCollections(CatalogSitemapResponder $sitemaps): StreamedResponse
    {
        return $sitemaps->collections();
    }

    public function sitemapTitles(CatalogSitemapResponder $sitemaps, int $page): StreamedResponse
    {
        return $sitemaps->titles($page);
    }

    public function sitemapVideos(CatalogSitemapResponder $sitemaps, int $page): StreamedResponse
    {
        return $sitemaps->videos($page);
    }

    public function sitemapRequests(CatalogSitemapResponder $sitemaps, int $page): StreamedResponse
    {
        return $sitemaps->requests($page);
    }

    public function feed(CatalogSitemapResponder $sitemaps): StreamedResponse
    {
        return $sitemaps->feed();
    }

    public function openSearch(CatalogSitemapResponder $sitemaps): StreamedResponse
    {
        return $sitemaps->openSearch();
    }

    public function llms(CatalogSitemapResponder $sitemaps): StreamedResponse
    {
        return $sitemaps->llms();
    }
}
