<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\CatalogCollection;
use App\Models\CatalogCollectionCategory;
use App\Services\Collections\CatalogCollectionCategoryDefaults;
use App\Support\DeterministicUuid;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class CatalogCollectionCategoryDefaultsTest extends TestCase
{
    use RefreshDatabase;

    public function test_default_taxonomy_is_bilingual_deterministic_and_does_not_classify_collections(): void
    {
        CatalogCollection::query()->create([
            'public_id' => '2d398f5e-45ed-41cf-bccf-458fa59cd17b',
            'name' => 'Подборка без категории',
            'slug' => 'uncategorized-collection',
        ]);

        $this->assertSame(36, CatalogCollectionCategory::query()->count());
        $this->assertSame(5, CatalogCollectionCategory::query()->whereNull('parent_id')->count());
        $this->assertSame(0, CatalogCollection::query()->whereNotNull('catalog_collection_category_id')->count());

        $root = CatalogCollectionCategory::query()
            ->with('translations')
            ->where('slug', 'themes-and-genres')
            ->firstOrFail();

        $this->assertSame(
            DeterministicUuid::from('seasonvar.catalog-collection-category', 'themes-and-genres'),
            $root->public_id,
        );
        $this->assertSame('Темы и жанры', $root->translations->firstWhere('locale', 'ru')?->name);
        $this->assertSame('Themes and genres', $root->translations->firstWhere('locale', 'en')?->name);

        $child = CatalogCollectionCategory::query()
            ->with(['parent', 'translations'])
            ->where('slug', 'detective-and-crime')
            ->firstOrFail();

        $this->assertSame($root->id, $child->parent_id);
        $this->assertSame('Детективы и криминал', $child->translations->firstWhere('locale', 'ru')?->name);
        $this->assertSame('Detective and crime', $child->translations->firstWhere('locale', 'en')?->name);
    }

    public function test_repeated_install_is_idempotent_and_preserves_administrator_edits(): void
    {
        $translation = CatalogCollectionCategory::query()
            ->where('slug', 'themes-and-genres')
            ->firstOrFail()
            ->translations()
            ->where('locale', 'ru')
            ->firstOrFail();
        $translation->forceFill(['name' => 'Отредактированное название'])->save();

        $defaults = app(CatalogCollectionCategoryDefaults::class);
        $defaults->install();
        $defaults->install();

        $this->assertSame(36, CatalogCollectionCategory::query()->count());
        $this->assertSame(
            'Отредактированное название',
            $translation->fresh()?->name,
        );
        $this->assertSame(
            72,
            CatalogCollectionCategory::query()
                ->withCount('translations')
                ->get()
                ->sum('translations_count'),
        );
    }
}
