<?php

declare(strict_types=1);

namespace App\Services\Seasonvar;

use App\DTOs\Seasonvar\SeasonvarCatalogData;
use App\Models\CatalogTitle;
use App\Models\SourcePage;
use App\Services\Catalog\Quality\CatalogMetadataProvenanceRecorder;

final readonly class SeasonvarCatalogMetadataProvenance
{
    public function __construct(
        private CatalogMetadataProvenanceRecorder $recorder,
    ) {}

    public function record(
        CatalogTitle $title,
        SourcePage $page,
        SeasonvarCatalogData $data,
    ): void {
        $taxonomies = collect($data->taxonomies)
            ->groupBy(static fn (array $taxonomy): string => (string) ($taxonomy['type'] ?? ''));

        $this->recorder->recordProviderSnapshot(
            $title,
            $page,
            [
                'title' => $data->title,
                'original_title' => $data->originalTitle,
                'type' => $data->type,
                'year' => $data->year,
                'description' => $data->description,
                'poster_url' => $data->posterUrl,
                'genres' => $taxonomies->get('genre', collect())
                    ->pluck('name')
                    ->filter(static fn (mixed $name): bool => is_string($name))
                    ->values()
                    ->all(),
                'countries' => $taxonomies->get('country', collect())
                    ->pluck('name')
                    ->filter(static fn (mixed $name): bool => is_string($name))
                    ->values()
                    ->all(),
            ],
            completeTaxonomySnapshot: $data->hasCompleteMetadataSnapshot(),
        );
    }
}
