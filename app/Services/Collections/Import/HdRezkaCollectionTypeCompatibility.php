<?php

declare(strict_types=1);

namespace App\Services\Collections\Import;

use App\Enums\CatalogCollectionSourceScope;
use App\Services\Catalog\Search\CatalogSearchNormalizer;

final readonly class HdRezkaCollectionTypeCompatibility
{
    public function __construct(private CatalogSearchNormalizer $normalizer) {}

    public function sourceScope(?string $sourceType): CatalogCollectionSourceScope
    {
        return match ($this->canonicalType($sourceType)) {
            'series', 'show', 'anime', 'documentary' => CatalogCollectionSourceScope::Supported,
            'film', 'cartoon' => CatalogCollectionSourceScope::Unsupported,
            default => CatalogCollectionSourceScope::Unknown,
        };
    }

    public function compatible(?string $sourceType, string $catalogType): bool
    {
        $sourceType = $this->canonicalType($sourceType);
        $catalogType = $this->canonicalType($catalogType);

        return $sourceType === null
            || $catalogType === null
            || $sourceType === $catalogType;
    }

    public function knownMatch(?string $sourceType, string $catalogType): bool
    {
        $sourceType = $this->canonicalType($sourceType);
        $catalogType = $this->canonicalType($catalogType);

        return $sourceType !== null && $sourceType === $catalogType;
    }

    private function canonicalType(?string $type): ?string
    {
        if ($type === null) {
            return null;
        }

        return match ($this->normalizer->key($type)) {
            'film', 'movie' => 'film',
            'series', 'serial', 'tv series', 'tvseries' => 'series',
            'cartoon', 'cartoons', 'animation', 'animated movie' => 'cartoon',
            'anime' => 'anime',
            'documentary' => 'documentary',
            'show', 'tv show' => 'show',
            default => null,
        };
    }
}
