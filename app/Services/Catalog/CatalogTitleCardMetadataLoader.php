<?php

declare(strict_types=1);

namespace App\Services\Catalog;

use App\Enums\ReleaseKind;
use App\Models\CatalogTitle;
use App\Models\Season;
use App\Models\User;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class CatalogTitleCardMetadataLoader
{
    private const NEW_EPISODE_WINDOW_DAYS = 7;

    private const SQLITE_RATING_INDEX = 'catalog_title_ratings_catalog_title_id_provider_unique';

    public function __construct(
        private readonly DatabaseManager $database,
    ) {}

    /**
     * @param  Collection<int, CatalogTitle>  $titles
     * @return Collection<int, CatalogTitle>
     */
    public function load(
        Collection $titles,
        ?User $user,
        bool $includeCountry,
    ): Collection {
        if ($titles->isEmpty()) {
            return $titles;
        }

        $titleIds = $titles
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
        $queries = [
            $this->ratingQuery($titleIds),
            ...($includeCountry ? [$this->countryQuery($titleIds)] : []),
            $this->adultQuery($titleIds),
            $this->recentEpisodeQuery($titleIds, $user),
        ];
        $query = array_shift($queries);

        foreach ($queries as $union) {
            $query->unionAll($union);
        }

        $metadata = $query
            ->get()
            ->groupBy(fn (object $row): int => (int) $row->catalog_title_id);

        return $titles->each(function (CatalogTitle $title) use ($metadata): void {
            $titleMetadata = $metadata->get($title->id, collect());
            $ratings = $titleMetadata
                ->where('metadata_type', 'rating')
                ->keyBy('text_value');

            $title->setAttribute(
                'card_imdb_rating',
                $this->ratingValue($ratings->get('imdb')),
            );
            $title->setAttribute(
                'card_kinopoisk_rating',
                $this->ratingValue($ratings->get('kinopoisk')),
            );
            $title->setAttribute(
                'card_country_name',
                $this->textValue($titleMetadata->firstWhere('metadata_type', 'country')),
            );
            $title->setAttribute(
                'card_is_adult',
                $titleMetadata->contains('metadata_type', 'adult'),
            );
            $title->setAttribute(
                'card_has_new_episode',
                $titleMetadata->contains('metadata_type', 'new_episode'),
            );
        });
    }

    /**
     * @param  list<int>  $titleIds
     */
    private function ratingQuery(array $titleIds): Builder
    {
        $connection = $this->database->connection();
        $query = $connection->table('catalog_title_ratings');

        if ($connection->getDriverName() === 'sqlite') {
            $grammar = $connection->getQueryGrammar();

            $query->fromRaw(
                $grammar->wrapTable('catalog_title_ratings')
                .' INDEXED BY '
                .$grammar->wrap(self::SQLITE_RATING_INDEX),
            );
        }

        return $query
            ->whereIn('catalog_title_id', $titleIds)
            ->whereIn('provider', ['imdb', 'kinopoisk'])
            ->whereBetween('rating', [0, 10])
            ->select('catalog_title_id')
            ->selectRaw('? AS metadata_type', ['rating'])
            ->selectRaw('provider AS text_value')
            ->selectRaw('rating AS numeric_value');
    }

    /**
     * @param  list<int>  $titleIds
     */
    private function countryQuery(array $titleIds): Builder
    {
        return DB::table('catalog_title_country')
            ->join('countries', 'countries.id', '=', 'catalog_title_country.country_id')
            ->whereIn('catalog_title_country.catalog_title_id', $titleIds)
            ->groupBy('catalog_title_country.catalog_title_id')
            ->select('catalog_title_country.catalog_title_id')
            ->selectRaw('? AS metadata_type', ['country'])
            ->selectRaw('MIN(countries.name) AS text_value')
            ->selectRaw('0 AS numeric_value');
    }

    /**
     * @param  list<int>  $titleIds
     */
    private function adultQuery(array $titleIds): Builder
    {
        return DB::table('age_rating_catalog_title')
            ->join('age_ratings', 'age_ratings.id', '=', 'age_rating_catalog_title.age_rating_id')
            ->whereIn('age_rating_catalog_title.catalog_title_id', $titleIds)
            ->where('age_ratings.name', '18+')
            ->groupBy('age_rating_catalog_title.catalog_title_id')
            ->select('age_rating_catalog_title.catalog_title_id')
            ->selectRaw('? AS metadata_type', ['adult'])
            ->selectRaw('MIN(age_ratings.name) AS text_value')
            ->selectRaw('1 AS numeric_value');
    }

    /**
     * @param  list<int>  $titleIds
     */
    private function recentEpisodeQuery(array $titleIds, ?User $user): Builder
    {
        $today = today()->toDateString();
        $cutoff = today()
            ->subDays(self::NEW_EPISODE_WINDOW_DAYS - 1)
            ->toDateString();

        return Season::query()
            ->availableTo($user)
            ->whereIn('seasons.catalog_title_id', $titleIds)
            ->where('seasons.kind', ReleaseKind::Regular->value)
            ->where(
                'seasons.latest_episode_released_at',
                '>=',
                $cutoff,
            )
            ->where('seasons.latest_episode_released_at', '<=', $today)
            ->groupBy('seasons.catalog_title_id')
            ->select('seasons.catalog_title_id')
            ->selectRaw('? AS metadata_type', ['new_episode'])
            ->selectRaw('MAX(seasons.latest_episode_released_at) AS text_value')
            ->selectRaw('1 AS numeric_value')
            ->toBase();
    }

    private function ratingValue(?object $row): ?float
    {
        if ($row === null || ! is_numeric($row->numeric_value ?? null)) {
            return null;
        }

        return (float) $row->numeric_value;
    }

    private function textValue(?object $row): ?string
    {
        $value = $row->text_value ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }
}
