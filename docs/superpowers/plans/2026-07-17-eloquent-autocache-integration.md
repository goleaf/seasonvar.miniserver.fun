# Eloquent AutoCache Integration Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a production-ready, opt-in `wddyousuf/eloquent-autocache` integration for the bounded public country and genre filter queries without changing private, importer, relation, or existing tiered-cache boundaries.

**Architecture:** A project concern composes the vendor `Cacheable` trait and owns one exact, bounded filter-option query plus its warm-up contract. Only `Country` and `Genre` opt in; `CatalogTopListFilterOptions` consumes the named query, while configuration disables transaction caching, tags, row cache, SWR, and global automatic query caching. Existing Redis/Memcached/TieredCache infrastructure remains authoritative for domain snapshots and full responses.

**Tech Stack:** PHP 8.5, Laravel 13.20, Eloquent, `wddyousuf/eloquent-autocache:^0.2.3`, SQLite, Redis-compatible Laravel cache stores, PHPUnit 12.5, Laravel Pint.

## Global Constraints

- Work only on the existing `main` branch; do not create branches or worktrees.
- Preserve every pre-existing staged and unstaged change. The current worktree contains unrelated work, so no commit may absorb it without explicit user authorization.
- Treat the per-task commit steps as checkpoints while the inherited worktree is dirty. If the user authorizes one combined commit, defer it until Task 7 after the complete inherited tree passes verification; do not commit unverified pre-existing work at an earlier checkpoint.
- Use `config()` in application code and `env()` only in configuration files.
- Keep `AUTOCACHE_MODE=opt-in`, `AUTOCACHE_CACHE_IN_TRANSACTIONS=false`, `AUTOCACHE_USE_TAGS=false`, `AUTOCACHE_ROW_CACHE=false`, and `AUTOCACHE_SWR=0`.
- Cache only public `Country` and `Genre` rows with the exact columns `id`, `name`, and `slug`, ordered by `name`, then `id`, and limited to 100 rows.
- Do not add AutoCache to `CatalogTitle`, `User`, collections, reviews/comments, importer state, media/access models, or private queries.
- Do not add a cache store, migration, queue, scheduler, pivot listener, wildcard deletion, `Cache::flush()`, or Redis `KEYS` usage.
- Keep `cache.serializable_classes=false` and store only the package's plain array/scalar payloads.
- Use Russian prose in `README.md`, `CHANGELOG.md`, and project documentation; preserve exact technical identifiers.
- Apply TDD: write each behavioral test, observe the expected failure, then add only the production code needed to pass.

---

### Task 1: Lock the supported package dependency

**Files:**
- Create: `tests/Unit/EloquentAutoCacheDependencyTest.php`
- Modify: `composer.json`
- Modify: `composer.lock`

**Interfaces:**
- Consumes: Composer runtime metadata through `Composer\InstalledVersions`.
- Produces: installed classes `Wddyousuf\AutoCache\Traits\Cacheable`, `Wddyousuf\AutoCache\Facades\AutoCache`, and package version `0.2.x` locked by Composer.

- [ ] **Step 1: Write the failing dependency test**

```php
<?php

declare(strict_types=1);

namespace Tests\Unit;

use Composer\InstalledVersions;
use Tests\TestCase;

final class EloquentAutoCacheDependencyTest extends TestCase
{
    public function test_supported_eloquent_autocache_version_is_installed(): void
    {
        $this->assertTrue(InstalledVersions::isInstalled('wddyousuf/eloquent-autocache'));
        $this->assertMatchesRegularExpression(
            '/^v?0\.2\./',
            (string) InstalledVersions::getPrettyVersion('wddyousuf/eloquent-autocache'),
        );
        $this->assertTrue(class_exists(\Wddyousuf\AutoCache\Facades\AutoCache::class));
        $this->assertTrue(trait_exists(\Wddyousuf\AutoCache\Traits\Cacheable::class));
    }
}
```

- [ ] **Step 2: Run the test and verify RED**

Run:

```bash
php artisan test tests/Unit/EloquentAutoCacheDependencyTest.php
```

Expected: FAIL because `wddyousuf/eloquent-autocache` is not installed.

- [ ] **Step 3: Install the exact compatible dependency range**

Run:

```bash
composer require 'wddyousuf/eloquent-autocache:^0.2.3' --no-interaction
```

Inspect `composer.json` and `composer.lock`. Confirm that the package resolves to a stable `0.2.x` release compatible with `illuminate/* ^13.0`. Preserve the already-present `guzzlehttp/psr7` lock delta; do not reset or silently relabel it as part of AutoCache.

- [ ] **Step 4: Verify GREEN and Composer metadata**

Run:

```bash
php artisan test tests/Unit/EloquentAutoCacheDependencyTest.php
composer validate --strict
composer audit --locked
```

Expected: the focused test passes, Composer validation succeeds, and the audit reports no known vulnerable locked package.

- [ ] **Step 5: Commit the isolated dependency change when repository scope is authorized**

```bash
git add composer.json composer.lock tests/Unit/EloquentAutoCacheDependencyTest.php
git commit -m "build: add Eloquent AutoCache dependency"
```

Before running the commit, isolate or explicitly include pre-existing changes according to the user's repository-scope decision; never use `--no-verify`.

---

### Task 2: Publish strict project configuration

**Files:**
- Modify: `tests/Unit/EloquentAutoCacheDependencyTest.php`
- Create: `config/autocache.php`
- Modify: `phpunit.xml`
- Modify: `.env.example`

**Interfaces:**
- Consumes: package config keys merged by `AutoCacheServiceProvider` and the existing cache stores from `config/cache.php`.
- Produces: deterministic opt-in, strict-transaction configuration and CLI model discovery for `Country` and `Genre`.

- [ ] **Step 1: Add the failing configuration contract test**

Add this method to `EloquentAutoCacheDependencyTest`:

```php
public function test_project_autocache_configuration_is_bounded_and_opt_in(): void
{
    $this->assertTrue(config('autocache.enabled'));
    $this->assertSame('array', config('autocache.store'));
    $this->assertSame('opt-in', config('autocache.mode'));
    $this->assertSame(300, config('autocache.ttl'));
    $this->assertSame(0.1, config('autocache.ttl_jitter'));
    $this->assertFalse(config('autocache.use_tags'));
    $this->assertSame(5, config('autocache.lock_for'));
    $this->assertFalse(config('autocache.row_cache'));
    $this->assertFalse(config('autocache.cache_in_transactions'));
    $this->assertSame(0, config('autocache.swr'));
    $this->assertSame(100, config('autocache.max_rows'));
    $this->assertFalse(config('autocache.stats'));
    $this->assertSame([
        \App\Models\Country::class,
        \App\Models\Genre::class,
    ], config('autocache.models'));
    $this->assertFalse(config('autocache.pivot_invalidation.enabled'));
    $this->assertSame([], config('autocache.pivot_invalidation.map'));
    $this->assertFalse(config('cache.serializable_classes'));
}
```

- [ ] **Step 2: Run the test and verify RED**

```bash
php artisan test tests/Unit/EloquentAutoCacheDependencyTest.php
```

Expected: FAIL because package defaults use `auto`, transaction caching, tags/row cache, and different limits.

- [ ] **Step 3: Add the complete project configuration**

Create `config/autocache.php`:

```php
<?php

declare(strict_types=1);

use App\Models\Country;
use App\Models\Genre;
use Illuminate\Support\Str;

return [
    'enabled' => env('AUTOCACHE_ENABLED', true),
    'store' => env('AUTOCACHE_STORE', 'recomputable-failover'),
    'ttl' => (int) env('AUTOCACHE_TTL', 300),
    'ttl_jitter' => (float) env('AUTOCACHE_TTL_JITTER', 0.1),
    'prefix' => env(
        'AUTOCACHE_PREFIX',
        Str::slug((string) env('APP_NAME', 'seasonvar'))
            .':'.env('APP_ENV', 'production')
            .':eloquent-autocache:v1',
    ),
    'use_tags' => env('AUTOCACHE_USE_TAGS', false),
    'lock_for' => (int) env('AUTOCACHE_LOCK_FOR', 5),
    'mode' => env('AUTOCACHE_MODE', 'opt-in'),
    'row_cache' => env('AUTOCACHE_ROW_CACHE', false),
    'cache_in_transactions' => env('AUTOCACHE_CACHE_IN_TRANSACTIONS', false),
    'swr' => (int) env('AUTOCACHE_SWR', 0),
    'max_rows' => (int) env('AUTOCACHE_MAX_ROWS', 100),
    'volatile_patterns' => [
        'now()',
        'current_timestamp',
        'rand(',
        'random(',
        'uuid()',
        'newid()',
    ],
    'stats' => env('AUTOCACHE_STATS', false),
    'models' => [
        Country::class,
        Genre::class,
    ],
    'pivot_invalidation' => [
        'enabled' => false,
        'map' => [],
    ],
];
```

- [ ] **Step 4: Isolate PHPUnit from production cache services**

Add this forced environment value next to `CACHE_STORE=array` in `phpunit.xml`:

```xml
<env name="AUTOCACHE_STORE" value="array" force="true"/>
```

- [ ] **Step 5: Document every supported environment override**

Add immediately after the core cache variables in `.env.example`:

```dotenv
AUTOCACHE_ENABLED=true
AUTOCACHE_STORE=recomputable-failover
AUTOCACHE_TTL=300
AUTOCACHE_TTL_JITTER=0.1
# AUTOCACHE_PREFIX="seasonvar:production:eloquent-autocache:v1"
AUTOCACHE_USE_TAGS=false
AUTOCACHE_LOCK_FOR=5
AUTOCACHE_MODE=opt-in
AUTOCACHE_ROW_CACHE=false
AUTOCACHE_CACHE_IN_TRANSACTIONS=false
AUTOCACHE_SWR=0
AUTOCACHE_MAX_ROWS=100
AUTOCACHE_STATS=false
```

- [ ] **Step 6: Verify GREEN and cached configuration**

```bash
php artisan test tests/Unit/EloquentAutoCacheDependencyTest.php
php artisan config:cache
php artisan config:clear
```

Expected: both unit tests pass and Laravel can serialize and reload the complete configuration.

- [ ] **Step 7: Commit configuration after repository scope is authorized**

```bash
git add .env.example config/autocache.php phpunit.xml tests/Unit/EloquentAutoCacheDependencyTest.php
git commit -m "config: constrain Eloquent AutoCache"
```

---

### Task 3: Add the bounded cacheable model query

**Files:**
- Create: `tests/Feature/EloquentAutoCacheIntegrationTest.php`
- Create: `app/Models/Concerns/CachesCatalogFilterOptions.php`
- Modify: `app/Models/Country.php`
- Modify: `app/Models/Genre.php`
- Modify: `app/Services/Catalog/CatalogTopListFilterOptions.php`

**Interfaces:**
- Produces: `Country::cachedCatalogFilterOptions(): CachedBuilder<Country>` and `Genre::cachedCatalogFilterOptions(): CachedBuilder<Genre>`.
- Consumes: those named builders from `CatalogTopListFilterOptions::countries()` and `genres()`.

- [ ] **Step 1: Write the failing cache-hit test**

Create `tests/Feature/EloquentAutoCacheIntegrationTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Country;
use App\Models\Genre;
use App\Services\Catalog\CatalogTopListFilterOptions;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Wddyousuf\AutoCache\Facades\AutoCache;

final class EloquentAutoCacheIntegrationTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::store('array')->flush();
        AutoCache::clear();
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
```

- [ ] **Step 2: Run the test and verify RED**

```bash
php artisan test tests/Feature/EloquentAutoCacheIntegrationTest.php
```

Expected: FAIL with two `countries` and two `genres` SELECTs because the service still uses ordinary Eloquent builders; warming also fails because the registered models do not yet implement the project warm-up contract.

- [ ] **Step 3: Implement the single-purpose project concern**

Create `app/Models/Concerns/CachesCatalogFilterOptions.php`:

```php
<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use Wddyousuf\AutoCache\Query\CachedBuilder;
use Wddyousuf\AutoCache\Traits\Cacheable;

trait CachesCatalogFilterOptions
{
    use Cacheable;

    /** @return CachedBuilder<static> */
    public static function cachedCatalogFilterOptions(): CachedBuilder
    {
        return static::query()
            ->cache()
            ->select(['id', 'name', 'slug'])
            ->orderBy('name')
            ->orderBy('id')
            ->limit(100);
    }

    /** @return array<int, CachedBuilder<static>> */
    public function cacheWarmupQueries(): array
    {
        return [static::cachedCatalogFilterOptions()];
    }
}
```

- [ ] **Step 4: Attach the concern only to the two approved models**

In `Country.php` and `Genre.php`, import `App\Models\Concerns\CachesCatalogFilterOptions` and change the trait line to:

```php
use CachesCatalogFilterOptions, HasCatalogTitles;
```

Do not change any other model.

- [ ] **Step 5: Consume the named query at the public boundary**

Change only the builder initialization in `CatalogTopListFilterOptions`:

```php
return Country::cachedCatalogFilterOptions()
    ->get()
    ->map(fn (Country $country): array => [
        'name' => $country->name,
        'slug' => $country->slug,
    ])
    ->values();
```

```php
return Genre::cachedCatalogFilterOptions()
    ->get()
    ->map(fn (Genre $genre): array => [
        'name' => $genre->name,
        'slug' => $genre->slug,
    ])
    ->values();
```

Remove the duplicated `select`, ordering, and limit clauses from this service because the concern now owns that exact cache identity.

- [ ] **Step 6: Verify GREEN**

```bash
php artisan test tests/Feature/EloquentAutoCacheIntegrationTest.php
php artisan test --filter=CatalogTopListPageTest
```

Expected: one database SELECT per model for two identical service reads, and all Top 100 behavior remains green.

- [ ] **Step 7: Commit the model/query boundary after repository scope is authorized**

```bash
git add app/Models/Concerns/CachesCatalogFilterOptions.php app/Models/Country.php app/Models/Genre.php app/Services/Catalog/CatalogTopListFilterOptions.php tests/Feature/EloquentAutoCacheIntegrationTest.php
git commit -m "feat: cache public catalog filter options"
```

---

### Task 4: Prove invalidation, opt-in isolation, and rollback safety

**Files:**
- Modify: `tests/Feature/EloquentAutoCacheIntegrationTest.php`

**Interfaces:**
- Consumes: package write interception on `Country`/`Genre`, project strict transaction config, and the named cached query.
- Produces: regression proof for create/update/delete freshness, per-model isolation, uncached ordinary reads, and rollback correctness.

- [ ] **Step 1: Add the failing opt-in isolation test**

```php
public function test_ordinary_queries_remain_uncached_without_explicit_opt_in(): void
{
    Country::query()->create(['name' => 'Литва', 'slug' => 'litva']);

    $selects = $this->countTableSelects('countries', function (): void {
        Country::query()->orderBy('id')->get();
        Country::query()->orderBy('id')->get();
    });

    $this->assertSame(2, $selects);
}
```

Run the focused method as an opt-in regression check. It must execute two SQL reads; if it fails, fix configuration rather than adding per-query bypasses.

- [ ] **Step 2: Add create/update/delete freshness tests**

```php
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
```

Run each method immediately after adding it. Before the package/model concern is correct, the stale assertion must fail; after the production work from Task 3 it must pass.

- [ ] **Step 3: Add per-model invalidation isolation**

```php
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
```

- [ ] **Step 4: Add strict rollback regression coverage**

```php
public function test_rolled_back_write_cannot_poison_the_cache(): void
{
    Country::query()->create(['name' => 'Литва', 'slug' => 'litva']);
    $options = app(CatalogTopListFilterOptions::class);
    $this->assertSame(['litva'], $options->countries()->pluck('slug')->all());

    DB::beginTransaction();

    try {
        Country::query()->create(['name' => 'Несохранённая страна', 'slug' => 'rollback']);
        $this->assertSame(['litva', 'rollback'], $options->countries()->pluck('slug')->sort()->values()->all());
    } finally {
        DB::rollBack();
    }

    $selects = $this->countTableSelects('countries', function () use ($options): void {
        $this->assertSame(['litva'], $options->countries()->pluck('slug')->all());
        $this->assertSame(['litva'], $options->countries()->pluck('slug')->all());
    });

    $this->assertSame(1, $selects);
}
```

- [ ] **Step 5: Add Laravel 13 hydration safety coverage**

```php
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
```

- [ ] **Step 6: Run the complete integration file**

```bash
php artisan test tests/Feature/EloquentAutoCacheIntegrationTest.php
```

Expected: all cache-hit, opt-in, mutation, isolation, rollback, and hydration tests pass with no warnings.

- [ ] **Step 7: Commit regression coverage after repository scope is authorized**

```bash
git add tests/Feature/EloquentAutoCacheIntegrationTest.php
git commit -m "test: verify Eloquent AutoCache lifecycle"
```

---

### Task 5: Verify operator commands and exact warming

**Files:**
- Modify: `tests/Feature/EloquentAutoCacheIntegrationTest.php`
- Modify: `README.md`

**Interfaces:**
- Consumes: `config('autocache.models')` and `cacheWarmupQueries()` from the project concern.
- Produces: operator-verified warm, model flush, clear, and stats behavior limited to `Country` and `Genre`.

- [ ] **Step 1: Add the failing command/warming test**

```php
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
```

- [ ] **Step 2: Run the command test and verify RED/GREEN**

```bash
php artisan test --filter=operator_commands_discover_and_warm_only_registered_models
```

Expected before `cacheWarmupQueries()`/model registration is complete: FAIL because the exact service query is not warm. Expected after Tasks 2–3: PASS.

- [ ] **Step 3: Add concise operator commands to README**

Extend the existing `Администрирование и диагностика` command block with:

```bash
php artisan autocache:stats
php artisan autocache:warm --all
php artisan autocache:flush "App\Models\Country"
```

Add one Russian paragraph directly below the block explaining that AutoCache is opt-in, currently covers only public country/genre Top 100 options, and can be disabled with `AUTOCACHE_ENABLED=false` followed by config rebuild and graceful process reload. Do not describe private data as cached.

- [ ] **Step 4: Re-run integration and README policy tests**

```bash
php artisan test tests/Feature/EloquentAutoCacheIntegrationTest.php
php artisan test tests/Unit/ReadmePolicyScriptTest.php
```

- [ ] **Step 5: Commit operator integration after repository scope is authorized**

```bash
git add README.md tests/Feature/EloquentAutoCacheIntegrationTest.php
git commit -m "docs: add Eloquent AutoCache operations"
```

---

### Task 6: Update the canonical cache and dependency documentation

**Files:**
- Modify: `docs/caching.md`
- Modify: `docs/environment.md`
- Modify: `docs/deployment.md`
- Modify: `docs/upgrade.md`
- Modify: `docs/audits/dependency-report.md`
- Modify: `CHANGELOG.md`
- Modify: `README.md`

**Interfaces:**
- Consumes: final installed version, configuration keys, model scope, commands, tests, and rollback procedure.
- Produces: one authoritative operational contract linked from the existing documentation map.

- [ ] **Step 1: Add the canonical AutoCache section to `docs/caching.md`**

Document in Russian, in the existing Eloquent/data cache area:

- selected models: `Country`, `Genre`;
- exact query projection/order/limit;
- `opt-in`, 300-second TTL, jitter, strict transaction bypass, no tags/row/SWR/pivot listener;
- `recomputable-failover` production store and `array` PHPUnit store;
- automatic Eloquent write invalidation and the explicit-flush requirement after any future direct table write;
- no user/session/private values and no replacement of `TieredCache`;
- warm/flush/clear/stats commands and emergency disable procedure;
- finite-TTL recovery behavior and prohibition on store-wide flush/key scans.

- [ ] **Step 2: Update environment and deployment runbooks**

In `docs/environment.md`, add a table for all `AUTOCACHE_*` variables with the exact defaults from `config/autocache.php` and state that `AUTOCACHE_MODE=auto` is not an operator rollout flag.

In `docs/deployment.md`, add these exact rollout steps after Composer install/config rebuild guidance:

```bash
php artisan autocache:clear
php artisan autocache:warm --all
php artisan autocache:stats
```

Explain that rollback sets `AUTOCACHE_ENABLED=false`, rebuilds config, and gracefully reloads PHP-FPM/workers before any package removal.

- [ ] **Step 3: Update dependency ownership**

In `docs/audits/dependency-report.md`, record `wddyousuf/eloquent-autocache`, locked version, MIT license, Laravel 13 compatibility, package purpose, production scope, and audit result.

In `docs/upgrade.md`, add the package to controlled Composer dependency checks and require focused cache lifecycle tests for future updates.

- [ ] **Step 4: Add Russian changelog and visitor history entries**

Add one separate bullet under `## 2026-07-17` in `CHANGELOG.md` describing the opt-in dependency, exact public dictionaries, automatic invalidation, strict transaction behavior, commands, configuration, and tests without removing or combining prior entries.

Add `### 17 июля 2026 года` as the newest dated subsection in the final `История обновлений для посетителей` section of `README.md` with one visitor-facing bullet: repeated loading of country/genre controls in Top 100 now reuses a safely invalidated cache while private data remains outside it.

- [ ] **Step 5: Refresh managed documentation through Artisan**

```bash
php artisan project:docs-refresh
php artisan project:docs-refresh --check
```

Do not hand-edit text between `project-docs:start` and `project-docs:end`.

- [ ] **Step 6: Verify documentation policies**

```bash
php artisan test tests/Unit/ReadmePolicyScriptTest.php
php artisan test tests/Unit/ChangelogPolicyScriptTest.php
php artisan test tests/Feature/RefreshProjectDocsCommandTest.php
php artisan test tests/Unit/ProductionOperationsDocumentationTest.php
```

- [ ] **Step 7: Commit documentation after repository scope is authorized**

```bash
git add README.md CHANGELOG.md docs/caching.md docs/environment.md docs/deployment.md docs/upgrade.md docs/audits/dependency-report.md
git commit -m "docs: document Eloquent AutoCache lifecycle"
```

---

### Task 7: Format, verify, and finish on `main`

**Files:**
- Modify mechanically as reported: PHP files touched by Tasks 1–5.
- Verify: every file listed in Tasks 1–6 plus `README.md` actuality.

**Interfaces:**
- Consumes: complete implementation and documentation.
- Produces: fresh evidence for dependency safety, formatting, static analysis, focused behavior, full regressions, config caching, and repository cleanliness.

- [ ] **Step 1: Run Pint on changed PHP files**

```bash
./vendor/bin/pint --dirty --format agent
```

Review any formatting delta; it must not rewrite unrelated PHP files.

- [ ] **Step 2: Run focused AutoCache and adjacent catalog tests**

```bash
php artisan test tests/Unit/EloquentAutoCacheDependencyTest.php
php artisan test tests/Feature/EloquentAutoCacheIntegrationTest.php
php artisan test --filter=CatalogTopListPageTest
php artisan test --filter=CacheInfrastructureIntegrationTest
```

Expected: all tests pass with zero failures and warnings attributable to the change.

- [ ] **Step 3: Run framework, dependency, and static gates**

```bash
composer validate --strict
composer audit --locked
composer analyse
composer rector:check
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan config:clear
php artisan route:clear
php artisan view:clear
```

Expected: each command exits 0. These commands clear only generated configuration, route, and view artifacts; they do not run application data-cache deletion.

- [ ] **Step 4: Run the complete PHP suite**

```bash
php artisan test
```

Expected: the full PHPUnit suite exits 0.

- [ ] **Step 5: Verify frontend build because the inherited worktree contains frontend changes**

```bash
npm run build
```

The AutoCache work itself changes no frontend asset, but the final combined repository state must still compile before any all-tree commit.

- [ ] **Step 6: Re-run documentation and diff checks**

```bash
php artisan project:docs-refresh --check
git diff --check
git status --short --branch
```

Confirm the branch is `main`, `README.md` accurately describes the available commands/visitor result, no secret or `.env` file is present, and every integration file is included.

- [ ] **Step 7: Resolve the pre-existing-change scope before the final commit**

If the user authorized a combined commit, review the complete staged diff and commit all verified product changes on `main`. If the user did not authorize it, stop and report the existing staged/unstaged work as the project-mandated blocker; do not bypass the hook and do not leave an AutoCache-only success claim.

For an authorized combined commit:

```bash
git add composer.json composer.lock config/autocache.php phpunit.xml .env.example app/Models/Concerns/CachesCatalogFilterOptions.php app/Models/Country.php app/Models/Genre.php app/Services/Catalog/CatalogTopListFilterOptions.php tests/Unit/EloquentAutoCacheDependencyTest.php tests/Feature/EloquentAutoCacheIntegrationTest.php README.md CHANGELOG.md docs/caching.md docs/environment.md docs/deployment.md docs/upgrade.md docs/audits/dependency-report.md docs/superpowers/plans/2026-07-17-eloquent-autocache-integration.md
git status --short --branch
git commit -m "feat: integrate Eloquent AutoCache"
```

- [ ] **Step 8: Verify post-commit state**

```bash
git status --short --branch
git log -5 --oneline --decorate
```

Expected for task completion: `main` has no uncommitted tracked/untracked AutoCache changes, all authorized pre-existing changes are accounted for, and the integration commit plus any hook-managed documentation commit are visible.
