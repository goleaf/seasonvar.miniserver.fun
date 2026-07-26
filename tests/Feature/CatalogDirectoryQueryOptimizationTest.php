<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\DTOs\CatalogDirectoryDefinition;
use App\Enums\ContentAudience;
use App\Enums\PublicationStatus;
use App\Enums\TagVisibility;
use App\Models\Actor;
use App\Models\CatalogTitle;
use App\Models\Tag;
use App\Services\Catalog\CatalogDirectoryQuery;
use App\Services\Catalog\CatalogDirectoryRegistry;
use App\Support\Cache\CacheDomain;
use App\Support\Cache\CacheVersionRegistry;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class CatalogDirectoryQueryOptimizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_name_order_counts_only_values_reaching_the_bounded_page(): void
    {
        $alpha = Actor::query()->create([
            'name' => 'Альфа Актёр',
            'slug' => 'alfa-akter',
        ]);
        $beta = Actor::query()->create([
            'name' => 'Бета Актёр',
            'slug' => 'beta-akter',
        ]);
        $hidden = Actor::query()->create([
            'name' => 'Скрытый Актёр',
            'slug' => 'skrytyi-akter',
        ]);
        Actor::query()->create([
            'name' => 'Без связей',
            'slug' => 'bez-sviazei',
        ]);

        $firstVisible = CatalogTitle::factory()->create();
        $secondVisible = CatalogTitle::factory()->create();
        $betaVisible = CatalogTitle::factory()->create();
        $draft = CatalogTitle::factory()->create([
            'is_published' => false,
            'publication_status' => PublicationStatus::Draft,
        ]);
        $future = CatalogTitle::factory()->create([
            'available_from' => now()->addHour(),
        ]);
        $expired = CatalogTitle::factory()->create([
            'available_until' => now()->subHour(),
        ]);
        $deleted = CatalogTitle::factory()->create();

        $alpha->catalogTitles()->attach([
            $firstVisible->id,
            $secondVisible->id,
            $draft->id,
            $future->id,
            $expired->id,
            $deleted->id,
        ]);
        $beta->catalogTitles()->attach($betaVisible);
        $hidden->catalogTitles()->attach($draft);
        $deleted->delete();

        $queries = $this->captureQueries(function () use ($alpha, $beta): void {
            $items = app(CatalogDirectoryQuery::class)->paginate(
                $this->actorsDirectory(),
                search: '',
                letter: '',
                sort: 'name_asc',
                decade: null,
                total: 4,
                perPage: 48,
            )->getCollection();

            $this->assertSame([$alpha->id, $beta->id], $items->pluck('id')->all());
            $this->assertSame([2, 1], $items->pluck('published_titles_count')->map(
                fn (mixed $count): int => (int) $count,
            )->all());
        });

        $resultQuery = collect($queries)->sole(
            fn (string $sql): bool => str_contains($sql, 'from actors')
                && str_contains($sql, 'published_titles_count'),
        );

        $this->assertStringContainsString(
            'exists (select 1 from catalog_title_actor as directory_visible_links',
            $resultQuery,
        );
        $this->assertStringContainsString(
            'select count(distinct directory_visible_links.catalog_title_id)',
            $resultQuery,
        );
        $this->assertStringNotContainsString('directory_value_counts', $resultQuery);
        $this->assertStringNotContainsString('group by actor_id', $resultQuery);
    }

    public function test_count_order_keeps_the_global_grouped_aggregate(): void
    {
        $alpha = Actor::query()->create([
            'name' => 'Альфа Актёр',
            'slug' => 'alfa-count-akter',
        ]);
        $beta = Actor::query()->create([
            'name' => 'Бета Актёр',
            'slug' => 'beta-count-akter',
        ]);
        $first = CatalogTitle::factory()->create();
        $second = CatalogTitle::factory()->create();

        $alpha->catalogTitles()->attach([$first->id, $second->id]);
        $beta->catalogTitles()->attach($first);

        $queries = $this->captureQueries(function () use ($alpha, $beta): void {
            $items = app(CatalogDirectoryQuery::class)->paginate(
                $this->actorsDirectory(),
                search: '',
                letter: '',
                sort: 'count_desc',
                decade: null,
                total: 2,
                perPage: 48,
            )->getCollection();

            $this->assertSame([$alpha->id, $beta->id], $items->pluck('id')->all());
            $this->assertSame([2, 1], $items->pluck('published_titles_count')->map(
                fn (mixed $count): int => (int) $count,
            )->all());
        });

        $resultQuery = collect($queries)->sole(
            fn (string $sql): bool => str_contains($sql, 'from actors')
                && str_contains($sql, 'published_titles_count'),
        );

        $this->assertStringContainsString('directory_value_counts', $resultQuery);
        $this->assertStringContainsString('group by actor_id', $resultQuery);
        $this->assertStringContainsString(
            'order by published_titles_count desc, actors.name asc, actors.id asc',
            $resultQuery,
        );
    }

    public function test_filtered_total_groups_only_visible_candidate_pivot_values(): void
    {
        $alpha = Actor::query()->create([
            'name' => 'Alpha Actor',
            'slug' => 'alpha-filtered-actor',
        ]);
        $alphaDraft = Actor::query()->create([
            'name' => 'Alpha Draft Actor',
            'slug' => 'alpha-draft-filtered-actor',
        ]);
        $beta = Actor::query()->create([
            'name' => 'Beta Actor',
            'slug' => 'beta-filtered-actor',
        ]);

        $alpha->catalogTitles()->attach([
            CatalogTitle::factory()->create()->id,
            CatalogTitle::factory()->create()->id,
        ]);
        $alphaDraft->catalogTitles()->attach(
            CatalogTitle::factory()->create([
                'is_published' => false,
                'publication_status' => PublicationStatus::Draft,
            ]),
        );
        $beta->catalogTitles()->attach(CatalogTitle::factory()->create());

        $queries = $this->captureQueries(function () use ($alpha): void {
            $paginator = app(CatalogDirectoryQuery::class)->paginate(
                $this->actorsDirectory(),
                search: 'Alpha',
                letter: '',
                sort: 'name_asc',
                decade: null,
                total: 3,
                perPage: 48,
            );
            $items = $paginator->getCollection();

            $this->assertSame(1, $paginator->total());
            $this->assertSame([$alpha->id], $items->pluck('id')->all());
            $this->assertSame([2], $items->pluck('published_titles_count')->map(
                fn (mixed $count): int => (int) $count,
            )->all());
        });

        $totalQuery = collect($queries)->sole(
            fn (string $sql): bool => str_contains($sql, 'as aggregate')
                && (
                    str_contains($sql, 'as filtered_directory_values')
                    || str_contains($sql, 'count(distinct actors.id)')
                ),
        );

        $this->assertStringContainsString(
            'from (select actor_id from catalog_title_actor where actor_id in (select actors.id from actors',
            $totalQuery,
        );
        $this->assertStringContainsString('group by actor_id) as filtered_directory_values', $totalQuery);
        $this->assertStringNotContainsString('from actors inner join catalog_title_actor', $totalQuery);
        $this->assertStringNotContainsString('count(distinct actors.id)', $totalQuery);
    }

    public function test_filtered_count_order_scopes_the_grouped_aggregate_to_candidates(): void
    {
        $alpha = Actor::query()->create([
            'name' => 'Alpha Actor',
            'slug' => 'alpha-filtered-count-actor',
        ]);
        $alpine = Actor::query()->create([
            'name' => 'Alpine Actor',
            'slug' => 'alpine-filtered-count-actor',
        ]);
        $beta = Actor::query()->create([
            'name' => 'Beta Actor',
            'slug' => 'beta-filtered-count-actor',
        ]);
        $hidden = Actor::query()->create([
            'name' => 'Archived Actor',
            'slug' => 'archived-filtered-count-actor',
        ]);

        $firstAlpha = CatalogTitle::factory()->create();
        $secondAlpha = CatalogTitle::factory()->create();
        $alpineVisible = CatalogTitle::factory()->create();
        $betaVisible = CatalogTitle::factory()->count(3)->create();
        $draft = CatalogTitle::factory()->create([
            'is_published' => false,
            'publication_status' => PublicationStatus::Draft,
        ]);
        $future = CatalogTitle::factory()->create([
            'available_from' => now()->addHour(),
        ]);
        $expired = CatalogTitle::factory()->create([
            'available_until' => now()->subHour(),
        ]);
        $deleted = CatalogTitle::factory()->create();

        $alpha->catalogTitles()->attach([$firstAlpha->id, $secondAlpha->id]);
        $alpine->catalogTitles()->attach($alpineVisible);
        $beta->catalogTitles()->attach($betaVisible);
        $hidden->catalogTitles()->attach([$draft->id, $future->id, $expired->id, $deleted->id]);
        $deleted->delete();

        $queries = $this->captureQueries(function () use ($alpha, $alpine): void {
            $paginator = app(CatalogDirectoryQuery::class)->paginate(
                $this->actorsDirectory(),
                search: '',
                letter: 'A',
                sort: 'count_desc',
                decade: null,
                total: 4,
                perPage: 48,
            );
            $items = $paginator->getCollection();

            $this->assertSame(2, $paginator->total());
            $this->assertSame([$alpha->id, $alpine->id], $items->pluck('id')->all());
            $this->assertSame([2, 1], $items->pluck('published_titles_count')->map(
                fn (mixed $count): int => (int) $count,
            )->all());
        });

        $resultQuery = collect($queries)->sole(
            fn (string $sql): bool => str_contains($sql, 'from actors')
                && str_contains($sql, 'directory_value_counts'),
        );

        $this->assertStringContainsString(
            'actor_id in (select actors.id from actors',
            $resultQuery,
        );
        $this->assertStringContainsString('group by actor_id', $resultQuery);
        $this->assertStringContainsString(
            'order by published_titles_count desc, actors.name asc, actors.id asc',
            $resultQuery,
        );
    }

    public function test_alphabet_groups_visible_value_ids_before_joining_taxonomy_labels(): void
    {
        $latin = Actor::query()->create([
            'name' => 'Alice Actor',
            'slug' => 'alice-alphabet-actor',
        ]);
        $cyrillic = Actor::query()->create([
            'name' => 'Борис Актёр',
            'slug' => 'boris-alphabet-akter',
        ]);
        $symbol = Actor::query()->create([
            'name' => '123 Actor',
            'slug' => '123-alphabet-actor',
        ]);
        $draftOnly = Actor::query()->create([
            'name' => 'Draft Actor',
            'slug' => 'draft-alphabet-actor',
        ]);
        $futureOnly = Actor::query()->create([
            'name' => 'Future Actor',
            'slug' => 'future-alphabet-actor',
        ]);
        $expiredOnly = Actor::query()->create([
            'name' => 'Expired Actor',
            'slug' => 'expired-alphabet-actor',
        ]);
        $deletedOnly = Actor::query()->create([
            'name' => 'Deleted Actor',
            'slug' => 'deleted-alphabet-actor',
        ]);

        $firstVisible = CatalogTitle::factory()->create();
        $secondVisible = CatalogTitle::factory()->create();
        $draft = CatalogTitle::factory()->create([
            'is_published' => false,
            'publication_status' => PublicationStatus::Draft,
        ]);
        $future = CatalogTitle::factory()->create([
            'available_from' => now()->addHour(),
        ]);
        $expired = CatalogTitle::factory()->create([
            'available_until' => now()->subHour(),
        ]);
        $deleted = CatalogTitle::factory()->create();

        $latin->catalogTitles()->attach([$firstVisible->id, $secondVisible->id]);
        $cyrillic->catalogTitles()->attach($firstVisible);
        $symbol->catalogTitles()->attach($secondVisible);
        $draftOnly->catalogTitles()->attach($draft);
        $futureOnly->catalogTitles()->attach($future);
        $expiredOnly->catalogTitles()->attach($expired);
        $deletedOnly->catalogTitles()->attach($deleted);
        $deleted->delete();

        app(CacheVersionRegistry::class)->bump(CacheDomain::CatalogFacets);

        $queries = $this->captureQueries(function (): void {
            $letters = app(CatalogDirectoryQuery::class)->letters($this->actorsDirectory());

            $this->assertEqualsCanonicalizing(['#', 'A', 'Б'], $letters->all());
        });

        $alphabetQuery = collect($queries)->sole(
            fn (string $sql): bool => str_contains($sql, 'as initial')
                && str_contains($sql, 'from actors'),
        );

        $this->assertStringContainsString(
            'inner join (select catalog_title_actor.actor_id as directory_value_id',
            $alphabetQuery,
        );
        $this->assertStringContainsString('group by catalog_title_actor.actor_id', $alphabetQuery);
        $this->assertStringContainsString('as directory_visible_values', $alphabetQuery);
        $this->assertStringNotContainsString(
            'from actors inner join catalog_title_actor',
            $alphabetQuery,
        );
    }

    public function test_tag_alphabet_uses_one_translation_lookup_when_active_and_fallback_locales_match(): void
    {
        app()->setLocale('ru');
        config(['app.fallback_locale' => 'ru']);

        $title = CatalogTitle::factory()->create();
        $tag = Tag::query()->create([
            'name' => 'Canonical tag',
            'slug' => 'canonical-alphabet-tag',
        ]);
        $tag->translations()->create([
            'locale' => 'ru',
            'label' => 'Жанр',
        ]);
        $title->tags()->attach($tag);

        app(CacheVersionRegistry::class)->bump(CacheDomain::CatalogFacets);

        $queries = $this->captureQueries(function (): void {
            $letters = app(CatalogDirectoryQuery::class)->letters($this->tagsDirectory());

            $this->assertSame(['Ж'], $letters->all());
        });

        $alphabetQuery = collect($queries)->sole(
            fn (string $sql): bool => str_contains($sql, 'as initial')
                && str_contains($sql, 'from tags'),
        );

        $this->assertSame(1, substr_count($alphabetQuery, 'from tag_translations as'));
    }

    public function test_filtered_tag_candidates_preserve_localized_search_and_public_eligibility(): void
    {
        app()->setLocale('ru');
        config(['app.fallback_locale' => 'ru']);

        $visibleTitle = CatalogTitle::factory()->create();
        $eligible = Tag::query()->create([
            'name' => 'Science fiction',
            'slug' => 'science-fiction-filtered-tag',
        ]);
        $eligible->translations()->create([
            'locale' => 'ru',
            'label' => 'Научная фантастика',
        ]);
        $eligible->catalogTitles()->attach($visibleTitle);

        $internal = Tag::query()->create([
            'name' => 'Internal science fiction',
            'slug' => 'internal-science-fiction-filtered-tag',
            'visibility' => TagVisibility::Internal,
        ]);
        $internal->translations()->create([
            'locale' => 'ru',
            'label' => 'Скрытая фантастика',
        ]);
        $internal->catalogTitles()->attach(CatalogTitle::factory()->create());

        $paginator = app(CatalogDirectoryQuery::class)->paginate(
            $this->tagsDirectory(),
            search: 'фантастика',
            letter: '',
            sort: 'count_desc',
            decade: null,
            total: 2,
            perPage: 48,
        );
        $items = $paginator->getCollection();

        $this->assertSame(1, $paginator->total());
        $this->assertSame([$eligible->id], $items->pluck('id')->all());
        $this->assertSame(['Научная фантастика'], $items->pluck('name')->all());
        $this->assertSame([1], $items->pluck('published_titles_count')->map(
            fn (mixed $count): int => (int) $count,
        )->all());
    }

    public function test_decades_rebuild_selects_distinct_years_without_database_decade_grouping(): void
    {
        CatalogTitle::factory()->create(['year' => 2024]);
        CatalogTitle::factory()->create(['year' => 2021]);
        CatalogTitle::factory()->create(['year' => 2015]);
        CatalogTitle::factory()->create([
            'year' => 1999,
            'is_published' => false,
            'publication_status' => PublicationStatus::Draft,
        ]);
        CatalogTitle::factory()->create([
            'year' => 1988,
            'available_from' => now()->addHour(),
        ]);
        CatalogTitle::factory()->create([
            'year' => 1977,
            'available_until' => now()->subHour(),
        ]);
        CatalogTitle::factory()->create([
            'year' => 1966,
            'audience' => ContentAudience::Authenticated,
        ]);
        $deleted = CatalogTitle::factory()->create(['year' => 1955]);
        $deleted->delete();
        app(CacheVersionRegistry::class)->bump(CacheDomain::CatalogFacets);

        $queries = $this->captureQueries(function (): void {
            $this->assertSame(
                [2020, 2010],
                app(CatalogDirectoryQuery::class)->decades()->all(),
            );
        });

        $decadesQuery = collect($queries)->sole(
            fn (string $sql): bool => str_contains($sql, 'from catalog_titles')
                && (
                    str_contains($sql, 'as decade')
                    || str_contains($sql, 'select distinct year')
                ),
        );

        $this->assertStringStartsWith('select distinct year from catalog_titles', $decadesQuery);
        $this->assertStringContainsString('order by year desc', $decadesQuery);
        $this->assertStringNotContainsString('cast(year / 10', $decadesQuery);
        $this->assertStringNotContainsString('group by decade', $decadesQuery);
    }

    /**
     * @param  callable(): void  $callback
     * @return list<string>
     */
    private function captureQueries(callable $callback): array
    {
        $queries = [];

        DB::listen(function (QueryExecuted $query) use (&$queries): void {
            $queries[] = Str::of($query->sql)
                ->replace(['`', '"'], '')
                ->lower()
                ->squish()
                ->toString();
        });

        $callback();

        return $queries;
    }

    private function actorsDirectory(): CatalogDirectoryDefinition
    {
        $directory = app(CatalogDirectoryRegistry::class)->find('actors');
        $this->assertNotNull($directory);

        return $directory;
    }

    private function tagsDirectory(): CatalogDirectoryDefinition
    {
        $directory = app(CatalogDirectoryRegistry::class)->find('tags');
        $this->assertNotNull($directory);

        return $directory;
    }
}
