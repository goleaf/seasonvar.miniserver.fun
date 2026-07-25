<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\CatalogCollectionModerationStatus;
use App\Enums\CatalogCollectionVisibility;
use App\Livewire\Collections\CatalogCollectionDashboard;
use App\Livewire\Collections\CatalogCollectionEditor;
use App\Models\CatalogCollection;
use App\Models\CatalogCollectionCategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

final class CatalogCollectionCategoryEditorTest extends TestCase
{
    use RefreshDatabase;

    public function test_editor_hydrates_dependent_category_controls_and_saves_selected_child(): void
    {
        $owner = User::factory()->create(['email_verified_at' => now()]);
        $current = CatalogCollectionCategory::query()->where('slug', 'detective-and-crime')->firstOrFail();
        $next = CatalogCollectionCategory::query()->with('parent')->where('slug', 'weekend')->firstOrFail();
        $collection = $this->collection($owner, $current);

        Livewire::actingAs($owner)
            ->test(CatalogCollectionEditor::class, ['collectionPublicId' => $collection->public_id])
            ->assertSet('categoryRootPublicId', $current->parent?->public_id)
            ->assertSet('categoryPublicId', $current->public_id)
            ->assertSeeText('Темы и жанры')
            ->assertSeeText('Детективы и криминал')
            ->set('categoryRootPublicId', $next->parent?->public_id)
            ->assertSet('categoryPublicId', '')
            ->set('categoryPublicId', $next->public_id)
            ->set('visibility', CatalogCollectionVisibility::Public->value)
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('categoryRootPublicId', $next->parent?->public_id)
            ->assertSet('categoryPublicId', $next->public_id);

        $this->assertSame($next->id, $collection->refresh()->catalog_collection_category_id);
        $this->assertSame(CatalogCollectionModerationStatus::Pending, $collection->moderation_status);
    }

    public function test_dashboard_requires_category_for_public_creation_and_accepts_active_selection(): void
    {
        $owner = User::factory()->create(['email_verified_at' => now()]);
        $category = CatalogCollectionCategory::query()->with('parent')->where('slug', 'mini-series')->firstOrFail();

        $component = Livewire::actingAs($owner)
            ->test(CatalogCollectionDashboard::class)
            ->set('showCreate', true)
            ->set('name', 'Новая публичная подборка')
            ->set('visibility', CatalogCollectionVisibility::Public->value)
            ->call('create')
            ->assertHasErrors(['categoryPublicId'])
            ->set('categoryRootPublicId', $category->parent?->public_id)
            ->set('categoryPublicId', $category->public_id)
            ->call('create')
            ->assertHasNoErrors();

        $created = CatalogCollection::query()->where('name', 'Новая публичная подборка')->sole();

        $this->assertSame($category->id, $created->catalog_collection_category_id);
        $component->assertRedirect(route('collections.edit', ['collectionPublicId' => $created->public_id]));
    }

    public function test_editor_shows_archived_current_assignment_but_requires_replacement_on_save(): void
    {
        $owner = User::factory()->create(['email_verified_at' => now()]);
        $category = CatalogCollectionCategory::query()->with('parent')->where('slug', 'history')->firstOrFail();
        $collection = $this->collection($owner, $category);
        $category->forceFill(['is_active' => false])->save();

        Livewire::actingAs($owner)
            ->test(CatalogCollectionEditor::class, ['collectionPublicId' => $collection->public_id])
            ->assertSet('categoryRootPublicId', $category->parent?->public_id)
            ->assertSet('categoryPublicId', $category->public_id)
            ->assertSeeText(__('collections.categories.archived_assignment'))
            ->call('save')
            ->assertHasErrors(['categoryPublicId']);
    }

    private function collection(User $owner, CatalogCollectionCategory $category): CatalogCollection
    {
        return CatalogCollection::query()->create([
            'public_id' => (string) Str::uuid(),
            'owner_id' => $owner->id,
            'catalog_collection_category_id' => $category->id,
            'name' => 'Подборка владельца',
            'slug' => 'owner-collection-'.Str::lower(Str::random(8)),
            'visibility' => CatalogCollectionVisibility::Private,
            'moderation_status' => CatalogCollectionModerationStatus::Approved,
        ]);
    }
}
