<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\CatalogTitle;
use App\Models\Tag;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class LegacyTagSchemaCompatibilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_canonical_tag_taxonomy_page_renders_on_a_cold_request(): void
    {
        config(['cache-architecture.page_cache.enabled' => false]);

        $title = CatalogTitle::factory()->create([
            'title' => 'Сериал с каноническим тегом',
            'slug' => 'serial-s-kanonicheskim-tegom',
        ]);
        $tag = Tag::query()->create([
            'name' => 'Канонический тег',
            'slug' => 'kanonicheskii-teg',
        ]);
        $relatedTag = Tag::query()->create([
            'name' => 'Связанный тег',
            'slug' => 'sviazannyi-teg',
        ]);
        $title->tags()->attach([$tag->id, $relatedTag->id]);

        $this->get(route('titles.taxonomy', [
            'type' => 'tag',
            'taxonomy' => $tag->slug,
        ]))
            ->assertOk()
            ->assertSeeText($title->title)
            ->assertSeeText($tag->name)
            ->assertSeeText($relatedTag->name);
    }

    public function test_canonical_tag_schema_is_inspected_once_per_application_lifecycle(): void
    {
        config(['cache-architecture.page_cache.enabled' => false]);
        config(['tags.canonical_schema' => null]);

        $title = CatalogTitle::factory()->create([
            'title' => 'Сериал с одной проверкой схемы',
            'slug' => 'serial-s-odnoi-proverkoi-skhemy',
        ]);
        $schemaInspections = 0;

        DB::listen(function (QueryExecuted $query) use (&$schemaInspections): void {
            if (str_contains($query->sql, "name = 'tag_translations'")) {
                $schemaInspections++;
            }
        });

        $this->get(route('titles.show', $title))->assertOk();
        $this->get(route('titles.show', $title))->assertOk();

        $this->assertSame(1, $schemaInspections);
    }

    public function test_public_catalog_pages_render_before_the_canonical_tag_schema_is_available(): void
    {
        $title = CatalogTitle::factory()->create([
            'title' => 'Совместимый сериал',
            'slug' => 'sovmestimyi-serial',
        ]);
        $tag = Tag::query()->create([
            'name' => 'Совместимый тег',
            'slug' => 'sovmestimyi-teg',
        ]);
        $title->tags()->attach($tag);

        Schema::drop('tag_translations');
        config(['tags.canonical_schema' => false]);

        foreach ([
            route('home'),
            route('titles.index'),
            route('tags.index'),
            route('titles.show', $title),
        ] as $url) {
            $this->get($url)->assertOk();
        }

        $this->get(route('tags.show', $tag->slug))
            ->assertMovedPermanently()
            ->assertRedirect(route('titles.taxonomy', [
                'type' => 'tag',
                'taxonomy' => $tag->slug,
            ]));

        $this->get(route('titles.taxonomy', [
            'type' => 'tag',
            'taxonomy' => $tag->slug,
        ]))->assertOk();
    }
}
