<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\DTOs\CatalogCollectionData;
use App\Enums\CatalogCollectionModerationStatus;
use App\Enums\CatalogCollectionVisibility;
use App\Models\CatalogCollectionCategory;
use App\Models\User;
use App\Services\Collections\CatalogCollectionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class CatalogCollectionCategoryServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_verified_owner_can_create_public_collection_with_active_child_category(): void
    {
        $owner = User::factory()->create(['email_verified_at' => now()]);
        $category = CatalogCollectionCategory::query()
            ->where('slug', 'detective-and-crime')
            ->firstOrFail();

        $collection = app(CatalogCollectionService::class)->create(
            $owner,
            new CatalogCollectionData(
                name: 'Детективы на выходные',
                description: 'Подборка',
                visibility: CatalogCollectionVisibility::Public,
                categoryPublicId: $category->public_id,
            ),
        );

        $this->assertSame($category->id, $collection->catalog_collection_category_id);
        $this->assertSame(CatalogCollectionModerationStatus::Pending, $collection->moderation_status);
    }

    public function test_owner_public_and_unlisted_writes_require_active_category(): void
    {
        $owner = User::factory()->create(['email_verified_at' => now()]);
        $service = app(CatalogCollectionService::class);

        foreach ([CatalogCollectionVisibility::Public, CatalogCollectionVisibility::Unlisted] as $visibility) {
            try {
                $service->create(
                    $owner,
                    new CatalogCollectionData(
                        name: 'Подборка '.$visibility->value,
                        description: null,
                        visibility: $visibility,
                    ),
                );
                $this->fail('Ожидалась ошибка обязательной категории.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('categoryPublicId', $exception->errors());
            }
        }

        $inactive = CatalogCollectionCategory::query()
            ->where('slug', 'history')
            ->firstOrFail();
        $inactive->forceFill(['is_active' => false])->save();

        try {
            $service->create(
                $owner,
                new CatalogCollectionData(
                    name: 'Архивная категория',
                    description: null,
                    visibility: CatalogCollectionVisibility::Public,
                    categoryPublicId: $inactive->public_id,
                ),
            );
            $this->fail('Ожидалась ошибка архивной категории.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('categoryPublicId', $exception->errors());
        }
    }

    public function test_private_collection_may_remain_uncategorized_and_public_update_persists_category(): void
    {
        $owner = User::factory()->create(['email_verified_at' => now()]);
        $service = app(CatalogCollectionService::class);
        $collection = $service->create(
            $owner,
            new CatalogCollectionData(
                name: 'Личная подборка',
                description: null,
                visibility: CatalogCollectionVisibility::Private,
            ),
        );
        $category = CatalogCollectionCategory::query()
            ->where('slug', 'weekend')
            ->firstOrFail();

        $this->assertNull($collection->catalog_collection_category_id);

        $updated = $service->update(
            $owner,
            $collection,
            new CatalogCollectionData(
                name: $collection->name,
                description: $collection->description,
                visibility: CatalogCollectionVisibility::Public,
                sortMode: $collection->sort_mode,
                categoryPublicId: $category->public_id,
            ),
            $collection->content_version,
        );

        $this->assertSame($category->id, $updated->catalog_collection_category_id);
        $this->assertSame(CatalogCollectionModerationStatus::Pending, $updated->moderation_status);
        $this->assertSame($collection->content_version + 1, $updated->content_version);
    }
}
