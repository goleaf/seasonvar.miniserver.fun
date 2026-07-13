<?php

declare(strict_types=1);

namespace App\Services\Catalog\Search;

use App\Models\CatalogTitle;
use App\Services\Catalog\CatalogTaxonomyRegistry;
use Illuminate\Support\Collection;
use LogicException;

final readonly class CatalogSearchDocumentBuilder
{
    public function __construct(
        private CatalogTaxonomyRegistry $taxonomies,
        private CatalogSearchNormalizer $normalizer,
    ) {}

    /**
     * @return array{catalog_title_id: int, title: string, original_title: string, aliases: string, transliteration: string, people: string, taxonomies: string, description: string, suggestion_names: string, normalized_title_key: string, normalized_original_title_key: string, normalized_alias_keys: string, fingerprint: string}
     */
    public function build(CatalogTitle $title): array
    {
        $this->assertRelationsLoaded($title);

        $titleText = $this->normalizer->display((string) $title->title);
        $originalTitle = $this->normalizer->display((string) $title->original_title);
        $aliases = $this->sortedUniqueText($title->aliases->pluck('name'));
        $people = $this->sortedUniqueText($title->actors->pluck('name')->merge($title->directors->pluck('name')));
        $taxonomies = $this->sortedUniqueText(
            collect($this->taxonomies->relations())
                ->reject(fn (array $config): bool => in_array($config['relation'], ['actors', 'directors'], true))
                ->flatMap(fn (array $config): Collection => $title->{$config['relation']}->pluck('name')),
        );
        $description = $this->normalizer->display((string) $title->description);
        $suggestionNames = $this->sortedUniqueText(collect([
            $titleText,
            $originalTitle,
            ...$aliases,
            ...$people,
        ]));
        $transliteration = $this->sortedUniqueText(collect([
            $titleText,
            $originalTitle,
            ...$aliases,
            ...$people,
            ...$taxonomies,
        ])->map(fn (string $value): string => $this->normalizer->transliterate($value)));
        $document = [
            'catalog_title_id' => (int) $title->id,
            'title' => $titleText,
            'original_title' => $originalTitle,
            'aliases' => $aliases->implode("\n"),
            'transliteration' => $transliteration->implode("\n"),
            'people' => $people->implode("\n"),
            'taxonomies' => $taxonomies->implode("\n"),
            'description' => $description,
            'suggestion_names' => $suggestionNames->implode("\n"),
            'normalized_title_key' => $this->normalizer->key($titleText),
            'normalized_original_title_key' => $this->normalizer->key($originalTitle),
            'normalized_alias_keys' => $aliases
                ->map(fn (string $alias): string => $this->normalizer->key($alias))
                ->filter()
                ->implode("\n"),
        ];

        $document['fingerprint'] = hash(
            'sha256',
            json_encode(array_values($document), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        );

        return $document;
    }

    private function assertRelationsLoaded(CatalogTitle $title): void
    {
        foreach (['aliases', ...$this->taxonomies->relationNames()] as $relation) {
            if (! $title->relationLoaded($relation)) {
                throw new LogicException("Search document relation [{$relation}] must be eager loaded.");
            }
        }
    }

    /** @param Collection<int, mixed> $values */
    private function sortedUniqueText(Collection $values): Collection
    {
        return $values
            ->map(fn (mixed $value): string => $this->normalizer->display((string) $value))
            ->filter()
            ->unique(fn (string $value): string => $this->normalizer->key($value))
            ->sortBy(fn (string $value): string => $this->normalizer->key($value))
            ->values();
    }
}
