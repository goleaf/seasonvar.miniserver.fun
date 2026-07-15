<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\CatalogTitle;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class CatalogTitleHttpQueryBudgetTest extends TestCase
{
    use RefreshDatabase;

    public function test_title_http_render_reuses_page_and_playback_reads_within_the_request(): void
    {
        config(['cache-architecture.page_cache.enabled' => false]);

        $title = CatalogTitle::factory()->create([
            'title' => 'Сериал с ограниченным бюджетом запросов',
            'slug' => 'serial-s-ogranichennym-biudzhetom-zaprosov',
        ]);
        $queries = [];

        DB::listen(function (QueryExecuted $query) use (&$queries): void {
            if ($this->isCatalogRead($query->sql)) {
                $queries[] = [
                    'sql' => $query->sql,
                    'bindings' => $query->bindings,
                ];
            }
        });

        $response = $this->get(route('titles.show', $title));

        $response
            ->assertOk()
            ->assertSeeText($title->title)
            ->assertSee('data-livewire-placeholder', false);

        $fingerprints = collect($queries)
            ->map(fn (array $query): string => $query['sql'].'|'.json_encode($query['bindings'], JSON_THROW_ON_ERROR))
            ->countBy();
        $duplicates = $fingerprints
            ->filter(fn (int $count): bool => $count > 1)
            ->all();

        $this->assertLessThanOrEqual(
            30,
            count($queries),
            "Страница тайтла превысила catalog query budget:\n".collect($queries)->pluck('sql')->implode("\n"),
        );
        $this->assertLessThanOrEqual(
            2,
            count($duplicates),
            "Страница тайтла повторила одинаковые запросы:\n".collect($duplicates)
                ->map(fn (int $count, string $query): string => "{$count}x {$query}")
                ->implode("\n"),
        );
    }

    private function isCatalogRead(string $sql): bool
    {
        return str_contains($sql, 'catalog_')
            || str_contains($sql, 'licensed_media')
            || str_contains($sql, 'episodes')
            || str_contains($sql, 'seasons')
            || str_contains($sql, 'genres')
            || str_contains($sql, 'countries')
            || str_contains($sql, 'actors')
            || str_contains($sql, 'directors')
            || str_contains($sql, 'age_ratings')
            || str_contains($sql, 'translations')
            || str_contains($sql, 'statuses')
            || str_contains($sql, 'networks')
            || str_contains($sql, 'studios')
            || str_contains($sql, 'tags');
    }
}
