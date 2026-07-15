<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Http\Resources\CatalogTaxonomyResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class CatalogHomeResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $stats = (array) data_get($this->resource, 'stats', []);
        $subtitleTag = data_get($this->resource, 'subtitleTag');

        return [
            'stats' => [
                'titles' => (int) ($stats['titles'] ?? 0),
                'episodes' => (int) ($stats['episodes'] ?? 0),
                'videos' => (int) ($stats['videos'] ?? 0),
                'genres' => (int) ($stats['genres'] ?? 0),
                'countries' => (int) ($stats['countries'] ?? 0),
            ],
            'latest_titles' => TitleCardResource::collection(data_get($this->resource, 'latestTitles', collect())),
            'featured_titles' => TitleCardResource::collection(data_get($this->resource, 'featuredTitles', collect())),
            'titles_with_video' => TitleCardResource::collection(data_get($this->resource, 'videoTitles', collect())),
            'latest_releases' => LatestReleaseResource::collection(data_get($this->resource, 'latestMedia', collect())),
            'year_buckets' => $this->yearBuckets(data_get($this->resource, 'yearBuckets')),
            'genres' => CatalogTaxonomyResource::collection(data_get($this->resource, 'genres', collect())),
            'countries' => CatalogTaxonomyResource::collection(data_get($this->resource, 'countries', collect())),
            'subtitle_tag' => $subtitleTag === null ? null : [
                'id' => (int) $subtitleTag->id,
                'name' => (string) $subtitleTag->name,
                'slug' => (string) $subtitleTag->slug,
                'titles_count' => (int) $subtitleTag->catalog_titles_count,
            ],
        ];
    }

    /** @return list<array{year: int, titles_count: int}> */
    private function yearBuckets(mixed $buckets): array
    {
        if (! is_iterable($buckets)) {
            return [];
        }

        $rows = [];

        foreach ($buckets as $bucket) {
            if (! is_object($bucket)) {
                continue;
            }

            $rows[] = [
                'year' => (int) data_get($bucket, 'year', 0),
                'titles_count' => (int) data_get($bucket, 'titles_count', 0),
            ];
        }

        return $rows;
    }
}
