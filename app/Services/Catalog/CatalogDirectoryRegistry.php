<?php

namespace App\Services\Catalog;

use App\DTOs\CatalogDirectoryDefinition;
use App\Enums\CatalogFilterType;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class CatalogDirectoryRegistry
{
    /**
     * @var array<string, array{path: string, filter_type: string|null, alphabet: bool, icon: string}>
     */
    private const DIRECTORIES = [
        'genres' => ['path' => 'genres', 'filter_type' => 'genre', 'alphabet' => false, 'icon' => 'fa-solid fa-masks-theater'],
        'countries' => ['path' => 'countries', 'filter_type' => 'country', 'alphabet' => false, 'icon' => 'fa-solid fa-flag'],
        'actors' => ['path' => 'actors', 'filter_type' => 'actor', 'alphabet' => true, 'icon' => 'fa-solid fa-user-group'],
        'directors' => ['path' => 'directors', 'filter_type' => 'director', 'alphabet' => true, 'icon' => 'fa-solid fa-video'],
        'age-ratings' => ['path' => 'age-ratings', 'filter_type' => 'age_rating', 'alphabet' => false, 'icon' => 'fa-solid fa-shield-halved'],
        'translations' => ['path' => 'translations', 'filter_type' => 'translation', 'alphabet' => false, 'icon' => 'fa-solid fa-language'],
        'statuses' => ['path' => 'statuses', 'filter_type' => 'status', 'alphabet' => false, 'icon' => 'fa-solid fa-signal'],
        'networks' => ['path' => 'networks', 'filter_type' => 'network', 'alphabet' => false, 'icon' => 'fa-solid fa-tower-broadcast'],
        'studios' => ['path' => 'studios', 'filter_type' => 'studio', 'alphabet' => false, 'icon' => 'fa-solid fa-building'],
        'tags' => ['path' => 'tags', 'filter_type' => 'tag', 'alphabet' => true, 'icon' => 'fa-solid fa-tags'],
        'years' => ['path' => 'years', 'filter_type' => null, 'alphabet' => false, 'icon' => 'fa-solid fa-calendar-days'],
    ];

    public function __construct(
        private readonly CatalogTaxonomyRegistry $taxonomies,
    ) {}

    /**
     * Route-safe metadata needed before the application container resolves this service.
     *
     * @return array<string, array{path: string, filter_type: string|null, alphabet: bool, icon: string}>
     */
    public static function routeMap(): array
    {
        return self::DIRECTORIES;
    }

    /** @return Collection<string, CatalogDirectoryDefinition> */
    public function all(): Collection
    {
        return collect(self::DIRECTORIES)
            ->mapWithKeys(fn (array $config, string $key): array => [
                $key => $this->definition($key, $config),
            ]);
    }

    public function find(mixed $key): ?CatalogDirectoryDefinition
    {
        if (! is_string($key) || ! isset(self::DIRECTORIES[$key])) {
            return null;
        }

        return $this->definition($key, self::DIRECTORIES[$key]);
    }

    public function forFilterType(string $filterType): ?CatalogDirectoryDefinition
    {
        return $this->all()->first(
            fn (CatalogDirectoryDefinition $directory): bool => $directory->filterType?->value === $filterType,
        );
    }

    /** @return Collection<int, CatalogDirectoryDefinition> */
    public function suggestions(string $search, int $limit = 3): Collection
    {
        $search = Str::lower(Str::squish($search));

        if (mb_strlen($search) < 3) {
            return collect();
        }

        return $this->all()
            ->filter(function (CatalogDirectoryDefinition $directory) use ($search): bool {
                $title = Str::lower($directory->title);
                $item = Str::lower($directory->itemLabel);

                return str_contains($title, $search)
                    || str_contains($search, $title)
                    || str_contains($search, $item)
                    || str_contains($item, $search);
            })
            ->take(max(1, min($limit, 5)))
            ->values();
    }

    /**
     * @param  array{path: string, filter_type: string|null, alphabet: bool, icon: string}  $config
     */
    private function definition(string $key, array $config): CatalogDirectoryDefinition
    {
        $filterType = $config['filter_type'] === null
            ? null
            : CatalogFilterType::from($config['filter_type']);

        if ($filterType !== null && ! $this->taxonomies->supports($filterType->value)) {
            throw new \LogicException('Directory taxonomy is not registered: '.$filterType->value);
        }

        return new CatalogDirectoryDefinition(
            key: $key,
            path: $config['path'],
            indexRouteName: $key.'.index',
            detailRouteName: $key.'.show',
            title: (string) __("catalog.directories.{$key}.title"),
            description: (string) __("catalog.directories.{$key}.description"),
            itemLabel: (string) __("catalog.directories.{$key}.item"),
            icon: $config['icon'],
            filterType: $filterType,
            supportsAlphabet: $config['alphabet'],
            perPage: $filterType?->value === 'actor' || $filterType?->value === 'director'
                ? (int) config('catalog.directories.people_per_page', 48)
                : (int) config('catalog.directories.per_page', 36),
        );
    }
}
