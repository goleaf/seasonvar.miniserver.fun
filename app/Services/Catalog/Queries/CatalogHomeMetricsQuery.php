<?php

declare(strict_types=1);

namespace App\Services\Catalog\Queries;

use App\Models\Episode;
use App\Models\LicensedMedia;
use App\Models\Season;
use App\Services\Catalog\CatalogTitleQuery;

final readonly class CatalogHomeMetricsQuery
{
    public function __construct(private CatalogTitleQuery $titles) {}

    /** @return array{titles: int, episodes: int, videos: int} */
    public function handle(): array
    {
        return [
            'titles' => $this->titles->visibleTo(null)->count(),
            'episodes' => Episode::query()
                ->published()
                ->whereIn('season_id', Season::query()
                    ->published()
                    ->select('id')
                    ->whereIn('catalog_title_id', $this->titles->visibleTo(null)->select('id')))
                ->count(),
            'videos' => LicensedMedia::query()
                ->published()
                ->forAvailableReleases(null)
                ->whereIn('catalog_title_id', $this->titles->visibleTo(null)->select('id'))
                ->count(),
        ];
    }
}
