<?php

namespace App\Services\Seasonvar;

use App\DTOs\Seasonvar\SeasonvarCatalogData;
use App\Models\CatalogTitle;
use App\Models\SourcePage;

final class SeasonvarCatalogIdentityResolver
{
    public function resolve(SourcePage $page, SeasonvarCatalogData $data, string $sourceUrlHash): ?CatalogTitle
    {
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
}
