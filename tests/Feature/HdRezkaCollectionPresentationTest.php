<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\CatalogCollectionModerationStatus;
use App\Enums\CatalogCollectionSort;
use App\Enums\CatalogCollectionSyncStatus;
use App\Enums\CatalogCollectionType;
use App\Enums\CatalogCollectionVisibility;
use App\Livewire\Collections\CatalogCollectionPage;
use App\Models\CatalogCollection;
use App\Models\CatalogCollectionItem;
use App\Models\CatalogCollectionSource;
use App\Models\CatalogCollectionSyncRun;
use App\Models\CatalogTitle;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

final class HdRezkaCollectionPresentationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('uploads');
        config(['uploads.disk' => 'uploads']);
    }

    public function test_collection_route_is_owned_by_full_page_livewire_and_keeps_private_headers(): void
    {
        $collection = $this->collection();

        $this->assertSame(
            CatalogCollectionPage::class,
            Route::getRoutes()->getByName('collections.show')?->getActionName(),
        );

        $response = $this->get(route('collections.show', ['collectionSlug' => $collection->slug]))
            ->assertOk()
            ->assertSeeLivewire('collections.catalog-collection-page');

        $cacheControl = (string) $response->headers->get('Cache-Control');
        $this->assertStringContainsString('private', $cacheControl);
        $this->assertStringContainsString('no-store', $cacheControl);
        $this->assertStringContainsString('max-age=0', $cacheControl);
    }

    public function test_public_directory_uses_local_cover_responsive_grid_and_imported_editorial_badges(): void
    {
        $collection = $this->collection();
        $title = CatalogTitle::factory()->create();
        CatalogCollectionItem::query()->create([
            'catalog_collection_id' => $collection->id,
            'catalog_title_id' => $title->id,
            'position' => 1,
        ]);
        $this->source($collection);
        Storage::disk('uploads')->put((string) $collection->cover_path, 'webp-cover');
        $coverUrl = route('collections.cover', [
            'publicId' => $collection->public_id,
            'version' => $collection->cover_version,
        ]);

        $response = $this->get(route('discover.index', ['type' => 'popular']));

        $response
            ->assertOk()
            ->assertSeeText('Лучшие фильмы года')
            ->assertSeeText('Редакционная')
            ->assertSeeText('Обновляется автоматически')
            ->assertSeeText('1 сериал')
            ->assertSee('src="'.$coverUrl.'"', false)
            ->assertSee('sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4', false)
            ->assertDontSee('https://hdrezka.my', false)
            ->assertDontSee('/xfsearch/collections/secret-source/', false);

        $this->get($coverUrl)
            ->assertOk()
            ->assertHeader('Content-Type', 'image/webp');
    }

    public function test_admin_page_shows_one_bounded_sanitized_latest_sync_summary(): void
    {
        config(['seasonvar.admin_emails' => ['admin@example.com']]);
        $admin = User::factory()->create(['email' => 'admin@example.com']);
        $collection = $this->collection();
        $this->source($collection);
        CatalogCollectionSyncRun::query()->create([
            'provider' => 'hdrezka',
            'status' => CatalogCollectionSyncStatus::Completed,
            'counters' => [
                'collections_processed' => 12,
                'pages' => 34,
                'items' => 567,
                'matched' => 321,
                'ambiguous' => 7,
                'unmatched' => 239,
            ],
            'error_summary' => 'private-source-token https://hdrezka.my/secret',
            'started_at' => now()->subMinutes(5),
            'completed_at' => now(),
        ]);
        DB::flushQueryLog();
        DB::enableQueryLog();

        $response = $this->actingAs($admin)->get(route('admin.catalog', ['section' => 'collections']));
        $syncQueries = collect(DB::getQueryLog())
            ->filter(fn (array $query): bool => str_contains(
                $query['query'],
                'from "catalog_collection_sync_runs"',
            ));
        DB::disableQueryLog();

        $response
            ->assertOk()
            ->assertSee('data-collection-source-sync-summary', false)
            ->assertSeeText('Последняя синхронизация подборок')
            ->assertSeeText('Завершена')
            ->assertSeeTextInOrder(['Подборок', '12'])
            ->assertSeeTextInOrder(['Страниц', '34'])
            ->assertSeeTextInOrder(['Тайтлов', '567'])
            ->assertSeeTextInOrder(['Совпало', '321'])
            ->assertSeeTextInOrder(['Неоднозначно', '7'])
            ->assertSeeTextInOrder(['Не найдено', '239'])
            ->assertDontSee('private-source-token', false)
            ->assertDontSee('https://hdrezka.my', false)
            ->assertDontSee('/xfsearch/collections/secret-source/', false);
        $this->assertCount(1, $syncQueries);
    }

    public function test_public_collection_comment_status_region_has_an_accessible_role(): void
    {
        $collection = $this->collection();

        $this->get(route('collections.show', ['collectionSlug' => $collection->slug]))
            ->assertOk()
            ->assertSee('role="status" aria-live="polite" aria-atomic="true" aria-label="Результат действия с комментариями"', false);
    }

    private function collection(): CatalogCollection
    {
        $publicId = (string) Str::uuid();

        return CatalogCollection::query()->create([
            'public_id' => $publicId,
            'owner_id' => null,
            'name' => 'Лучшие фильмы года',
            'description' => null,
            'slug' => 'luchshie-filmy-goda-'.Str::lower(Str::random(8)),
            'type' => CatalogCollectionType::Editorial,
            'visibility' => CatalogCollectionVisibility::Public,
            'moderation_status' => CatalogCollectionModerationStatus::Approved,
            'sort_mode' => CatalogCollectionSort::Manual,
            'content_locale' => 'ru',
            'is_featured' => false,
            'cover_disk' => 'uploads',
            'cover_path' => 'catalog-collections/'.$publicId.'/imported/'.str_repeat('a', 64).'.webp',
            'cover_mime_type' => 'image/webp',
            'cover_size' => 10,
            'cover_version' => 1,
            'content_version' => 1,
            'published_at' => now(),
        ]);
    }

    private function source(CatalogCollection $collection): CatalogCollectionSource
    {
        return CatalogCollectionSource::query()->create([
            'provider' => 'hdrezka',
            'source_key' => hash('sha256', (string) $collection->id),
            'catalog_collection_id' => $collection->id,
            'source_path' => '/xfsearch/collections/secret-source/',
            'remote_name' => $collection->name,
            'last_successful_sync_at' => now(),
        ]);
    }
}
