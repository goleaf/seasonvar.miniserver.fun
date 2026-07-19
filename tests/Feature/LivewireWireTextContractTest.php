<?php

namespace Tests\Feature;

use App\Enums\CatalogCollectionModerationStatus;
use App\Enums\CatalogCollectionSort;
use App\Enums\CatalogCollectionType;
use App\Enums\CatalogCollectionVisibility;
use App\Livewire\Collections\CatalogCollectionMembershipManager;
use App\Models\CatalogCollection;
use App\Models\CatalogTitle;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

final class LivewireWireTextContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_collection_membership_counter_uses_local_wire_text_with_server_fallback(): void
    {
        $user = User::factory()->create();
        $title = CatalogTitle::factory()->create();
        CatalogCollection::query()->create([
            'public_id' => (string) Str::uuid(),
            'owner_id' => $user->id,
            'name' => 'Личная подборка',
            'slug' => 'wire-text-'.Str::lower(Str::random(8)),
            'type' => CatalogCollectionType::User,
            'visibility' => CatalogCollectionVisibility::Private,
            'moderation_status' => CatalogCollectionModerationStatus::Approved,
            'sort_mode' => CatalogCollectionSort::Manual,
            'content_locale' => null,
            'is_featured' => false,
        ]);

        Livewire::actingAs($user)
            ->test(CatalogCollectionMembershipManager::class, ['catalogTitleId' => $title->id])
            ->call('openSelector')
            ->assertSeeText('Выбрано: 0')
            ->assertSeeHtml('wire:model="selectedCollectionPublicIds"')
            ->assertSeeHtml('wire:text=')
            ->assertSeeHtml('selectedCollectionPublicIds.length')
            ->assertDontSeeHtml('wire:model.live="selectedCollectionPublicIds"');
    }

    public function test_wire_text_inventory_is_limited_to_the_local_collection_counter(): void
    {
        $markup = collect(File::allFiles(resource_path('views')))
            ->map(fn ($file): string => File::get($file->getPathname()))
            ->implode("\n");
        $membership = File::get(resource_path('views/livewire/collections/catalog-collection-membership-manager.blade.php'));

        $this->assertSame(1, substr_count($markup, 'wire:text='));
        $this->assertDoesNotMatchRegularExpression('/wire:text\.[^=\s]+/', $markup);
        $this->assertStringContainsString('selectedCollectionPublicIds.length', $membership);
        $this->assertStringContainsString('{{ $selectedCountLabel }}', $membership);
        $this->assertStringContainsString('wire:model="selectedCollectionPublicIds"', $membership);
        $this->assertStringNotContainsString('wire:model.live="selectedCollectionPublicIds"', $membership);
    }
}
