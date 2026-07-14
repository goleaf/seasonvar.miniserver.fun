<?php

namespace App\Services\Catalog;

use App\DTOs\CatalogDirectoryDefinition;
use App\Models\CatalogTitle;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Pagination\LengthAwarePaginator as LaravelLengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CatalogDirectoryQuery
{
    public function __construct(
        private readonly CatalogTaxonomyRegistry $taxonomies,
        private readonly CatalogTitleQuery $titles,
    ) {}

    /** @return LengthAwarePaginator<int, Model|object> */
    public function paginate(
        CatalogDirectoryDefinition $directory,
        string $search,
        string $letter,
        string $sort,
        ?int $decade,
        ?int $total = null,
        ?int $perPage = null,
    ): LengthAwarePaginator {
        $query = $directory->isYear()
            ? $this->yearQuery($search, $decade)
            : $this->taxonomyQuery($directory, $search, $letter);

        if ($directory->isYear()) {
            $sort === 'count_desc'
                ? $query->orderByDesc('published_titles_count')->orderByDesc('year')
                : $query->orderByDesc('year');
        } else {
            $filterType = $directory->filterType?->value;
            abort_if($filterType === null, 404);
            $table = (new ($this->taxonomies->modelClass($filterType)))->getTable();

            if ($sort === 'count_desc') {
                $query->orderByDesc('published_titles_count');
            }

            $query->orderBy($table.'.name')->orderBy($table.'.id');
        }

        $page = max(1, LaravelLengthAwarePaginator::resolveCurrentPage('page'));
        $hasFilters = $search !== '' || $letter !== '' || $decade !== null;
        $total = $hasFilters
            ? $this->filteredValueCount($directory, $search, $letter, $decade)
            : ($total ?? $this->summary($directory)['values']);
        $perPage = max(1, min(50, $perPage ?? $directory->perPage));
        $items = $query->forPage($page, $perPage)->get();

        return new LaravelLengthAwarePaginator($items, $total, $perPage, $page, [
            'path' => request()->url(),
            'query' => request()->query(),
            'pageName' => 'page',
        ]);
    }

    /** @return array{values: int, titles: int} */
    public function summary(CatalogDirectoryDefinition $directory): array
    {
        if ($directory->isYear()) {
            $summary = DB::query()
                ->fromSub(
                    $this->validYearTitles()->select(['catalog_titles.id', 'catalog_titles.year']),
                    'visible_directory_years',
                )
                ->selectRaw('count(distinct year) as values_count')
                ->selectRaw('count(*) as titles_count')
                ->first();

            return [
                'values' => (int) ($summary?->values_count ?? 0),
                'titles' => (int) ($summary?->titles_count ?? 0),
            ];
        }

        $filterType = $directory->filterType?->value;
        abort_if($filterType === null, 404);
        $pivot = $this->pivot($filterType);
        $visibleTitles = $this->titles->visibleTo(null)->select('catalog_titles.id');
        $visibleAlias = 'visible_directory_summary_titles';
        $summaryQuery = DB::table($pivot['table'])->joinSub(
            $visibleTitles,
            $visibleAlias,
            $visibleAlias.'.id',
            '=',
            $pivot['table'].'.'.$pivot['title_key'],
        );
        $summary = $summaryQuery
            ->selectRaw("count(distinct {$pivot['table']}.{$pivot['related_key']}) as values_count")
            ->selectRaw("count(distinct {$pivot['table']}.{$pivot['title_key']}) as titles_count")
            ->first();

        return [
            'values' => (int) ($summary?->values_count ?? 0),
            'titles' => (int) ($summary?->titles_count ?? 0),
        ];
    }

    /** @return Collection<int, string> */
    public function letters(CatalogDirectoryDefinition $directory): Collection
    {
        if (! $directory->supportsAlphabet || $directory->filterType === null) {
            return collect();
        }

        $filterType = $directory->filterType->value;
        $modelClass = $this->taxonomies->modelClass($filterType);
        $model = new $modelClass;
        $table = $model->getTable();
        $pivot = $this->pivot($filterType);
        $visibleAlias = 'visible_directory_letter_titles';

        return DB::table($table)
            ->selectRaw("substr({$table}.name, 1, 1) as initial")
            ->join($pivot['table'], $pivot['table'].'.'.$pivot['related_key'], '=', $table.'.id')
            ->joinSub(
                $this->titles->visibleTo(null)->select('catalog_titles.id'),
                $visibleAlias,
                $visibleAlias.'.id',
                '=',
                $pivot['table'].'.'.$pivot['title_key'],
            )
            ->whereNotNull($table.'.name')
            ->where($table.'.name', '<>', '')
            ->groupBy('initial')
            ->pluck('initial')
            ->map(fn (mixed $initial): string => $this->normalizedInitial((string) $initial))
            ->unique()
            ->sortBy(fn (string $letter): string => $letter === '#' ? '~~~' : Str::lower($letter))
            ->values();
    }

    /** @return Collection<int, int> */
    public function decades(): Collection
    {
        return $this->validYearTitles()
            ->selectRaw('(cast(year / 10 as integer) * 10) as decade')
            ->groupBy('decade')
            ->orderByDesc('decade')
            ->pluck('decade')
            ->map(fn (mixed $decade): int => (int) $decade)
            ->values();
    }

    public function detailExists(CatalogDirectoryDefinition $directory, string|int $value): bool
    {
        if ($directory->isYear()) {
            $year = filter_var($value, FILTER_VALIDATE_INT, [
                'options' => ['min_range' => $this->minimumYear(), 'max_range' => $this->maximumYear()],
            ]);

            return $year !== false && $this->validYearTitles()->where('year', $year)->exists();
        }

        if (! is_string($value) || preg_match('/^[a-z0-9][a-z0-9-]*$/', $value) !== 1 || $directory->filterType === null) {
            return false;
        }

        $modelClass = $this->taxonomies->modelClass($directory->filterType->value);

        return $modelClass::query()
            ->where('slug', $value)
            ->whereHas('catalogTitles', fn (Builder $query): Builder => $this->titles->constrainVisible($query, null))
            ->exists();
    }

    /** @return Builder<Model> */
    private function taxonomyQuery(
        CatalogDirectoryDefinition $directory,
        string $search,
        string $letter,
    ): Builder {
        $filterType = $directory->filterType?->value;
        abort_if($filterType === null, 404);
        $modelClass = $this->taxonomies->modelClass($filterType);
        $model = new $modelClass;
        $table = $model->getTable();
        $pivot = $this->pivot($filterType);
        $counts = DB::table($pivot['table'])
            ->selectRaw("{$pivot['related_key']} as directory_value_id")
            ->selectRaw("count(distinct {$pivot['title_key']}) as published_titles_count")
            ->whereIn(
                $pivot['title_key'],
                $this->titles->visibleTo(null)->select('catalog_titles.id'),
            )
            ->groupBy($pivot['related_key']);

        $query = $modelClass::query()
            ->select([
                $table.'.id',
                $table.'.name',
                $table.'.slug',
                'directory_value_counts.published_titles_count',
            ])
            ->joinSub(
                $counts,
                'directory_value_counts',
                'directory_value_counts.directory_value_id',
                '=',
                $table.'.id',
            )
            ->whereNotNull($table.'.name')
            ->where($table.'.name', '<>', '')
            ->whereNotNull($table.'.slug')
            ->where($table.'.slug', '<>', '');

        $this->applyTaxonomySearch($query, $table, $search);

        if ($letter !== '') {
            $this->applyLetter($query, $table, $letter);
        }

        return $query;
    }

    private function filteredValueCount(
        CatalogDirectoryDefinition $directory,
        string $search,
        string $letter,
        ?int $decade,
    ): int {
        if ($directory->isYear()) {
            $query = $this->validYearTitles();

            if ($search !== '') {
                if (preg_match('/^\d{4}$/', $search) === 1) {
                    $query->where('year', (int) $search);
                } else {
                    $query->whereRaw('1 = 0');
                }
            }

            if ($decade !== null) {
                $query->whereBetween('year', [$decade, $decade + 9]);
            }

            return $query->distinct()->count('year');
        }

        $filterType = $directory->filterType?->value;
        abort_if($filterType === null, 404);
        $modelClass = $this->taxonomies->modelClass($filterType);
        $table = (new $modelClass)->getTable();
        $pivot = $this->pivot($filterType);
        $visibleAlias = 'visible_directory_filtered_count_titles';
        $query = $modelClass::query()
            ->join($pivot['table'], $pivot['table'].'.'.$pivot['related_key'], '=', $table.'.id')
            ->joinSub(
                $this->titles->visibleTo(null)->select('catalog_titles.id'),
                $visibleAlias,
                $visibleAlias.'.id',
                '=',
                $pivot['table'].'.'.$pivot['title_key'],
            );
        $this->applyTaxonomySearch($query, $table, $search);

        if ($letter !== '') {
            $this->applyLetter($query, $table, $letter);
        }

        return $query->distinct()->count($table.'.id');
    }

    /** @param Builder<Model> $query */
    private function applyTaxonomySearch(Builder $query, string $table, string $search): void
    {
        if ($search === '') {
            return;
        }

        $term = str_replace(['%', '_'], '', $search);
        $slug = Str::slug($term);
        $query->where(function (Builder $query) use ($table, $term, $slug): void {
            $query->where($table.'.name', 'like', '%'.$term.'%');

            if ($slug !== '') {
                $query->orWhere($table.'.slug', 'like', '%'.$slug.'%');
            }
        });
    }

    private function yearQuery(string $search, ?int $decade): QueryBuilder
    {
        $query = DB::query()
            ->fromSub($this->validYearTitles()->select(['catalog_titles.id', 'catalog_titles.year']), 'visible_year_titles')
            ->selectRaw('year as id, cast(year as text) as name, year, count(distinct id) as published_titles_count')
            ->groupBy('year');

        if ($search !== '') {
            if (preg_match('/^\d{4}$/', $search) === 1) {
                $query->where('year', (int) $search);
            } else {
                $query->whereRaw('1 = 0');
            }
        }

        if ($decade !== null) {
            $query->whereBetween('year', [$decade, $decade + 9]);
        }

        return $query;
    }

    /** @return Builder<CatalogTitle> */
    private function validYearTitles(): Builder
    {
        return $this->titles->visibleTo(null)
            ->whereNotNull('year')
            ->whereBetween('year', [$this->minimumYear(), $this->maximumYear()]);
    }

    /** @param Builder<Model> $query */
    private function applyLetter(Builder $query, string $table, string $letter): void
    {
        if ($letter === '#') {
            $driver = DB::connection()->getDriverName();

            match ($driver) {
                'mysql', 'mariadb' => $query->whereRaw("{$table}.name not regexp '^[[:alpha:]]'"),
                'pgsql' => $query->whereRaw("{$table}.name !~ '^[[:alpha:]]'"),
                default => $query->whereRaw("substr({$table}.name, 1, 1) not glob '[A-Za-zА-Яа-яЁё]'"),
            };

            return;
        }

        $upper = mb_strtoupper($letter);
        $lower = mb_strtolower($letter);
        $query->where(function (Builder $query) use ($table, $upper, $lower): void {
            $query->where($table.'.name', 'like', $upper.'%')
                ->orWhere($table.'.name', 'like', $lower.'%');
        });
    }

    private function normalizedInitial(string $initial): string
    {
        return preg_match('/^[A-Za-zА-Яа-яЁё]$/u', $initial) === 1
            ? mb_strtoupper($initial)
            : '#';
    }

    /** @return array{table: string, title_key: string, related_key: string} */
    private function pivot(string $filterType): array
    {
        $relation = (new CatalogTitle)->{$this->taxonomies->relationName($filterType)}();

        return [
            'table' => $relation->getTable(),
            'title_key' => $relation->getForeignPivotKeyName(),
            'related_key' => $relation->getRelatedPivotKeyName(),
        ];
    }

    private function minimumYear(): int
    {
        return max(1900, (int) config('catalog.directories.minimum_year', 1900));
    }

    private function maximumYear(): int
    {
        $configured = config('catalog.directories.maximum_year');

        return is_numeric($configured)
            ? max($this->minimumYear(), (int) $configured)
            : now()->year + 1;
    }
}
