<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\CatalogCollectionModerationStatus;
use App\Enums\CatalogCollectionVisibility;
use App\Models\CatalogCollection;
use App\Models\CatalogCollectionCategory;
use App\Models\CatalogCollectionItem;
use App\Models\CatalogTitle;
use App\Models\User;
use App\Services\Collections\CatalogCollectionAccountService;
use App\Services\Collections\CatalogCollectionQuery;
use App\Services\Collections\CatalogCollectionSeoPresenter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class CatalogCollectionExternalContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_api_keeps_null_deprecated_cover_and_adds_public_category_shape(): void
    {
        [$owner, $collection] = $this->collectionWithItem();

        $response = $this->getJson(route('api.v1.collections.show', [
            'collectionSlug' => $collection->slug,
        ]))->assertOk();

        $response
            ->assertJsonPath('collection.cover_url', null)
            ->assertJsonPath('collection.category.slug', 'detective-and-crime')
            ->assertJsonPath('collection.category.name', 'Детективы и криминал')
            ->assertJsonPath('collection.category.parent.slug', 'themes-and-genres')
            ->assertJsonPath('collection.category.parent.name', 'Темы и жанры')
            ->assertJsonMissingPath('collection.category.id')
            ->assertJsonMissingPath('collection.category.parent.id');

        $this->assertSame($owner->public_id, $response->json('collection.owner.id'));
    }

    public function test_collection_seo_and_sitemap_publish_no_collection_image(): void
    {
        [, $collection] = $this->collectionWithItem();
        $summary = app(CatalogCollectionQuery::class)->summary($collection);
        $seo = app(CatalogCollectionSeoPresenter::class)->collection($summary, null);

        $this->assertArrayNotHasKey('image', $seo);
        $this->assertArrayNotHasKey('image_alt', $seo);
        $this->assertArrayNotHasKey('image', $seo['jsonLd'][0]);

        $response = $this->get(route('sitemap.collections'))->assertOk();
        $xml = $response->streamedContent();

        $this->assertStringContainsString(
            route('collections.show', ['collectionSlug' => $collection->slug]),
            $xml,
        );
        $this->assertStringNotContainsString('xmlns:image=', $xml);
        $this->assertStringNotContainsString('<image:', $xml);
        $this->assertStringNotContainsString('/collections/covers/', $xml);
    }

    public function test_account_export_contains_stable_category_without_cover_metadata(): void
    {
        [$owner] = $this->collectionWithItem();
        $export = app(CatalogCollectionAccountService::class)->export($owner);
        $collection = $export[0];

        $this->assertSame('detective-and-crime', $collection['category']['slug']);
        $this->assertSame('Детективы и криминал', $collection['category']['name']);
        $this->assertSame('themes-and-genres', $collection['category']['parent']['slug']);
        $this->assertArrayNotHasKey('id', $collection['category']);
        $this->assertArrayNotHasKey('cover_path', $collection);
        $this->assertArrayNotHasKey('cover_url', $collection);
    }

    public function test_openapi_marks_cover_as_deprecated_and_documents_category(): void
    {
        $document = json_decode(
            (string) file_get_contents(resource_path('api/openapi.json')),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        $schema = $document['components']['schemas']['PublicCollection'];

        $this->assertTrue($schema['properties']['cover_url']['deprecated']);
        $this->assertContains('null', $schema['properties']['cover_url']['type']);
        $this->assertArrayHasKey('category', $schema['properties']);
    }

    /** @return array{User, CatalogCollection} */
    private function collectionWithItem(): array
    {
        $owner = User::factory()->create();
        $category = CatalogCollectionCategory::query()
            ->where('slug', 'detective-and-crime')
            ->firstOrFail();
        $collection = CatalogCollection::query()->create([
            'public_id' => (string) Str::uuid(),
            'owner_id' => $owner->id,
            'catalog_collection_category_id' => $category->id,
            'name' => 'Детективная подборка',
            'slug' => 'detektivnaya-podborka-'.Str::lower(Str::random(6)),
            'visibility' => CatalogCollectionVisibility::Public,
            'moderation_status' => CatalogCollectionModerationStatus::Approved,
            'published_at' => now(),
        ]);
        $title = CatalogTitle::factory()->create();
        CatalogCollectionItem::query()->create([
            'catalog_collection_id' => $collection->id,
            'catalog_title_id' => $title->id,
            'position' => 1,
        ]);

        return [$owner, $collection];
    }
}
