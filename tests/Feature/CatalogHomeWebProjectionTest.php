<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\CatalogRecommendationType;
use App\Models\CatalogTitle;
use App\Models\LicensedMedia;
use App\Services\Catalog\CatalogHomePageBuilder;
use App\Services\Catalog\CatalogHomeSnapshotCache;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class CatalogHomeWebProjectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_web_projection_is_bounded_while_api_keeps_the_full_snapshot(): void
    {
        $this->seedHomeProjectionTitles();

        $snapshot = app(CatalogHomeSnapshotCache::class)->refresh();
        $builder = app(CatalogHomePageBuilder::class);
        $full = $builder->data();
        $web = $builder->webData();

        $this->assertCount(16, $snapshot['latest_title_ids']);
        $this->assertCount(16, $full['latestTitles']);
        $this->assertCount(12, $web['latestTitles']);
        $this->assertCount(12, $full['featuredTitles']);
        $this->assertCount(12, $full['latestMedia']);
        $this->assertEmpty($web['featuredTitles']);
        $this->assertEmpty($web['latestMedia']);
        $this->assertSame(
            collect($full['latestTitles'])->take(12)->pluck('id')->all(),
            collect($web['latestTitles'])->pluck('id')->all(),
        );
        $this->assertTrue($web['hasMoreLatestTitles']);
        $this->assertSame(
            route('discover.index', ['type' => CatalogRecommendationType::RecentlyUpdated->value]),
            $web['recentlyUpdatedUrl'],
        );
        $this->assertEmpty($web['homeRecommendationItems']);

        $webResponse = $this->get(route('home'))->assertOk();
        $webHtml = $webResponse->getContent();

        $this->assertSame(12, substr_count($webHtml, 'data-home-latest-update-card'));
        $webResponse
            ->assertSee('data-home-latest-updates-all', false)
            ->assertSee($web['recentlyUpdatedUrl'], false)
            ->assertSeeText(__('home.actions.view_all'));

        $this->getJson('/api/v1/home')
            ->assertOk()
            ->assertJsonCount(16, 'data.latest_titles');
    }

    public function test_web_projection_does_not_run_api_only_hydration_queries(): void
    {
        $this->seedHomeProjectionTitles();
        app(CatalogHomeSnapshotCache::class)->refresh();
        $queries = [];
        DB::listen(function (QueryExecuted $query) use (&$queries): void {
            $queries[] = str($query->sql)
                ->replace(['`', '"'], '')
                ->lower()
                ->squish()
                ->toString();
        });

        $web = app(CatalogHomePageBuilder::class)->webData();
        $cardTitleHydrations = collect($queries)->filter(
            fn (string $sql): bool => str_starts_with(
                $sql,
                'select id, slug, title, original_title, type, year, description, poster_url, indexed_at from catalog_titles where',
            ),
        );
        $latestMediaHydrations = collect($queries)->filter(
            fn (string $sql): bool => str_starts_with(
                $sql,
                'select id, catalog_title_id, season_id, episode_id, title, quality, translation_name, format, published_at from licensed_media where',
            ) && str_contains($sql, 'licensed_media.id in'),
        );
        $this->assertEmpty($web['featuredTitles']);
        $this->assertEmpty($web['latestMedia']);
        $this->assertCount(2, $cardTitleHydrations, implode("\n", $queries));
        $this->assertEmpty($latestMediaHydrations, implode("\n", $latestMediaHydrations->all()));

        foreach ([
            'genres' => 'catalog_title_genre',
            'countries' => 'catalog_title_country',
            'age_ratings' => 'age_rating_catalog_title',
            'translations' => 'catalog_title_translation',
            'tags' => 'catalog_title_tag',
        ] as $table => $pivotTable) {
            $taxonomyHydrations = collect($queries)->filter(
                fn (string $sql): bool => str_contains(
                    $sql,
                    "from {$table} inner join {$pivotTable}",
                ),
            );

            $this->assertCount(2, $taxonomyHydrations, implode("\n", $taxonomyHydrations->all()));
        }
    }

    private function seedHomeProjectionTitles(): void
    {
        $this->travelTo(now()->setDate(2026, 7, 26)->setTime(12, 0));

        foreach (range(1, 16) as $index) {
            $createdAt = now()->subMinutes($index);
            $title = CatalogTitle::factory()->create([
                'slug' => "homepage-web-projection-{$index}",
                'title' => "Главная web-проекция {$index}",
                'poster_url' => "https://media.example.com/homepage-web-projection-{$index}.jpg",
                'indexed_at' => $createdAt,
            ]);

            LicensedMedia::factory()->create([
                'catalog_title_id' => $title->id,
                'status' => 'published',
                'published_at' => $createdAt,
                'created_at' => $createdAt,
                'updated_at' => $createdAt,
            ]);
        }
    }
}
