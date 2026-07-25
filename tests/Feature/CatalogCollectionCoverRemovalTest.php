<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\CatalogCollectionModerationStatus;
use App\Enums\CatalogCollectionVisibility;
use App\Livewire\Collections\CatalogCollectionEditor;
use App\Models\CatalogCollection;
use App\Models\CatalogCollectionSource;
use App\Models\User;
use App\Services\Collections\CatalogCollectionAccountService;
use App\Services\Collections\Import\HdRezkaCollectionParser;
use App\Services\Collections\Import\HdRezkaCollectionSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;
use ReflectionMethod;
use Tests\TestCase;

final class CatalogCollectionCoverRemovalTest extends TestCase
{
    use RefreshDatabase;

    public function test_legacy_collection_cover_route_and_runtime_services_are_absent(): void
    {
        $publicId = (string) Str::uuid();

        $this->assertNull(Route::getRoutes()->getByName('collections.cover'));
        $this->get("/collections/covers/{$publicId}/1")->assertNotFound();
        $this->assertFalse(File::exists(app_path('Services/Collections/CatalogCollectionCoverResponder.php')));
        $this->assertFalse(File::exists(app_path('Services/Collections/CatalogCollectionCoverService.php')));
        $this->assertFalse(File::exists(app_path('Services/Collections/Import/HdRezkaCollectionCoverImporter.php')));
        $this->assertFalse(File::exists(app_path('DTOs/PreparedImportedCollectionCover.php')));
    }

    public function test_editor_has_no_cover_state_actions_or_controls(): void
    {
        $owner = User::factory()->create();
        $collection = $this->collection($owner);

        $this->assertFalse(property_exists(CatalogCollectionEditor::class, 'cover'));
        $this->assertFalse(method_exists(CatalogCollectionEditor::class, 'uploadCover'));
        $this->assertFalse(method_exists(CatalogCollectionEditor::class, 'removeCover'));

        Livewire::actingAs($owner)
            ->test(CatalogCollectionEditor::class, ['collectionPublicId' => $collection->public_id])
            ->assertDontSeeHtml('wire:model="cover"')
            ->assertDontSeeHtml('wire:click="removeCover"')
            ->assertDontSeeText(__('collections.form.cover'));
    }

    public function test_collection_import_parser_and_sync_contract_ignore_remote_images(): void
    {
        $definitions = app(HdRezkaCollectionParser::class)
            ->collections($this->fixture('collections-index.html'));
        $definition = $definitions[0];
        $syncDependencies = collect((new ReflectionMethod(
            HdRezkaCollectionSyncService::class,
            '__construct',
        ))->getParameters())->map(
            fn (\ReflectionParameter $parameter): string => (string) $parameter->getType(),
        );

        $this->assertFalse(property_exists($definition, 'coverPath'));
        $this->assertFalse($syncDependencies->contains(
            'App\Services\Collections\Import\HdRezkaCollectionCoverImporter',
        ));
        $this->assertNotContains('cover_source_path', (new CatalogCollectionSource)->getFillable());
        $this->assertNotContains('cover_path', (new CatalogCollectionSource)->getFillable());
        $this->assertNotContains('cover_content_hash', (new CatalogCollectionSource)->getFillable());
    }

    public function test_account_collection_purge_does_not_manage_legacy_cover_storage(): void
    {
        Storage::fake('uploads');
        config(['uploads.disk' => 'uploads']);
        $owner = User::factory()->create();
        $collection = $this->collection($owner);
        $legacyPath = 'catalog-collections/'.Str::uuid().'/legacy.webp';
        Storage::disk('uploads')->put($legacyPath, 'legacy');

        app(CatalogCollectionAccountService::class)->purgeOwned($owner);

        $this->assertDatabaseMissing('catalog_collections', ['id' => $collection->id]);
        Storage::disk('uploads')->assertExists($legacyPath);
    }

    /** @param array<string, mixed> $attributes */
    private function collection(User $owner, array $attributes = []): CatalogCollection
    {
        $collection = CatalogCollection::query()->create([
            'public_id' => (string) Str::uuid(),
            'owner_id' => $owner->id,
            'name' => 'Подборка без обложки',
            'slug' => 'podborka-bez-oblozhki-'.Str::lower(Str::random(6)),
            'visibility' => CatalogCollectionVisibility::Private,
            'moderation_status' => CatalogCollectionModerationStatus::Approved,
        ]);

        if ($attributes !== []) {
            $collection->forceFill($attributes)->save();
        }

        return $collection;
    }

    private function fixture(string $name): string
    {
        return (string) file_get_contents(base_path('tests/Fixtures/hdrezka/'.$name));
    }
}
