<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\CatalogTitle;
use App\Models\CatalogTitleSearchDocument;
use App\Services\Catalog\Search\CatalogSearchDocumentBuilder;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class SyncHdRezkaCollectionsCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('uploads');
        Http::preventStrayRequests();
        config([
            'catalog-collection-imports.hdrezka.delay_seconds' => 0,
            'catalog-collection-imports.hdrezka.lock_store' => 'array',
        ]);
    }

    public function test_disabled_source_is_rejected_with_russian_operator_message(): void
    {
        config(['catalog-collection-imports.hdrezka.enabled' => false]);

        $this->artisan('catalog-collections:sync-hdrezka')
            ->expectsOutput('Синхронизация HDRezka выключена в конфигурации.')
            ->assertFailed();

        Http::assertNothingSent();
    }

    public function test_dry_run_command_reports_counters_without_writes(): void
    {
        config(['catalog-collection-imports.hdrezka.enabled' => true]);
        $title = CatalogTitle::factory()->create(['title' => 'Один фильм', 'year' => 2024, 'type' => 'film']);
        $title->load('aliases');
        CatalogTitleSearchDocument::query()->create(app(CatalogSearchDocumentBuilder::class)->build($title));
        $index = '<div class="collections-grid"><a href="/xfsearch/collections/films/"><span class="name">Фильмы</span></a></div>';
        $page = '<div id="dle-content"><div class="card_item"><span class="card_item__category_icon card_item__category_icon--film"></span><a class="card_item__title" href="/101-title.html">Один фильм</a><div class="card_item__misc">2024, США</div></div></div>';
        Http::fake([
            'https://hdrezka.my/collections.html' => Http::response($index, 200, ['Content-Type' => 'text/html']),
            'https://hdrezka.my/xfsearch/collections/films/' => Http::response($page, 200, ['Content-Type' => 'text/html']),
        ]);

        $this->artisan('catalog-collections:sync-hdrezka', ['--dry-run' => true, '--limit-collections' => 1])
            ->expectsOutputToContain('Подборок обнаружено: 1')
            ->expectsOutputToContain('Тайтлов просмотрено: 1')
            ->expectsOutputToContain('Совпало: 1')
            ->expectsOutput('Dry-run завершён: данные портала не изменены.')
            ->assertSuccessful();

        $this->assertDatabaseCount('catalog_collection_sync_runs', 0);
        $this->assertDatabaseCount('catalog_collections', 0);
    }

    public function test_invalid_collection_limit_is_rejected_before_http(): void
    {
        config(['catalog-collection-imports.hdrezka.enabled' => true]);

        $this->artisan('catalog-collections:sync-hdrezka', ['--limit-collections' => 0])
            ->expectsOutput('Параметр --limit-collections должен быть положительным целым числом.')
            ->assertFailed();

        Http::assertNothingSent();
    }

    public function test_daily_schedule_is_single_server_and_overlap_protected(): void
    {
        $event = collect(app(Schedule::class)->events())
            ->first(fn ($event): bool => $event->description === 'hdrezka-editorial-collections-sync');

        $this->assertNotNull($event);
        $this->assertSame('37 3 * * *', $event->expression);
        $this->assertStringContainsString('catalog-collections:sync-hdrezka', $event->command);
        $this->assertTrue($event->withoutOverlapping);
        $this->assertSame(360, $event->expiresAt);
        $this->assertTrue($event->onOneServer);
        $this->assertSame('redis-locks', $event->mutex->store);
    }
}
