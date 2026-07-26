<?php

namespace App\Services\Catalog;

use App\Enums\CatalogFilterType;
use App\Models\Actor;
use App\Models\AgeRating;
use App\Models\CatalogStatus;
use App\Models\CatalogTitle;
use App\Models\CatalogTitleRating;
use App\Models\Country;
use App\Models\Director;
use App\Models\Genre;
use App\Models\Network;
use App\Models\Studio;
use App\Models\Tag;
use App\Models\Translation;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CatalogTaxonomyRegistry
{
    private const CARD_RATING_TITLE_PROVIDER_INDEX = 'catalog_title_ratings_catalog_title_id_provider_unique';

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
        return ['genres'];
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
     * @return array<string, \Closure>
     */
    public function relationSummaryLoads(): array
    {
        return collect(self::FILTER_RELATIONS)
            ->mapWithKeys(function (array $config): array {
                $modelClass = $config['model'];
                $table = (new $modelClass)->getTable();

                if ($modelClass === Tag::class && Tag::usesCanonicalSchema()) {
                    return [
                        $config['relation'] => fn ($query) => $query
                            ->select([
                                $table.'.id',
                                $table.'.public_id',
                                $table.'.code',
                                $table.'.name',
                                $table.'.slug',
                                $table.'.type',
                                $table.'.visibility',
                                $table.'.moderation_status',
                                $table.'.source',
                                $table.'.archived_at',
                                $table.'.merged_into_id',
                            ])
                            ->publiclyEligible()
                            ->withLocalizedLabel()
                            ->orderBy($table.'.name')
                            ->orderBy($table.'.id'),
                    ];
                }

                return [
                    $config['relation'] => fn ($query) => $query
                        ->select([$table.'.id', $table.'.name', $table.'.slug'])
                        ->orderBy($table.'.name')
                        ->orderBy($table.'.id'),
                ];
            })
            ->all();
    }

    /**
     * @return array<string, \Closure>
     */
    public function cardSummaryLoads(): array
    {
        return [
            ...collect($this->relationSummaryLoads())
                ->only($this->cardRelations())
                ->all(),
            'ratings' => /** @param HasMany<CatalogTitleRating, CatalogTitle> $relation */
                fn (HasMany $relation): HasMany => $this->preferCardRatingTitleProviderIndex($relation)
                    ->select([
                        'catalog_title_ratings.catalog_title_id',
                        'catalog_title_ratings.provider',
                        'catalog_title_ratings.rating',
                    ])
                    ->whereIn('catalog_title_ratings.provider', ['kinopoisk', 'imdb'])
                    ->whereBetween('catalog_title_ratings.rating', [0, 10]),
        ];
    }

    /**
     * @param  HasMany<CatalogTitleRating, CatalogTitle>  $relation
     * @return HasMany<CatalogTitleRating, CatalogTitle>
     */
    private function preferCardRatingTitleProviderIndex(HasMany $relation): HasMany
    {
        $query = $relation->getQuery();
        $connection = $query->getModel()->getConnection();

        if ($connection->getDriverName() !== 'sqlite') {
            return $relation;
        }

        $grammar = $connection->getQueryGrammar();

        $query->fromRaw(
            $grammar->wrapTable($query->getModel()->getTable())
            .' INDEXED BY '
            .$grammar->wrap(self::CARD_RATING_TITLE_PROVIDER_INDEX),
        );

        return $relation;
    }

    public function relationName(string $filterType): string
    {
        return self::FILTER_RELATIONS[$filterType]['relation'];
    }

    /** @return array{table: string, title_key: string, related_key: string} */
    public function pivot(string $filterType): array
    {
        $relation = (new CatalogTitle)->{$this->relationName($filterType)}();

        return [
            'table' => $relation->getTable(),
            'title_key' => $relation->getForeignPivotKeyName(),
            'related_key' => $relation->getRelatedPivotKeyName(),
        ];
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
