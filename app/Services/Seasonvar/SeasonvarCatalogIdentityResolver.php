<?php

namespace App\Services\Seasonvar;

use App\DTOs\Seasonvar\SeasonvarCatalogData;
use App\Models\CatalogTitle;
use App\Models\Season;
use App\Models\SourcePage;

final class SeasonvarCatalogIdentityResolver
{
    public function resolve(
        SourcePage $page,
        SeasonvarCatalogData $data,
        string $sourceUrlHash,
        ?CatalogTitle $preferredCatalogTitle = null,
    ): ?CatalogTitle {
        $byKnownSeasonUrl = $this->catalogTitleByKnownSeasonUrl($page, $sourceUrlHash, $preferredCatalogTitle);

        if ($byKnownSeasonUrl !== null) {
            return $byKnownSeasonUrl;
        }

        if ($data->externalId !== null) {
            $byProviderId = CatalogTitle::withTrashed()
                ->where('source_id', $page->source_id)
                ->where('external_id', $data->externalId)
                ->first();

            if ($byProviderId !== null) {
                return $byProviderId;
            }
        }

        return CatalogTitle::withTrashed()
            ->where('source_id', $page->source_id)
            ->where(function ($query) use ($page, $sourceUrlHash): void {
                $query->where('source_url_hash', $sourceUrlHash)
                    ->orWhere('source_page_id', $page->id);
            })
            ->first();
    }

    private function catalogTitleByKnownSeasonUrl(
        SourcePage $page,
        string $sourceUrlHash,
        ?CatalogTitle $preferredCatalogTitle,
    ): ?CatalogTitle {
        if ($preferredCatalogTitle !== null && $this->catalogTitleHasSeasonUrl($preferredCatalogTitle, $sourceUrlHash)) {
            return $preferredCatalogTitle;
        }

        $titleIds = Season::withTrashed()
            ->select('seasons.catalog_title_id')
            ->join('catalog_titles', 'catalog_titles.id', '=', 'seasons.catalog_title_id')
            ->where('seasons.source_url_hash', $sourceUrlHash)
            ->where('catalog_titles.source_id', $page->source_id)
            ->whereNull('catalog_titles.deleted_at')
            ->distinct()
            ->pluck('seasons.catalog_title_id');

        if ($titleIds->count() === 1) {
            return CatalogTitle::query()->whereKey((int) $titleIds->first())->first();
        }

        return $this->catalogTitleByLargestKnownSeasonFamily($titleIds->all());
    }

    /**
     * Existing imports may already contain duplicate title rows with the same detected season URLs
     * but different provider IDs. Prefer the single most complete season family, and keep the old
     * external-id fallback when that choice is ambiguous.
     *
     * @param  list<int>  $titleIds
     */
    private function catalogTitleByLargestKnownSeasonFamily(array $titleIds): ?CatalogTitle
    {
        $titles = CatalogTitle::query()
            ->whereKey($titleIds)
            ->withCount('seasons')
            ->orderByDesc('seasons_count')
            ->orderBy('id')
            ->get();

        $largestSeasonCount = (int) ($titles->first()?->getAttribute('seasons_count') ?? 0);

        if ($largestSeasonCount === 0) {
            return null;
        }

        if ($titles->filter(fn (CatalogTitle $title): bool => (int) $title->getAttribute('seasons_count') === $largestSeasonCount)->count() !== 1) {
            return null;
        }

        return $titles->first();
    }

    private function catalogTitleHasSeasonUrl(CatalogTitle $catalogTitle, string $sourceUrlHash): bool
    {
        return Season::withTrashed()
            ->where('catalog_title_id', $catalogTitle->id)
            ->where('source_url_hash', $sourceUrlHash)
            ->exists();
    }
}
