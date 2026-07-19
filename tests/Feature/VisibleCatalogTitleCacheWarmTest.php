<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\WarmCatalogTitlePage;
use App\Models\CatalogTitle;
use App\Models\LicensedMedia;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

final class VisibleCatalogTitleCacheWarmTest extends TestCase
{
    use RefreshDatabase;

    public function test_catalog_dispatches_only_rendered_titles(): void
    {
        config([
            'cache-architecture.warming.enabled' => true,
            'cache-architecture.page_cache.warming_enabled' => true,
            'cache-architecture.warming.visible_titles.enabled' => true,
        ]);
        $this->withoutDefer();
        Queue::fake();
        $visible = collect([
            $this->visibleTitle('pervyi-pokazannyi'),
            $this->visibleTitle('vtoroi-pokazannyi'),
        ]);
        $hidden = CatalogTitle::factory()->create(['is_published' => false]);

        $this->get(route('titles.index'))->assertOk();

        Queue::assertPushed(WarmCatalogTitlePage::class, 2);
        foreach ($visible as $title) {
            Queue::assertPushed(fn (WarmCatalogTitlePage $job): bool => $job->titleId === $title->id);
        }
        Queue::assertNotPushed(fn (WarmCatalogTitlePage $job): bool => $job->titleId === $hidden->id);
    }

    private function visibleTitle(string $slug): CatalogTitle
    {
        $title = CatalogTitle::factory()->create(['slug' => $slug]);
        LicensedMedia::factory()->create([
            'catalog_title_id' => $title->id,
            'status' => 'published',
            'published_at' => now(),
        ]);

        return $title;
    }
}
