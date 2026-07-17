<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Country;
use App\Models\Genre;
use App\Services\Catalog\CatalogTopListFilterOptions;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class EloquentAutoCacheIntegrationTest extends TestCase
{
    use DatabaseTruncation;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::store('array')->flush();
    }

    protected function beforeTruncatingDatabase(): void
    {
        RefreshDatabaseState::$migrated = false;
    }

    public function test_public_filter_options_cache_identical_country_and_genre_reads(): void
    {
        Country::query()->create(['name' => 'Литва', 'slug' => 'litva']);
        Genre::query()->create(['name' => 'Драмы', 'slug' => 'dramy']);
        $options = app(CatalogTopListFilterOptions::class);

        $countrySelects = $this->countTableSelects('countries', function () use ($options): void {
            $this->assertSame(['litva'], $options->countries()->pluck('slug')->all());
            $this->assertSame(['litva'], $options->countries()->pluck('slug')->all());
        });
        $genreSelects = $this->countTableSelects('genres', function () use ($options): void {
            $this->assertSame(['dramy'], $options->genres()->pluck('slug')->all());
            $this->assertSame(['dramy'], $options->genres()->pluck('slug')->all());
        });

        $this->assertSame(1, $countrySelects);
        $this->assertSame(1, $genreSelects);
    }

    public function test_warm_all_primes_the_exact_filter_option_queries(): void
    {
        Country::query()->create(['name' => 'Литва', 'slug' => 'litva']);
        Genre::query()->create(['name' => 'Драмы', 'slug' => 'dramy']);

        $this->artisan('autocache:warm', ['--all' => true])
            ->expectsOutputToContain(Country::class)
            ->expectsOutputToContain(Genre::class)
            ->assertSuccessful();

        $options = app(CatalogTopListFilterOptions::class);
        $this->assertSame(0, $this->countTableSelects('countries', fn () => $options->countries()));
        $this->assertSame(0, $this->countTableSelects('genres', fn () => $options->genres()));
    }

    public function test_ordinary_queries_remain_uncached_without_explicit_opt_in(): void
    {
        Country::query()->create(['name' => 'Литва', 'slug' => 'litva']);

        $selects = $this->countTableSelects('countries', function (): void {
            Country::query()->orderBy('id')->get();
            Country::query()->orderBy('id')->get();
        });

        $this->assertSame(2, $selects);
    }

    public function test_create_invalidates_cached_country_options(): void
    {
        Country::query()->create(['name' => 'Литва', 'slug' => 'litva']);
        $options = app(CatalogTopListFilterOptions::class);
        $this->assertSame(['litva'], $options->countries()->pluck('slug')->all());

        Country::query()->create(['name' => 'Эстония', 'slug' => 'estoniya']);

        $this->assertSame(['litva', 'estoniya'], $options->countries()->pluck('slug')->all());
    }

    public function test_update_invalidates_cached_genre_options(): void
    {
        $genre = Genre::query()->create(['name' => 'Драмы', 'slug' => 'dramy']);
        $options = app(CatalogTopListFilterOptions::class);
        $this->assertSame(['dramy'], $options->genres()->pluck('slug')->all());

        $genre->update(['name' => 'Комедии', 'slug' => 'komedii']);

        $this->assertSame(['komedii'], $options->genres()->pluck('slug')->all());
    }

    public function test_delete_invalidates_cached_country_options(): void
    {
        $country = Country::query()->create(['name' => 'Литва', 'slug' => 'litva']);
        $options = app(CatalogTopListFilterOptions::class);
        $this->assertSame(['litva'], $options->countries()->pluck('slug')->all());

        $country->delete();

        $this->assertSame([], $options->countries()->all());
    }

    public function test_country_write_does_not_flush_genre_cache(): void
    {
        $country = Country::query()->create(['name' => 'Литва', 'slug' => 'litva']);
        Genre::query()->create(['name' => 'Драмы', 'slug' => 'dramy']);
        $options = app(CatalogTopListFilterOptions::class);
        $options->countries();
        $options->genres();

        $country->update(['name' => 'Литовская Республика']);

        $countrySelects = $this->countTableSelects('countries', fn () => $options->countries());
        $genreSelects = $this->countTableSelects('genres', fn () => $options->genres());

        $this->assertSame(1, $countrySelects);
        $this->assertSame(0, $genreSelects);
    }

    public function test_rolled_back_write_cannot_poison_the_cache(): void
    {
        Country::query()->create(['name' => 'Литва', 'slug' => 'litva']);
        $options = app(CatalogTopListFilterOptions::class);
        $this->assertSame(['litva'], $options->countries()->pluck('slug')->all());

        DB::beginTransaction();

        try {
            Country::query()->create(['name' => 'Несохранённая страна', 'slug' => 'rollback']);
            $this->assertSame(
                ['litva', 'rollback'],
                $options->countries()->pluck('slug')->sort()->values()->all(),
            );
        } finally {
            DB::rollBack();
        }

        $selects = $this->countTableSelects('countries', function () use ($options): void {
            $this->assertSame(['litva'], $options->countries()->pluck('slug')->all());
            $this->assertSame(['litva'], $options->countries()->pluck('slug')->all());
        });

        $this->assertSame(1, $selects);
    }

    public function test_cached_rows_hydrate_as_models_with_serializable_classes_disabled(): void
    {
        Country::query()->create(['name' => 'Литва', 'slug' => 'litva']);

        $first = Country::cachedCatalogFilterOptions()->get();
        $second = Country::cachedCatalogFilterOptions()->get();

        $this->assertFalse(config('cache.serializable_classes'));
        $this->assertInstanceOf(Country::class, $first->first());
        $this->assertInstanceOf(Country::class, $second->first());
        $this->assertSame(['id', 'name', 'slug'], array_keys($second->firstOrFail()->getAttributes()));
        $this->assertArrayNotHasKey('__PHP_Incomplete_Class_Name', $second->firstOrFail()->getAttributes());
    }

    public function test_operator_commands_discover_and_warm_only_registered_models(): void
    {
        Country::query()->create(['name' => 'Литва', 'slug' => 'litva']);
        Genre::query()->create(['name' => 'Драмы', 'slug' => 'dramy']);

        $this->artisan('autocache:warm', ['--all' => true])
            ->expectsOutputToContain(Country::class)
            ->expectsOutputToContain(Genre::class)
            ->assertSuccessful();

        $options = app(CatalogTopListFilterOptions::class);
        $this->assertSame(0, $this->countTableSelects('countries', fn () => $options->countries()));
        $this->assertSame(0, $this->countTableSelects('genres', fn () => $options->genres()));

        $this->artisan('autocache:flush', ['model' => Country::class])->assertSuccessful();
        $this->assertSame(1, $this->countTableSelects('countries', fn () => $options->countries()));
        $this->assertSame(0, $this->countTableSelects('genres', fn () => $options->genres()));

        $this->artisan('autocache:clear')->assertSuccessful();
        $this->artisan('autocache:stats')
            ->expectsOutputToContain('Stats are disabled')
            ->assertSuccessful();
    }

    private function countTableSelects(string $table, callable $callback): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        try {
            $callback();

            return collect(DB::getQueryLog())
                ->pluck('query')
                ->filter(fn (string $query): bool => str_starts_with(strtolower(ltrim($query)), 'select')
                    && str_contains($query, '"'.$table.'"'))
                ->count();
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }
    }
}
