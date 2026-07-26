<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\DTOs\CatalogDirectoryDefinition;
use App\Enums\PublicationStatus;
use App\Models\Actor;
use App\Models\CatalogTitle;
use App\Services\Catalog\CatalogDirectoryQuery;
use App\Services\Catalog\CatalogDirectoryRegistry;
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
            'where exists (select 1 from catalog_title_actor as directory_visible_links',
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
}
