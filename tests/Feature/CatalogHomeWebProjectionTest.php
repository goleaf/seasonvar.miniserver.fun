<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\CatalogRecommendationType;
use App\Models\CatalogTitle;
use App\Models\LicensedMedia;
use App\Services\Catalog\CatalogHomePageBuilder;
use App\Services\Catalog\CatalogHomeSnapshotCache;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class CatalogHomeWebProjectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_web_projection_is_bounded_while_api_keeps_the_full_snapshot(): void
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

        $snapshot = app(CatalogHomeSnapshotCache::class)->refresh();
        $builder = app(CatalogHomePageBuilder::class);
        $full = $builder->data();
        $web = $builder->webData();

        $this->assertCount(16, $snapshot['latest_title_ids']);
        $this->assertCount(16, $full['latestTitles']);
        $this->assertCount(12, $web['latestTitles']);
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
}
