<?php

namespace Tests\Unit;

use App\Models\Actor;
use App\Models\CatalogTitle;
use App\Models\CatalogTitleAlias;
use App\Models\Director;
use App\Models\Genre;
use App\Services\Catalog\CatalogTaxonomyRegistry;
use App\Services\Catalog\Search\CatalogSearchDocumentBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CatalogSearchDocumentBuilderTest extends TestCase
{
    use RefreshDatabase;

    public function test_builder_emits_weighted_safe_text_normalized_keys_and_transliteration(): void
    {
        $title = CatalogTitle::factory()->create([
            'title' => 'Знахарь',
            'original_title' => 'Znachor',
            'description' => 'История врача.',
            'source_url' => 'https://seasonvar.ru/private-source',
            'external_id' => 'secret-provider-id',
        ]);
        CatalogTitleAlias::query()->create([
            'catalog_title_id' => $title->id,
            'name' => 'Лекарь',
            'name_hash' => hash('sha256', 'лекарь'),
            'type' => 'alternative',
            'source' => 'seasonvar',
        ]);
        $actor = Actor::query()->create(['name' => 'Фёдор Лавров', 'slug' => 'fedor-lavrov']);
        $director = Director::query()->create(['name' => 'Анна Волкова', 'slug' => 'anna-volkova']);
        $genre = Genre::query()->create(['name' => 'Драма', 'slug' => 'drama']);
        $title->actors()->attach($actor);
        $title->directors()->attach($director);
        $title->genres()->attach($genre);
        $title->load(['aliases', ...app(CatalogTaxonomyRegistry::class)->relationNames()]);

        $document = app(CatalogSearchDocumentBuilder::class)->build($title);
        $serialized = json_encode($document, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        $this->assertSame($title->id, $document['catalog_title_id']);
        $this->assertSame('Знахарь', $document['title']);
        $this->assertSame('Znachor', $document['original_title']);
        $this->assertStringContainsString('Лекарь', $document['aliases']);
        $this->assertStringContainsString('Фёдор Лавров', $document['people']);
        $this->assertStringContainsString('Федор Лавров', $document['people']);
        $this->assertStringContainsString('Анна Волкова', $document['people']);
        $this->assertStringContainsString('Драма', $document['taxonomies']);
        $this->assertStringContainsString('znakhar', $document['transliteration']);
        $this->assertSame('знахарь', $document['normalized_title_key']);
        $this->assertStringContainsString('лекарь', $document['normalized_alias_keys']);
        $this->assertSame(64, strlen($document['fingerprint']));
        $this->assertStringNotContainsString('seasonvar.ru', $serialized);
        $this->assertStringNotContainsString('secret-provider-id', $serialized);
        $this->assertStringNotContainsString('source_url', $serialized);
    }

    public function test_fingerprint_is_stable_for_order_and_timestamps_but_changes_with_searchable_text(): void
    {
        $title = CatalogTitle::factory()->create(['title' => 'Стабильный документ']);
        CatalogTitleAlias::query()->create([
            'catalog_title_id' => $title->id,
            'name' => 'Бета',
            'name_hash' => hash('sha256', 'бета'),
            'type' => 'alternative',
            'source' => 'seasonvar',
        ]);
        CatalogTitleAlias::query()->create([
            'catalog_title_id' => $title->id,
            'name' => 'Альфа',
            'name_hash' => hash('sha256', 'альфа'),
            'type' => 'alternative',
            'source' => 'seasonvar',
        ]);
        $relations = ['aliases', ...app(CatalogTaxonomyRegistry::class)->relationNames()];
        $builder = app(CatalogSearchDocumentBuilder::class);
        $first = $builder->build($title->fresh()->load($relations));

        $title->touch();
        $second = $builder->build($title->fresh()->load($relations));

        $this->assertSame($first['fingerprint'], $second['fingerprint']);
        $this->assertSame("Альфа\nБета", $second['aliases']);

        $title->aliases()->where('name', 'Бета')->update(['name' => 'Гамма']);
        $third = $builder->build($title->fresh()->load($relations));

        $this->assertNotSame($second['fingerprint'], $third['fingerprint']);
    }
}
