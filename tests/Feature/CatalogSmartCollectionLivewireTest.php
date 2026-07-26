<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\DTOs\CatalogCollectionData;
use App\DTOs\CatalogSmartCollectionRules;
use App\Enums\CatalogCollectionMode;
use App\Enums\CatalogCollectionVisibility;
use App\Enums\CatalogSmartCollectionPreset;
use App\Livewire\Collections\CatalogCollectionDashboard;
use App\Livewire\Collections\CatalogCollectionEditor;
use App\Livewire\Collections\CatalogCollectionPage;
use App\Models\Actor;
use App\Models\CatalogCollection;
use App\Models\CatalogTitle;
use App\Models\Genre;
use App\Models\User;
use App\Services\Collections\CatalogCollectionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

final class CatalogSmartCollectionLivewireTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_creates_smart_collection_from_preset_and_forces_private_mode(): void
    {
        $owner = User::factory()->create(['email_verified_at' => now()]);

        Livewire::actingAs($owner)
            ->test(CatalogCollectionDashboard::class)
            ->set('showCreate', true)
            ->set('name', 'Моя библиотека с новыми сериями')
            ->set('mode', CatalogCollectionMode::Smart->value)
            ->set('visibility', CatalogCollectionVisibility::Public->value)
            ->set('smartPreset', CatalogSmartCollectionPreset::LibraryNewEpisodes->value)
            ->call('create')
            ->assertHasNoErrors();

        $collection = CatalogCollection::query()
            ->where('name', 'Моя библиотека с новыми сериями')
            ->sole();

        $this->assertTrue($collection->isSmart());
        $this->assertSame(CatalogCollectionVisibility::Private, $collection->visibility);
        $this->assertNull($collection->catalog_collection_category_id);
        $this->assertTrue((bool) $collection->smart_rules['in_library']);
        $this->assertTrue((bool) $collection->smart_rules['has_new_episodes']);
    }

    public function test_editor_applies_preset_validates_and_atomically_saves_custom_rules(): void
    {
        $owner = User::factory()->create(['email_verified_at' => now()]);
        $collection = $this->collection($owner);
        Genre::query()->create(['name' => 'Комедия', 'slug' => 'komediia']);
        Actor::query()->create(['name' => 'Любимый актёр', 'slug' => 'favorite-actor']);

        Livewire::actingAs($owner)
            ->test(CatalogCollectionEditor::class, ['collectionPublicId' => $collection->public_id])
            ->assertSet('mode', CatalogCollectionMode::Smart->value)
            ->assertSeeText(__('collections.smart.editor.title'))
            ->call('applySmartPreset', CatalogSmartCollectionPreset::ShortCompletedComedies->value)
            ->assertSet('genreSlug', 'komediia')
            ->assertSet('completion', 'completed')
            ->assertSet('episodesMax', '8')
            ->set('actorSearch', 'Любимый')
            ->assertSeeText('Любимый актёр')
            ->set('actorSlug', 'favorite-actor')
            ->set('imdbMin', '8,2')
            ->call('save')
            ->assertHasNoErrors();

        $collection->refresh();
        $this->assertSame('favorite-actor', $collection->smart_rules['actor_slug']);
        $this->assertEquals(8.2, $collection->smart_rules['imdb_min']);
        $this->assertSame('completed', $collection->smart_rules['completion']);
        $this->assertGreaterThan(1, $collection->content_version);
    }

    public function test_smart_detail_is_owner_only_and_has_no_manual_membership_controls(): void
    {
        $owner = User::factory()->create();
        $collection = $this->collection($owner);
        $title = CatalogTitle::factory()->create(['title' => 'Комедийное совпадение']);
        $genre = Genre::query()->create(['name' => 'Комедия', 'slug' => 'komediia']);
        $title->genres()->attach($genre);

        $component = Livewire::actingAs($owner)
            ->test(CatalogCollectionPage::class, ['collectionSlug' => $collection->slug])
            ->assertSeeText('Комедийное совпадение')
            ->assertSeeText(__('collections.smart.page.dynamic_notice'))
            ->assertDontSeeText(__('collections.actions.remove'));

        $this->assertSame(
            1,
            substr_count(strip_tags($component->html()), __('collections.smart.badge')),
            'Smart mode must have one mode badge; the category fallback must remain uncategorized.',
        );

        Livewire::actingAs(User::factory()->create())
            ->test(CatalogCollectionPage::class, ['collectionSlug' => $collection->slug])
            ->assertNotFound();

        auth()->logout();
        $this->get(route('collections.show', ['collectionSlug' => $collection->slug]))
            ->assertNotFound();
    }

    public function test_editor_rejects_empty_and_out_of_range_rules_with_russian_messages(): void
    {
        $owner = User::factory()->create(['email_verified_at' => now()]);
        $collection = $this->collection($owner);

        Livewire::actingAs($owner)
            ->test(CatalogCollectionEditor::class, ['collectionPublicId' => $collection->public_id])
            ->set('genreSlug', '')
            ->call('save')
            ->assertHasErrors(['rules'])
            ->assertSeeText(__('collections.smart.validation.rules'))
            ->set('genreSlug', 'komediia')
            ->set('imdbMin', '10,1')
            ->call('save')
            ->assertHasErrors(['imdb_min'])
            ->assertSeeText(__('collections.smart.validation.imdb_min'));
    }

    private function collection(User $owner): CatalogCollection
    {
        return app(CatalogCollectionService::class)->create(
            $owner,
            new CatalogCollectionData(
                name: 'Умные комедии',
                description: null,
                visibility: CatalogCollectionVisibility::Private,
                mode: CatalogCollectionMode::Smart,
                smartRules: CatalogSmartCollectionRules::fromInput(['genre_slug' => 'komediia']),
            ),
        );
    }
}
