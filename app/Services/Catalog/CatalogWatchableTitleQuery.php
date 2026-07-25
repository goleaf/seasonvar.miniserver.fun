<?php

declare(strict_types=1);

namespace App\Services\Catalog;

use App\Models\CatalogTitle;
use App\Models\LicensedMedia;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

final class CatalogWatchableTitleQuery
{
    public function __construct(private readonly CatalogTitleQuery $titles) {}

    /** @return Builder<CatalogTitle> */
    public function visibleTo(?User $user): Builder
    {
        return $this->titles
            ->visibleTo($user)
            ->whereExists($this->mediaForTitle($user)->selectRaw('1')->toBase());
    }

    /** @return Builder<LicensedMedia> */
    public function mediaForTitle(?User $user): Builder
    {
        return LicensedMedia::query()
            ->whereColumn('licensed_media.catalog_title_id', 'catalog_titles.id')
            ->published()
            ->forAvailableReleases($user)
            ->withoutKnownFailures()
            ->withPlaybackLocation();
    }
}
