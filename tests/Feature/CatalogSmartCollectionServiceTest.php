<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\DTOs\CatalogCollectionData;
use App\DTOs\CatalogSmartCollectionRules;
use App\Enums\CatalogCollectionMode;
use App\Enums\CatalogCollectionSort;
use App\Enums\CatalogCollectionType;
use App\Enums\CatalogCollectionVisibility;
use App\Enums\CatalogSmartCollectionPreset;
use App\Models\CatalogCollection;
use App\Models\CatalogCollectionItem;
use App\Models\CatalogTitle;
use App\Models\User;
use App\Services\Collections\CatalogCollectionItemService;
use App\Services\Collections\CatalogCollectionService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class CatalogSmartCollectionServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_creates_private_user_smart_collection_from_normalized_rules(): void
    {
        $owner = User::factory()->create();
        $rules = CatalogSmartCollectionPreset::NewKoreanThrillers->rules();

        $collection = app(CatalogCollectionService::class)->create(
            $owner,
            new CatalogCollectionData(
                name: 'Новые корейские триллеры',
                description: null,
                visibility: CatalogCollectionVisibility::Private,
                mode: CatalogCollectionMode::Smart,
                smartRules: $rules,
            ),
        );

        $this->assertSame(CatalogCollectionMode::Smart, $collection->mode);
        $this->assertSame(CatalogCollectionType::User, $collection->type);
        $this->assertSame(CatalogCollectionVisibility::Private, $collection->visibility);
        $this->assertNull($collection->catalog_collection_category_id);
        $this->assertEquals($rules->toArray(), $collection->smart_rules);
        $this->assertSame(1, $collection->smart_rules_version);
    }

    public function test_smart_collection_rejects_public_editorial_or_empty_rules(): void
    {
        $owner = User::factory()->create();

        foreach ([
            ['visibility' => CatalogCollectionVisibility::Public, 'type' => CatalogCollectionType::User],
            ['visibility' => CatalogCollectionVisibility::Private, 'type' => CatalogCollectionType::Editorial],
        ] as $attributes) {
            try {
                app(CatalogCollectionService::class)->create(
                    $owner,
                    new CatalogCollectionData(
                        name: 'Неверная умная подборка',
                        description: null,
                        visibility: $attributes['visibility'],
                        type: $attributes['type'],
                        mode: CatalogCollectionMode::Smart,
                        smartRules: CatalogSmartCollectionRules::fromInput(['genre_slug' => 'trillery']),
                    ),
                );
                $this->fail('Ожидалась ошибка границы умной подборки.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('mode', $exception->errors());
            }
        }
    }

    public function test_owner_atomically_replaces_rules_and_stale_write_is_rejected(): void
    {
        $owner = User::factory()->create();
        $collection = $this->smartCollection($owner);
        $originalVersion = $collection->content_version;
        $replacement = CatalogSmartCollectionRules::fromInput([
            'genre_slug' => 'komediia',
            'completion' => 'completed',
        ]);

        $updated = app(CatalogCollectionService::class)->update(
            $owner,
            $collection,
            $this->updateData($collection, $replacement),
            $originalVersion,
        );

        $this->assertSame($replacement->toArray(), $updated->smart_rules);
        $this->assertSame($originalVersion + 1, $updated->content_version);

        try {
            app(CatalogCollectionService::class)->update(
                $owner,
                $updated,
                $this->updateData(
                    $updated,
                    CatalogSmartCollectionRules::fromInput(['country_slug' => 'iuznaia-koreia']),
                ),
                $originalVersion,
            );
            $this->fail('Ожидалась ошибка устаревшей версии.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('form', $exception->errors());
        }
    }

    public function test_other_user_cannot_update_smart_rules(): void
    {
        $this->expectException(AuthorizationException::class);

        $owner = User::factory()->create();
        $collection = $this->smartCollection($owner);

        app(CatalogCollectionService::class)->update(
            User::factory()->create(),
            $collection,
            $this->updateData(
                $collection,
                CatalogSmartCollectionRules::fromInput(['genre_slug' => 'komediia']),
            ),
            $collection->content_version,
        );
    }

    public function test_manual_item_mutations_are_rejected_for_smart_collection(): void
    {
        $owner = User::factory()->create();
        $collection = $this->smartCollection($owner);

        try {
            app(CatalogCollectionItemService::class)->add(
                $owner,
                $collection,
                CatalogTitle::factory()->create(),
            );
            $this->fail('Ожидался запрет ручного состава smart-подборки.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('collection', $exception->errors());
        }
    }

    public function test_smart_mode_is_immutable_and_reorder_paths_reject_tampered_items(): void
    {
        $owner = User::factory()->create();
        $collection = $this->smartCollection($owner);
        $title = CatalogTitle::factory()->create();
        $item = CatalogCollectionItem::query()->create([
            'catalog_collection_id' => $collection->id,
            'catalog_title_id' => $title->id,
            'added_by_id' => $owner->id,
            'position' => 1,
        ]);

        try {
            app(CatalogCollectionService::class)->update(
                $owner,
                $collection,
                new CatalogCollectionData(
                    name: $collection->name,
                    description: $collection->description,
                    visibility: $collection->visibility,
                    sortMode: $collection->sort_mode,
                    type: $collection->type,
                    mode: CatalogCollectionMode::Manual,
                ),
                $collection->content_version,
            );
            $this->fail('Ожидался запрет смены режима подборки.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('mode', $exception->errors());
        }

        try {
            app(CatalogCollectionItemService::class)->move($owner, $collection, $item->id, 1);
            $this->fail('Ожидался запрет сортировки smart-подборки.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('collection', $exception->errors());
        }
    }

    private function smartCollection(User $owner): CatalogCollection
    {
        return app(CatalogCollectionService::class)->create(
            $owner,
            new CatalogCollectionData(
                name: 'Умная подборка',
                description: null,
                visibility: CatalogCollectionVisibility::Private,
                mode: CatalogCollectionMode::Smart,
                smartRules: CatalogSmartCollectionRules::fromInput(['genre_slug' => 'trillery']),
            ),
        );
    }

    private function updateData(
        CatalogCollection $collection,
        CatalogSmartCollectionRules $rules,
    ): CatalogCollectionData {
        return new CatalogCollectionData(
            name: $collection->name,
            description: $collection->description,
            visibility: $collection->visibility,
            sortMode: $collection->sort_mode ?? CatalogCollectionSort::RecentlyUpdated,
            type: $collection->type,
            contentLocale: $collection->content_locale,
            mode: $collection->mode,
            smartRules: $rules,
        );
    }
}
