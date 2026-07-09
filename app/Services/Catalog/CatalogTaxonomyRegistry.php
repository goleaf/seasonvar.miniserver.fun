<?php

namespace App\Services\Catalog;

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
        return array_keys(self::FILTER_RELATIONS);
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
        return ['genres', 'countries', 'ageRatings', 'translations', 'tags', 'seasons'];
    }

    public function relationName(string $filterType): string
    {
        return self::FILTER_RELATIONS[$filterType]['relation'];
    }

    /**
     * @return class-string<Model>
     */
    public function modelClass(string $filterType): string
    {
        return self::FILTER_RELATIONS[$filterType]['model'];
    }
}
