<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\CatalogCollectionSyncStatus;
use App\Jobs\RebuildCatalogRecommendationsAfterCollectionSync;
use App\Models\CatalogCollection;
use App\Models\CatalogCollectionSource;
use App\Models\CatalogCollectionSyncRun;
use App\Models\CatalogTitle;
use App\Models\CatalogTitleSearchDocument;
use App\Services\Catalog\CatalogCacheWarmRequestStore;
use App\Services\Catalog\Search\CatalogSearchDocumentBuilder;
use App\Services\Collections\Import\HdRezkaCollectionSyncService;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class HdRezkaCollectionSyncTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('uploads');
        Http::preventStrayRequests();
        config([
            'catalog-collection-imports.hdrezka.enabled' => true,
            'catalog-collection-imports.hdrezka.delay_seconds' => 0,
            'catalog-collection-imports.hdrezka.max_response_bytes' => 1_000_000,
            'catalog-collection-imports.hdrezka.max_pages_per_collection' => 10,
            'catalog-collection-imports.hdrezka.max_items_per_collection' => 100,
            'catalog-collection-imports.hdrezka.max_collections' => 10,
            'catalog-collection-imports.hdrezka.lock_store' => 'array',
            'catalog-collection-imports.hdrezka.lock_seconds' => 300,
            'catalog-collection-imports.hdrezka.cover.max_source_bytes' => 1_000_000,
            'catalog-collection-imports.hdrezka.cover.max_width' => 320,
            'catalog-collection-imports.hdrezka.cover.max_height' => 180,
            'catalog-collection-imports.hdrezka.cover.quality' => 82,
            'recommendations.similarity_v6.editorial_collection_signal_weight' => 280,
        ]);
    }

    public function test_full_two_page_sync_reconciles_all_items_cover_signals_and_defers_warm_until_recommendations_activate(): void
    {
        Queue::fake();
        $titles = [
            $this->indexedTitle('Первый фильм', 2024),
            $this->indexedTitle('Второй фильм', 2023),
            $this->indexedTitle('Третий фильм', 2022),
        ];
        $this->fakeFullSource();

        $result = app(HdRezkaCollectionSyncService::class)->sync();

        $this->assertSame(CatalogCollectionSyncStatus::Completed, $result->status);
        $this->assertFalse($result->dryRun);
        $this->assertNotNull($result->runId);
        $this->assertSame(1, $result->counters['collections_discovered']);
        $this->assertSame(1, $result->counters['collections_processed']);
        $this->assertSame(2, $result->counters['pages']);
        $this->assertSame(3, $result->counters['items']);
        $this->assertSame(3, $result->counters['matched']);
        $this->assertSame(0, $result->counters['ambiguous']);
        $this->assertSame(0, $result->counters['unmatched']);
        $this->assertSame(1, $result->counters['covers_updated']);
        $this->assertSame([], $result->errors);
        $this->assertDatabaseCount('catalog_collections', 1);
        $this->assertDatabaseCount('catalog_collection_sources', 1);
        $this->assertDatabaseCount('catalog_collection_source_items', 3);
        $this->assertDatabaseCount('catalog_collection_items', 3);
        $this->assertDatabaseCount('catalog_title_recommendation_signals', 3);
        $this->assertDatabaseCount('catalog_recommendation_dirty_titles', 3);
        $collection = CatalogCollection::query()->firstOrFail();
        $this->assertSame(array_column($titles, 'id'), $collection->items()->pluck('catalog_title_id')->all());
        $this->assertSame('image/webp', $collection->cover_mime_type);
        Storage::disk('uploads')->assertExists((string) $collection->cover_path);
        $this->assertSame(
            CatalogCollectionSyncStatus::Completed,
            CatalogCollectionSyncRun::query()->findOrFail($result->runId)->status,
        );
        $this->assertNull(app(CatalogCacheWarmRequestStore::class)->claim(10));
        Queue::assertPushed(RebuildCatalogRecommendationsAfterCollectionSync::class, 1);
        Http::assertSentCount(4);
    }

    public function test_dry_run_parses_and_matches_without_database_or_cover_mutations(): void
    {
        Queue::fake();
        $this->indexedTitle('Первый фильм', 2024);
        $this->indexedTitle('Второй фильм', 2023);
        $this->indexedTitle('Третий фильм', 2022);
        $this->fakeFullSource();

        $result = app(HdRezkaCollectionSyncService::class)->sync(dryRun: true);

        $this->assertSame(CatalogCollectionSyncStatus::Completed, $result->status);
        $this->assertTrue($result->dryRun);
        $this->assertNull($result->runId);
        $this->assertSame(3, $result->counters['items']);
        $this->assertSame(3, $result->counters['matched']);
        $this->assertDatabaseCount('catalog_collection_sync_runs', 0);
        $this->assertDatabaseCount('catalog_collections', 0);
        $this->assertDatabaseCount('catalog_collection_sources', 0);
        $this->assertDatabaseCount('catalog_title_recommendation_signals', 0);
        Storage::disk('uploads')->assertDirectoryEmpty('/');
        Queue::assertNotPushed(RebuildCatalogRecommendationsAfterCollectionSync::class);
        Http::assertSentCount(3);
    }

    public function test_it_follows_one_same_host_same_purpose_canonical_redirect(): void
    {
        $this->indexedTitle('Первый фильм', 2024);
        $index = <<<'HTML'
            <!doctype html><html lang="ru"><body><div class="collections-grid">
                <a href="/xfsearch/collections/Films/"><span class="name">Фильмы</span></a>
            </div></body></html>
            HTML;
        Http::fake([
            'https://hdrezka.my/collections.html' => $this->htmlResponse($index),
            'https://hdrezka.my/xfsearch/collections/Films/' => Http::response('', 301, [
                'Location' => 'https://hdrezka.my/xfsearch/collections/films/',
            ]),
            'https://hdrezka.my/xfsearch/collections/films/' => $this->htmlResponse(
                $this->pageHtml([$this->card('101', 'Первый фильм', 2024)], 1, null),
            ),
        ]);

        $result = app(HdRezkaCollectionSyncService::class)->sync(dryRun: true);

        $this->assertSame(CatalogCollectionSyncStatus::Completed, $result->status);
        $this->assertSame(1, $result->counters['pages']);
        $this->assertSame(1, $result->counters['items']);
        $this->assertSame(1, $result->counters['matched']);
        Http::assertSentCount(3);
    }

    public function test_page_failure_marks_run_partial_and_never_removes_unseen_membership_or_signals(): void
    {
        $first = $this->indexedTitle('Первый фильм', 2024);
        $second = $this->indexedTitle('Второй фильм', 2023);
        $this->indexedTitle('Третий фильм', 2022);
        $initialPageOne = $this->pageHtml([
            $this->card('101', 'Первый фильм', 2024),
            $this->card('102', 'Второй фильм', 2023),
        ], 1, 2);
        $partialPageOne = $this->pageHtml([$this->card('101', 'Первый фильм', 2024)], 1, 2);
        $pageTwo = $this->pageHtml([$this->card('103', 'Третий фильм', 2022)], 2, null);
        Http::fake([
            'https://hdrezka.my/collections.html' => Http::sequence()
                ->push($this->indexHtml(), 200, ['Content-Type' => 'text/html'])
                ->push($this->indexHtml(), 200, ['Content-Type' => 'text/html']),
            'https://hdrezka.my/xfsearch/collections/films/' => Http::sequence()
                ->push($initialPageOne, 200, ['Content-Type' => 'text/html'])
                ->push($partialPageOne, 200, ['Content-Type' => 'text/html']),
            'https://hdrezka.my/xfsearch/collections/films/page/2/' => Http::sequence()
                ->push($pageTwo, 200, ['Content-Type' => 'text/html'])
                ->push('Ошибка', 500),
            'https://hdrezka.my/uploads/mini/14/aa/cover.png' => Http::sequence()
                ->pushResponse($this->imageResponse())
                ->pushResponse($this->imageResponse()),
        ]);
        $initial = app(HdRezkaCollectionSyncService::class)->sync();
        $this->assertSame(
            CatalogCollectionSyncStatus::Completed,
            $initial->status,
            json_encode(['counters' => $initial->counters, 'errors' => $initial->errors], JSON_UNESCAPED_UNICODE),
        );
        $this->assertDatabaseCount('catalog_collection_items', 3);

        $partial = app(HdRezkaCollectionSyncService::class)->sync();

        $this->assertSame(CatalogCollectionSyncStatus::Partial, $partial->status);
        $this->assertSame(1, $partial->counters['collection_failures']);
        $this->assertNotEmpty($partial->errors);
        $this->assertDatabaseCount('catalog_collection_items', 3);
        $this->assertDatabaseCount('catalog_title_recommendation_signals', 3);
        $this->assertDatabaseHas('catalog_collection_items', ['catalog_title_id' => $second->id]);
        $this->assertDatabaseHas('catalog_title_recommendation_signals', [
            'catalog_title_id' => $second->id,
            'source' => 'hdrezka',
            'signal_type' => 'editorial_collection',
        ]);
        $this->assertDatabaseHas('catalog_collection_items', ['catalog_title_id' => $first->id]);
    }

    public function test_broken_first_page_still_records_the_index_collection_without_destructive_items(): void
    {
        Http::fake([
            'https://hdrezka.my/collections.html' => $this->htmlResponse($this->indexHtml(withCover: false)),
            'https://hdrezka.my/xfsearch/collections/films/' => Http::response(
                'Страница не найдена',
                404,
                ['Content-Type' => 'text/html; charset=UTF-8'],
            ),
        ]);

        $result = app(HdRezkaCollectionSyncService::class)->sync();

        $this->assertSame(CatalogCollectionSyncStatus::Partial, $result->status);
        $this->assertSame(1, $result->counters['collections_discovered']);
        $this->assertSame(1, $result->counters['collections_processed']);
        $this->assertSame(1, $result->counters['collection_failures']);
        $this->assertSame(0, $result->counters['items']);
        $this->assertDatabaseCount('catalog_collections', 1);
        $this->assertDatabaseCount('catalog_collection_sources', 1);
        $this->assertDatabaseCount('catalog_collection_source_items', 0);
        $this->assertDatabaseCount('catalog_collection_items', 0);
    }

    public function test_configured_page_limit_terminates_pagination_as_partial(): void
    {
        $this->indexedTitle('Первый фильм', 2024);
        config(['catalog-collection-imports.hdrezka.max_pages_per_collection' => 1]);
        Http::fake([
            'https://hdrezka.my/collections.html' => $this->htmlResponse($this->indexHtml(withCover: false)),
            'https://hdrezka.my/xfsearch/collections/films/' => $this->htmlResponse(
                $this->pageHtml([$this->card('101', 'Первый фильм', 2024)], 1, 2),
            ),
        ]);

        $result = app(HdRezkaCollectionSyncService::class)->sync();

        $this->assertSame(CatalogCollectionSyncStatus::Partial, $result->status);
        $this->assertSame(1, $result->counters['pages']);
        $this->assertSame(1, $result->counters['items']);
        $this->assertStringContainsString('лимит', implode(' ', $result->errors));
        $this->assertDatabaseCount('catalog_collection_items', 1);
    }

    public function test_cover_storage_failure_keeps_committed_membership_dirty_tracking_and_recommendation_dispatch(): void
    {
        Queue::fake();
        $titles = [
            $this->indexedTitle('Первый фильм', 2024),
            $this->indexedTitle('Второй фильм', 2023),
            $this->indexedTitle('Третий фильм', 2022),
        ];
        $this->fakeFullSource();
        config(['filesystems.disks.uploads.driver' => 'unsupported-for-imported-cover']);

        $result = app(HdRezkaCollectionSyncService::class)->sync();

        $this->assertSame(CatalogCollectionSyncStatus::Completed, $result->status);
        $this->assertSame(1, $result->counters['covers_failed']);
        $this->assertSame(0, $result->counters['collection_failures']);
        $this->assertNotEmpty($result->errors);
        $this->assertDatabaseCount('catalog_collection_items', 3);
        $this->assertDatabaseCount('catalog_title_recommendation_signals', 3);

        foreach ($titles as $title) {
            $this->assertDatabaseHas('catalog_recommendation_dirty_titles', [
                'catalog_title_id' => $title->id,
            ]);
        }

        $this->assertNull(app(CatalogCacheWarmRequestStore::class)->claim(10));
        Queue::assertPushed(RebuildCatalogRecommendationsAfterCollectionSync::class, 1);
    }

    public function test_completed_index_marks_a_disappeared_source_missing_and_removes_only_its_public_membership(): void
    {
        Queue::fake();
        $first = $this->indexedTitle('Первый фильм', 2024);
        $second = $this->indexedTitle('Второй фильм', 2023);
        $firstIndex = $this->collectionsIndexHtml([
            ['/xfsearch/collections/films/', 'Первая подборка'],
            ['/xfsearch/collections/series/', 'Вторая подборка'],
        ]);
        $secondIndex = $this->collectionsIndexHtml([
            ['/xfsearch/collections/films/', 'Первая подборка'],
        ]);
        Http::fake([
            'https://hdrezka.my/collections.html' => Http::sequence()
                ->push($firstIndex, 200, ['Content-Type' => 'text/html'])
                ->push($secondIndex, 200, ['Content-Type' => 'text/html'])
                ->push($firstIndex, 200, ['Content-Type' => 'text/html']),
            'https://hdrezka.my/xfsearch/collections/films/' => Http::sequence()
                ->push($this->pageHtml([$this->card('101', 'Первый фильм', 2024)], 1, null), 200, ['Content-Type' => 'text/html'])
                ->push($this->pageHtml([$this->card('101', 'Первый фильм', 2024)], 1, null), 200, ['Content-Type' => 'text/html'])
                ->push($this->pageHtml([$this->card('101', 'Первый фильм', 2024)], 1, null), 200, ['Content-Type' => 'text/html']),
            'https://hdrezka.my/xfsearch/collections/series/' => Http::sequence()
                ->push(str_replace(
                    '/xfsearch/collections/films/',
                    '/xfsearch/collections/series/',
                    $this->pageHtml([$this->card('102', 'Второй фильм', 2023)], 1, null),
                ), 200, ['Content-Type' => 'text/html'])
                ->push(str_replace(
                    '/xfsearch/collections/films/',
                    '/xfsearch/collections/series/',
                    $this->pageHtml([$this->card('102', 'Второй фильм', 2023)], 1, null),
                ), 200, ['Content-Type' => 'text/html']),
        ]);

        $initial = app(HdRezkaCollectionSyncService::class)->sync();
        $this->assertSame(CatalogCollectionSyncStatus::Completed, $initial->status);
        $this->assertDatabaseCount('catalog_collection_items', 2);
        DB::table('catalog_recommendation_dirty_titles')->delete();

        $result = app(HdRezkaCollectionSyncService::class)->sync();

        $this->assertSame(CatalogCollectionSyncStatus::Completed, $result->status);
        $this->assertSame(1, $result->counters['sources_missing']);
        $this->assertSame(1, $result->counters['removed']);
        $missingSource = CatalogCollectionSource::query()
            ->where('source_path', '/xfsearch/collections/series/')
            ->firstOrFail();
        $this->assertNotNull($missingSource->missing_since_at);
        $this->assertDatabaseMissing('catalog_collection_items', [
            'catalog_collection_id' => $missingSource->catalog_collection_id,
        ]);
        $this->assertDatabaseMissing('catalog_title_recommendation_signals', [
            'catalog_title_id' => $second->id,
            'source' => 'hdrezka',
            'signal_type' => 'editorial_collection',
        ]);
        $this->assertDatabaseHas('catalog_title_recommendation_signals', [
            'catalog_title_id' => $first->id,
            'source' => 'hdrezka',
            'signal_type' => 'editorial_collection',
        ]);
        $this->assertDatabaseHas('catalog_recommendation_dirty_titles', [
            'catalog_title_id' => $second->id,
        ]);
        $this->assertSame(1, CatalogCollection::query()->publiclyListed()->count());
        $this->assertFalse($missingSource->collection->load('sourceRecord')->isPubliclyViewable());

        $reappeared = app(HdRezkaCollectionSyncService::class)->sync();

        $this->assertSame(CatalogCollectionSyncStatus::Completed, $reappeared->status);
        $this->assertSame(1, $reappeared->counters['sources_reactivated']);
        $this->assertNull($missingSource->fresh()?->missing_since_at);
        $this->assertDatabaseHas('catalog_collection_items', [
            'catalog_collection_id' => $missingSource->catalog_collection_id,
            'catalog_title_id' => $second->id,
        ]);
        $this->assertDatabaseHas('catalog_title_recommendation_signals', [
            'catalog_title_id' => $second->id,
            'source' => 'hdrezka',
            'signal_type' => 'editorial_collection',
        ]);
        $this->assertSame(2, CatalogCollection::query()->publiclyListed()->count());
    }

    public function test_active_distributed_lock_returns_safe_failed_result_without_http_or_writes(): void
    {
        Http::fake();
        $lock = Cache::store('array')->lock('catalog-collections:sync:hdrezka', 300);
        $this->assertTrue($lock->get());

        try {
            $result = app(HdRezkaCollectionSyncService::class)->sync();
        } finally {
            $lock->release();
        }

        $this->assertSame(CatalogCollectionSyncStatus::Failed, $result->status);
        $this->assertSame(['Синхронизация коллекций уже выполняется.'], $result->errors);
        $this->assertNull($result->runId);
        $this->assertDatabaseCount('catalog_collection_sync_runs', 0);
        Http::assertNothingSent();
    }

    private function fakeFullSource(): void
    {
        Http::fake([
            'https://hdrezka.my/collections.html' => $this->htmlResponse($this->indexHtml()),
            'https://hdrezka.my/xfsearch/collections/films/' => $this->htmlResponse(
                $this->pageHtml([
                    $this->card('101', 'Первый фильм', 2024),
                    $this->card('102', 'Второй фильм', 2023),
                ], 1, 2),
            ),
            'https://hdrezka.my/xfsearch/collections/films/page/2/' => $this->htmlResponse(
                $this->pageHtml([$this->card('103', 'Третий фильм', 2022)], 2, null),
            ),
            'https://hdrezka.my/uploads/mini/14/aa/cover.png' => $this->imageResponse(),
        ]);
    }

    private function indexHtml(bool $withCover = true): string
    {
        $image = $withCover ? '<img data-src="/uploads/mini/14/aa/cover.png" alt="Обложка">' : '';

        return <<<HTML
            <!doctype html><html lang="ru"><head><meta charset="utf-8"></head><body>
            <div class="collections-grid">
                <a href="/xfsearch/collections/films/">
                    <span class="name">Проверочная подборка</span>{$image}
                </a>
            </div>
            </body></html>
            HTML;
    }

    /** @param list<array{string, string}> $collections */
    private function collectionsIndexHtml(array $collections): string
    {
        $links = collect($collections)
            ->map(fn (array $collection): string => sprintf(
                '<a href="%s"><span class="name">%s</span></a>',
                $collection[0],
                $collection[1],
            ))
            ->implode('');

        return '<!doctype html><html lang="ru"><head><meta charset="utf-8"></head><body>'
            .'<div class="collections-grid">'.$links.'</div></body></html>';
    }

    /** @param list<string> $cards */
    private function pageHtml(array $cards, int $currentPage, ?int $nextPage): string
    {
        $next = $nextPage !== null
            ? "<a href=\"https://hdrezka.my/xfsearch/collections/films/page/{$nextPage}/\">{$nextPage}</a>"
            : '';

        return '<!doctype html><html lang="ru"><head><meta charset="utf-8"></head><body>'
            .'<div id="dle-content">'.implode('', $cards)
            ."<div class=\"pagination\"><span>{$currentPage}</span>{$next}</div>"
            .'</div></body></html>';
    }

    private function card(string $id, string $title, int $year): string
    {
        return <<<HTML
            <div class="card_item">
                <div class="card_item__image"><span class="card_item__category_icon card_item__category_icon--film"></span></div>
                <a class="card_item__title" href="/{$id}-title.html">{$title}</a>
                <div class="card_item__misc">{$year}, США</div>
            </div>
            HTML;
    }

    private function indexedTitle(string $name, int $year): CatalogTitle
    {
        $title = CatalogTitle::factory()->create(['title' => $name, 'year' => $year, 'type' => 'film']);
        $title->load('aliases');
        CatalogTitleSearchDocument::query()->create(app(CatalogSearchDocumentBuilder::class)->build($title));

        return $title;
    }

    private function htmlResponse(string $html): PromiseInterface|Response
    {
        return Http::response($html, 200, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'Content-Length' => (string) strlen($html),
        ]);
    }

    private function imageResponse(): PromiseInterface|Response
    {
        $image = imagecreatetruecolor(640, 360);
        imagefill($image, 0, 0, imagecolorallocate($image, 35, 90, 160));
        ob_start();
        imagepng($image, null, 6);
        $bytes = ob_get_clean();
        imagedestroy($image);
        $this->assertIsString($bytes);

        return Http::response($bytes, 200, [
            'Content-Type' => 'image/png',
            'Content-Length' => (string) strlen($bytes),
        ]);
    }
}
