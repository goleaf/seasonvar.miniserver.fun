<?php

namespace App\Services\Catalog;

use App\Enums\CatalogFilterType;
use App\Models\Actor;
use App\Models\AgeRating;
use App\Models\CatalogStatus;
use App\Models\Country;
use App\Models\Director;
use App\Models\Genre;
use App\Models\Network;
use App\Models\Studio;
use App\Models\Tag;
use App\Models\Translation;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class CatalogTaxonomyRegistry
{
    /**
     * @var array<string, array{model: class-string<Model>, relation: string}>
     */
    private const FILTER_RELATIONS = [
        'genre' => ['model' => Genre::class, 'relation' => 'genres'],
        'country' => ['model' => Country::class, 'relation' => 'countries'],
        'actor' => ['model' => Actor::class, 'relation' => 'actors'],
        'director' => ['model' => Director::class, 'relation' => 'directors'],
        'age_rating' => ['model' => AgeRating::class, 'relation' => 'ageRatings'],
        'translation' => ['model' => Translation::class, 'relation' => 'translations'],
        'status' => ['model' => CatalogStatus::class, 'relation' => 'statuses'],
        'network' => ['model' => Network::class, 'relation' => 'networks'],
        'studio' => ['model' => Studio::class, 'relation' => 'studios'],
        'tag' => ['model' => Tag::class, 'relation' => 'tags'],
    ];

    /**
     * @return array<string, array{model: class-string<Model>, relation: string}>
     */
    public function relations(): array
    {
        return self::FILTER_RELATIONS;
    }

    /**
     * @return list<string>
     */
    public function filterTypes(): array
    {
        return CatalogFilterType::values();
    }

    /**
     * @return list<string>
     */
    public function relationNames(): array
    {
        return collect(self::FILTER_RELATIONS)->pluck('relation')->values()->all();
    }

    /**
     * @return list<string>
     */
    public function cardRelations(): array
    {
        return ['genres', 'countries', 'ageRatings', 'translations', 'tags'];
    }

    /**
     * @return list<string>
     */
    public function listRowRelations(): array
    {
        return array_values(array_unique([
            'latestSeason',
            ...$this->cardRelations(),
        ]));
    }

    /**
     * @return array<string, \Closure(BelongsToMany): BelongsToMany>
     */
    public function relationSummaryLoads(): array
    {
        return collect(self::FILTER_RELATIONS)
            ->mapWithKeys(function (array $config): array {
                $modelClass = $config['model'];
                $table = (new $modelClass)->getTable();

                return [
                    $config['relation'] => fn (BelongsToMany $query): BelongsToMany => $query
                        ->select([$table.'.id', $table.'.name', $table.'.slug'])
                        ->orderBy($table.'.name')
                        ->orderBy($table.'.id'),
                ];
            })
            ->all();
    }

    /**
     * @return array<string, \Closure(BelongsToMany): BelongsToMany>
     */
    public function cardSummaryLoads(): array
    {
        return collect($this->relationSummaryLoads())
            ->only($this->cardRelations())
            ->all();
    }

    public function relationName(string $filterType): string
    {
        return self::FILTER_RELATIONS[$filterType]['relation'];
    }

    public function supports(string $filterType): bool
    {
        return isset(self::FILTER_RELATIONS[$filterType]);
    }

    /**
     * @return class-string<Model>
     */
    public function modelClass(string $filterType): string
    {
        return self::FILTER_RELATIONS[$filterType]['model'];
    }
}
