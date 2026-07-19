<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\CatalogCollectionModerationStatus;
use App\Enums\CatalogCollectionSort;
use App\Enums\CatalogCollectionType;
use App\Enums\CatalogCollectionVisibility;
use App\Livewire\Collections\CatalogCollectionEditor;
use App\Models\CatalogCollection;
use App\Models\CatalogCollectionItem;
use App\Models\CatalogTitle;
use App\Models\User;
use App\Services\Collections\CatalogCollectionItemService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

final class LivewireWireSortContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_collection_editor_exposes_one_handle_scoped_sortable_list_with_keyboard_fallback(): void
    {
        $markup = collect(File::allFiles(resource_path('views')))
            ->map(fn ($file): string => File::get($file->getPathname()))
            ->implode("\n");
        $editor = File::get(resource_path('views/livewire/collections/catalog-collection-editor.blade.php'));

        $this->assertSame(1, substr_count($markup, 'wire:sort="'));
        $this->assertStringContainsString('<ol wire:sort="sortItem"', $editor);
        $this->assertStringContainsString('wire:sort:item="{{ $item->collection_item_id }}"', $editor);
        $this->assertStringContainsString('wire:sort:handle', $editor);
        $this->assertStringContainsString('wire:sort:ignore', $editor);
        $this->assertStringContainsString('wire:click="moveItem({{ $item->collection_item_id }}, -1)"', $editor);
        $this->assertStringContainsString('wire:click="moveItem({{ $item->collection_item_id }}, 1)"', $editor);
        $this->assertStringNotContainsString('wire:sort:group', $editor);
    }

    public function test_service_moves_an_item_inside_the_authorized_window(): void
    {
        [$owner, $collection, $items] = $this->collectionWithItems(4);

        $changed = app(CatalogCollectionItemService::class)->moveWithinWindow(
            $owner,
            $collection,
            $items[2]->id,
            targetIndex: 0,
            windowStart: 0,
            windowSize: 24,
        );

        $this->assertTrue($changed);
        $this->assertSame(
            [$items[2]->id, $items[0]->id, $items[1]->id, $items[3]->id],
            $collection->items()->pluck('id')->all(),
        );
        $this->assertSame(2, $collection->refresh()->content_version);
    }

    public function test_service_rejects_an_item_outside_the_current_page_window_without_mutation(): void
    {
        [$owner, $collection, $items] = $this->collectionWithItems(26);

        try {
            app(CatalogCollectionItemService::class)->moveWithinWindow(
                $owner,
                $collection,
                $items[24]->id,
                targetIndex: 0,
                windowStart: 0,
                windowSize: 24,
            );
            $this->fail('Expected an invalid cross-window order.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('order', $exception->errors());
        }

        $this->assertSame($items->pluck('id')->all(), $collection->items()->pluck('id')->all());
        $this->assertSame(1, $collection->refresh()->content_version);
    }

    public function test_component_translates_the_second_page_position_into_its_absolute_window(): void
    {
        [$owner, $collection, $items] = $this->collectionWithItems(26);
        $this->actingAs($owner);

        Livewire::withQueryParams(['collectionPage' => 2])
            ->test(CatalogCollectionEditor::class, ['collectionPublicId' => $collection->public_id])
            ->assertSet('paginators.collectionPage', 2)
            ->call('sortItem', $items[25]->id, 0)
            ->assertHasNoErrors()
            ->assertSet('status', __('collections.status.order_updated'));

        $this->assertSame(
            [...$items->take(24)->pluck('id')->all(), $items[25]->id, $items[24]->id],
            $collection->items()->pluck('id')->all(),
        );
    }

    /** @return array{User, CatalogCollection, Collection<int, CatalogCollectionItem>} */
    private function collectionWithItems(int $count): array
    {
        $owner = User::factory()->create();
        $collection = CatalogCollection::query()->create([
            'public_id' => (string) Str::uuid(),
            'owner_id' => $owner->id,
            'name' => 'Ручная подборка',
            'slug' => 'manual-'.Str::lower(Str::random(12)),
            'type' => CatalogCollectionType::User,
            'visibility' => CatalogCollectionVisibility::Private,
            'moderation_status' => CatalogCollectionModerationStatus::Approved,
            'sort_mode' => CatalogCollectionSort::Manual,
            'content_locale' => 'ru',
        ]);
        $titles = CatalogTitle::factory()->count($count)->create();
        $items = $titles->values()->map(fn (CatalogTitle $title, int $index): CatalogCollectionItem => CatalogCollectionItem::query()->create([
            'catalog_collection_id' => $collection->id,
            'catalog_title_id' => $title->id,
            'added_by_id' => $owner->id,
            'position' => $index + 1,
        ]));

        return [$owner, $collection, $items];
    }
}
