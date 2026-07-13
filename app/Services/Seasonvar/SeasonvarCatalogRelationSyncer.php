<?php

namespace App\Services\Seasonvar;

use App\Models\CatalogTitle;
use App\Services\Catalog\CatalogRelationSyncer;
use Illuminate\Support\Str;
use InvalidArgumentException;

class SeasonvarCatalogRelationSyncer
{
    public function __construct(
        private readonly CatalogRelationSyncer $relations,
        private readonly SeasonvarUrl $seasonvarUrl,
    ) {}

    /**
     * @param  list<array{type: string, name: string, source_id?: int|null, source_external_id?: string|int|null, source_url?: string|null}>  $taxonomies
     * @param  (callable(string, array<string, mixed>): void)|null  $progress
     * @return array<string, array{ids: list<int>, count: int, attached_ids: list<int>, attached_count: int}>
     */
    public function sync(CatalogTitle $title, array $taxonomies, ?callable $progress = null): array
    {
        $taxonomies = collect($taxonomies)
            ->map(function (array $item): array {
                $item['source_url'] = $this->safeSourceUrl($item['source_url'] ?? null);

                return $item;
            })
            ->values()
            ->all();

        return $this->relations->sync($title, $taxonomies, $progress);
    }

    private function safeSourceUrl(?string $sourceUrl): ?string
    {
        if ($sourceUrl === null || Str::length($sourceUrl) > 255) {
            return null;
        }

        try {
            $sourceUrl = $this->seasonvarUrl->normalize($sourceUrl);
        } catch (InvalidArgumentException) {
            return null;
        }

        $parts = parse_url($sourceUrl);

        if (! is_array($parts) || Str::lower((string) ($parts['scheme'] ?? '')) !== 'https') {
            return null;
        }

        return in_array(Str::lower((string) ($parts['host'] ?? '')), ['seasonvar.ru', 'www.seasonvar.ru'], true)
            ? $sourceUrl
            : null;
    }
}
