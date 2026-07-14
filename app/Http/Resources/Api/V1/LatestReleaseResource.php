<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Models\LicensedMedia;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin LicensedMedia */
final class LatestReleaseResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $catalogTitle = $this->catalogTitle;
        $season = $this->season;
        $episode = $this->episode;

        return [
            'id' => (int) $this->id,
            'title' => $this->title,
            'quality' => $this->quality,
            'translation' => $this->translation_name,
            'format' => $this->format,
            'published_at' => $this->published_at?->toJSON(),
            'catalog_title' => [
                'id' => (int) $catalogTitle->id,
                'slug' => (string) $catalogTitle->slug,
                'title' => $catalogTitle->display_title,
                'original_title' => $catalogTitle->display_original_title,
                'type' => (string) $catalogTitle->type,
                'year' => $catalogTitle->year === null ? null : (int) $catalogTitle->year,
                'poster_url' => $catalogTitle->poster_url,
                'counts' => [
                    'seasons' => (int) $catalogTitle->seasons_count,
                    'episodes' => (int) $catalogTitle->episodes_count,
                ],
            ],
            'season' => $season === null ? null : [
                'id' => (int) $season->id,
                'number' => $season->number,
                'kind' => $season->kind->value,
                'title' => $season->title,
            ],
            'episode' => $episode === null ? null : [
                'id' => (int) $episode->id,
                'number' => $episode->number,
                'kind' => $episode->kind->value,
                'title' => $episode->title,
                'released_at' => $episode->released_at?->toDateString(),
            ],
        ];
    }
}
