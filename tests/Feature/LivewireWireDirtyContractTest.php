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
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

final class LivewireWireDirtyContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_collection_membership_draft_has_a_targeted_localized_dirty_indicator(): void
    {
        $user = User::factory()->create();
        $title = CatalogTitle::factory()->create();
        CatalogCollection::query()->create([
            'public_id' => (string) Str::uuid(),
            'owner_id' => $user->id,
            'name' => 'Личная подборка',
            'slug' => 'wire-dirty-'.Str::lower(Str::random(8)),
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
            ->assertSeeHtml('wire:dirty')
            ->assertSeeHtml('wire:target="selectedCollectionPublicIds"')
            ->assertSeeText('Есть неприменённые изменения.')
            ->assertSeeHtml('wire:model="selectedCollectionPublicIds"')
            ->assertDontSeeHtml('wire:model.live="selectedCollectionPublicIds"');
    }
}
