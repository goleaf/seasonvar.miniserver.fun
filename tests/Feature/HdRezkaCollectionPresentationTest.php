<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\CatalogCollectionModerationStatus;
use App\Enums\CatalogCollectionSort;
use App\Enums\CatalogCollectionSourceMatchStatus;
use App\Enums\CatalogCollectionSyncStatus;
use App\Enums\CatalogCollectionType;
use App\Enums\CatalogCollectionVisibility;
use App\Livewire\Collections\CatalogCollectionPage;
use App\Models\CatalogCollection;
use App\Models\CatalogCollectionCategory;
use App\Models\CatalogCollectionItem;
use App\Models\CatalogCollectionSource;
use App\Models\CatalogCollectionSourceItem;
use App\Models\CatalogCollectionSyncRun;
use App\Models\CatalogTitle;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\TestCase;

final class HdRezkaCollectionPresentationTest extends TestCase
{
    use RefreshDatabase;

    public function test_collection_route_is_owned_by_full_page_livewire_and_keeps_private_headers(): void
    {
        $collection = $this->collection(withItem: true);

        $this->assertSame(
            CatalogCollectionPage::class,
            Route::getRoutes()->getByName('collections.show')?->getActionName(),
        );

        $response = $this->get(route('collections.show', ['collectionSlug' => $collection->slug]))
            ->assertOk()
            ->assertSee('wire:snapshot=', false);

        $cacheControl = (string) $response->headers->get('Cache-Control');
        $this->assertStringContainsString('private', $cacheControl);
        $this->assertStringContainsString('no-store', $cacheControl);
        $this->assertStringContainsString('max-age=0', $cacheControl);
    }

    public function test_public_directory_uses_text_only_responsive_cards_and_imported_editorial_badges(): void
    {
        $collection = $this->collection();
        $title = CatalogTitle::factory()->create();
        CatalogCollectionItem::query()->create([
            'catalog_collection_id' => $collection->id,
            'catalog_title_id' => $title->id,
            'position' => 1,
        ]);
        $this->source($collection);
        $response = $this->get(route('discover.index', ['type' => 'popular']));

        $response
            ->assertOk()
            ->assertSeeText('Лучшие фильмы года')
            ->assertSeeText('Редакционная')
            ->assertSeeText('Обновляется автоматически')
            ->assertSeeText('1 сериал')
            ->assertSee('sm:grid-cols-2', false)
            ->assertDontSee('https://hdrezka.my', false)
            ->assertDontSee('/xfsearch/collections/secret-source/', false);
    }

    public function test_admin_page_shows_one_bounded_sanitized_latest_sync_summary(): void
    {
        config(['seasonvar.admin_emails' => ['admin@example.com']]);
        $admin = User::factory()->create(['email' => 'admin@example.com']);
        $collection = $this->collection();
        $filledSource = $this->source($collection);
        $actionableEmptyCollection = $this->collection();
        $actionableEmptySource = $this->source($actionableEmptyCollection);
        $unsupportedEmptyCollection = $this->collection();
        $unsupportedEmptySource = $this->source($unsupportedEmptyCollection);
        $unknownEmptyCollection = $this->collection();
        $unknownEmptySource = $this->source($unknownEmptyCollection);
        $matchedTitle = CatalogTitle::factory()->create();
        CatalogCollectionItem::query()->create([
            'catalog_collection_id' => $collection->id,
            'catalog_title_id' => $matchedTitle->id,
            'position' => 1,
        ]);
        $run = CatalogCollectionSyncRun::query()->create([
            'provider' => 'hdrezka',
            'status' => CatalogCollectionSyncStatus::Completed,
            'counters' => [
                'collections_processed' => 4,
                'pages' => 4,
                'items' => 6,
                'matched' => 2,
                'ambiguous' => 1,
                'unmatched' => 3,
            ],
            'error_summary' => 'private-source-token https://hdrezka.my/secret',
            'started_at' => now()->subMinutes(5),
            'completed_at' => now(),
        ]);
        $this->sourceItem($filledSource, $run, CatalogCollectionSourceMatchStatus::Matched, 'primary', 1, $matchedTitle);
        $this->sourceItem($filledSource, $run, CatalogCollectionSourceMatchStatus::Matched, 'alias', 2, $matchedTitle);
        $this->sourceItem($actionableEmptySource, $run, CatalogCollectionSourceMatchStatus::Ambiguous, 'insufficient_lead', 1);
        $this->sourceItem($actionableEmptySource, $run, CatalogCollectionSourceMatchStatus::Unmatched, 'no_exact_candidate', 2);
        $this->sourceItem($unsupportedEmptySource, $run, CatalogCollectionSourceMatchStatus::Unmatched, 'no_eligible_candidate', 1, sourceType: 'film');
        $this->sourceItem($unknownEmptySource, $run, CatalogCollectionSourceMatchStatus::Unmatched, 'private-internal-reason', 1, sourceType: null);
        DB::flushQueryLog();
        DB::enableQueryLog();

        $response = $this->actingAs($admin)->get(route('admin.catalog', ['section' => 'collections']));
        $queryLog = collect(DB::getQueryLog());
        $syncQueries = $queryLog
            ->filter(fn (array $query): bool => str_contains(
                $query['query'],
                'from "catalog_collection_sync_runs"',
            ));
        $matchDiagnosticQueries = $queryLog
            ->filter(fn (array $query): bool => str_contains(
                $query['query'],
                'from "catalog_collection_source_items"',
            ) && str_contains($query['query'], 'group by "match_status", "match_method"'));
        $scopeDiagnosticQueries = $queryLog
            ->filter(fn (array $query): bool => str_contains(
                $query['query'],
                'from "catalog_collection_source_items"',
            ) && str_contains(
                $query['query'],
                'group by "catalog_collection_source_items"."catalog_collection_source_id", "catalog_collection_source_items"."source_type"',
            ));
        $emptyCollectionQueries = $queryLog
            ->filter(fn (array $query): bool => str_contains(
                $query['query'],
                'from "catalog_collection_sources"',
            ) && str_contains($query['query'], 'not in'));
        DB::disableQueryLog();

        $response
            ->assertOk()
            ->assertSee('data-collection-source-sync-summary', false)
            ->assertSeeText('Последняя синхронизация подборок')
            ->assertSeeText('Завершена')
            ->assertSeeTextInOrder(['Подборок', '4'])
            ->assertSeeTextInOrder(['Страниц', '4'])
            ->assertSeeTextInOrder(['Тайтлов', '6'])
            ->assertSeeTextInOrder(['Совпало', '2'])
            ->assertSeeTextInOrder(['Неоднозначно', '1'])
            ->assertSeeTextInOrder(['Не найдено', '3'])
            ->assertSeeTextInOrder(['Пустых подборок', '3'])
            ->assertSeeTextInOrder(['Требуют сопоставления', '2'])
            ->assertSeeTextInOrder(['Вне области каталога', '1'])
            ->assertSeeTextInOrder(['Покрытие совпадениями', '33,33'])
            ->assertSeeTextInOrder(['Поддерживаются каталогом', '4'])
            ->assertSeeTextInOrder(['Вне текущей области', '1'])
            ->assertSeeTextInOrder(['Тип не определён', '1'])
            ->assertSeeTextInOrder(['По основному названию', '1'])
            ->assertSeeTextInOrder(['По псевдониму', '1'])
            ->assertSeeTextInOrder(['Недостаточный отрыв', '1'])
            ->assertSeeTextInOrder(['Нет точного кандидата', '1'])
            ->assertSeeTextInOrder(['Нет подходящего кандидата', '1'])
            ->assertDontSee('private-source-token', false)
            ->assertDontSee('private-internal-reason', false)
            ->assertDontSee('https://hdrezka.my', false)
            ->assertDontSee('/xfsearch/collections/secret-source/', false);
        $this->assertCount(1, $syncQueries);
        $this->assertCount(1, $matchDiagnosticQueries);
        $this->assertCount(1, $scopeDiagnosticQueries);
        $this->assertCount(1, $emptyCollectionQueries);
    }

    public function test_admin_sync_diagnostics_handle_an_empty_run_without_a_reason_breakdown(): void
    {
        config(['seasonvar.admin_emails' => ['admin@example.com']]);
        $admin = User::factory()->create(['email' => 'admin@example.com']);
        CatalogCollectionSyncRun::query()->create([
            'provider' => 'hdrezka',
            'status' => CatalogCollectionSyncStatus::Completed,
            'counters' => [
                'collections_processed' => 0,
                'pages' => 0,
                'items' => 0,
                'matched' => 0,
                'ambiguous' => 0,
                'unmatched' => 0,
            ],
            'started_at' => now()->subMinute(),
            'completed_at' => now(),
        ]);

        $this->actingAs($admin)
            ->get(route('admin.catalog', ['section' => 'collections']))
            ->assertOk()
            ->assertSeeTextInOrder(['Пустых подборок', '0'])
            ->assertSeeTextInOrder(['Покрытие совпадениями', '0'])
            ->assertDontSeeText('Разбивка сопоставления');
    }

    public function test_public_collection_comment_status_region_has_an_accessible_role(): void
    {
        $collection = $this->collection(withItem: true);

        $this->get(route('collections.show', ['collectionSlug' => $collection->slug]))
            ->assertOk()
            ->assertSee('role="status" aria-live="polite" aria-atomic="true" aria-label="Результат действия с комментариями"', false);
    }

    private function collection(bool $withItem = false): CatalogCollection
    {
        $publicId = (string) Str::uuid();
        $category = CatalogCollectionCategory::query()
            ->where('is_active', true)
            ->orderBy('id')
            ->firstOrFail();

        $collection = CatalogCollection::query()->create([
            'public_id' => $publicId,
            'owner_id' => null,
            'catalog_collection_category_id' => $category->id,
            'name' => 'Лучшие фильмы года',
            'description' => null,
            'slug' => 'luchshie-filmy-goda-'.Str::lower(Str::random(8)),
            'type' => CatalogCollectionType::Editorial,
            'visibility' => CatalogCollectionVisibility::Public,
            'moderation_status' => CatalogCollectionModerationStatus::Approved,
            'sort_mode' => CatalogCollectionSort::Manual,
            'content_locale' => 'ru',
            'is_featured' => false,
            'content_version' => 1,
            'published_at' => now(),
        ]);

        if ($withItem) {
            CatalogCollectionItem::query()->create([
                'catalog_collection_id' => $collection->id,
                'catalog_title_id' => CatalogTitle::factory()->create()->id,
                'position' => 1,
            ]);
        }

        return $collection;
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

    private function sourceItem(
        CatalogCollectionSource $source,
        CatalogCollectionSyncRun $run,
        CatalogCollectionSourceMatchStatus $status,
        string $method,
        int $position,
        ?CatalogTitle $title = null,
        ?string $sourceType = 'series',
    ): void {
        $identity = $source->id.'-'.$position;

        CatalogCollectionSourceItem::query()->create([
            'catalog_collection_source_id' => $source->id,
            'source_item_key' => $identity,
            'source_title' => 'Карточка '.$identity,
            'normalized_title_key' => 'карточка '.$identity,
            'normalized_title_hash' => hash('sha256', 'карточка '.$identity),
            'source_year' => 2026,
            'source_type' => $sourceType,
            'countries' => ['Россия'],
            'detail_path' => '/'.$identity.'-card.html',
            'detail_path_hash' => hash('sha256', '/'.$identity.'-card.html'),
            'source_page' => 1,
            'source_position' => $position,
            'match_status' => $status,
            'catalog_title_id' => $title?->id,
            'match_method' => $method,
            'match_confidence' => $status === CatalogCollectionSourceMatchStatus::Matched ? 100 : 0,
            'match_reasons' => ['code' => $method],
            'last_seen_run_id' => $run->id,
        ]);
    }
}
