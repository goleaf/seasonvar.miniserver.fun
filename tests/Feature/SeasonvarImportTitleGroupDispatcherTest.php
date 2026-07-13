<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\FinalizeSeasonvarImportTitleGroup;
use App\Jobs\PrepareSeasonvarImportTitlePage;
use App\Models\CatalogTitle;
use App\Models\Season;
use App\Models\SeasonvarImportPreparedPage;
use App\Models\Source;
use App\Services\Seasonvar\SeasonvarImportTitleGroupDispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class SeasonvarImportTitleGroupDispatcherTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['seasonvar.queue.lock_store' => 'array']);
        Cache::store('array')->flush();
    }

    public function test_nine_urls_dispatch_nine_independent_jobs_without_chunking(): void
    {
        Queue::fake();
        $title = $this->titleWithSeasonUrls(range(1, 9));

        $group = app(SeasonvarImportTitleGroupDispatcher::class)
            ->start($title, 'seasonvar-title-refresh');

        Queue::assertPushed(PrepareSeasonvarImportTitlePage::class, 9);
        Queue::assertPushed(FinalizeSeasonvarImportTitleGroup::class, 1);
        $this->assertSame(9, $group->fresh()->expected_pages);
        $this->assertSame(9, $group->preparedPages()->count());
    }

    public function test_fifty_urls_are_dispatched_without_an_application_limit(): void
    {
        Queue::fake();
        $title = $this->titleWithSeasonUrls(range(1, 50));

        $group = app(SeasonvarImportTitleGroupDispatcher::class)
            ->start($title, 'seasonvar-title-refresh');

        Queue::assertPushed(PrepareSeasonvarImportTitlePage::class, 50);
        $this->assertSame(50, $group->fresh()->expected_pages);
    }

    public function test_duplicate_and_invalid_urls_do_not_create_more_prepared_pages(): void
    {
        Queue::fake();
        $title = $this->titleWithSeasonUrls([1]);
        $dispatcher = app(SeasonvarImportTitleGroupDispatcher::class);
        $group = $dispatcher->start($title, 'seasonvar-title-refresh');
        $validUrl = $this->seasonUrl(1);

        $created = $dispatcher->addUrls($group, [
            $validUrl,
            $validUrl,
            'https://example.com/serial-1-invalid-2-season.html',
            'javascript:alert(1)',
        ]);

        $this->assertSame(0, $created);
        $this->assertSame(1, $group->fresh()->expected_pages);
        $this->assertSame(1, $group->preparedPages()->count());
        Queue::assertPushed(PrepareSeasonvarImportTitlePage::class, 1);
    }

    public function test_preparation_job_dispatches_a_newly_discovered_season_before_releasing_parent(): void
    {
        Queue::fake();
        Http::preventStrayRequests();
        config([
            'seasonvar.media_check.enabled' => false,
            'security.external_playlist_enforce_public_dns' => false,
        ]);
        $title = $this->titleWithSeasonUrls([1]);
        $seasonOneUrl = $this->seasonUrl(1);
        $seasonTwoUrl = $this->seasonUrl(2);
        Http::fake([
            $seasonOneUrl => Http::response(<<<HTML
                <html>
                    <head><title>Рыжая 1 сезон</title></head>
                    <body>
                        <h1>Рыжая 1 сезон</h1>
                        <div class="pgs-sinfo_list">Год: 2026 Жанр: Драма Страна: Россия</div>
                        <div class="pgs-seaslist">
                            <a href="{$seasonOneUrl}">1 сезон</a>
                            <a href="{$seasonTwoUrl}">2 сезон</a>
                        </div>
                        <script>var arEpisodes = [{"1_seriya":{"n":"1"}}];</script>
                    </body>
                </html>
                HTML),
        ]);
        $group = app(SeasonvarImportTitleGroupDispatcher::class)
            ->start($title, 'seasonvar-title-refresh');
        $parent = $group->preparedPages()->firstOrFail();

        $this->app->call([new PrepareSeasonvarImportTitlePage($parent->id), 'handle']);

        $this->assertSame('prepared', $parent->fresh()->status->value);
        $this->assertSame(2, $group->fresh()->expected_pages);
        $this->assertSame(1, $group->fresh()->prepared_pages);
        $this->assertDatabaseHas('seasonvar_import_prepared_pages', [
            'seasonvar_import_title_group_id' => $group->id,
            'source_page_id' => SeasonvarImportPreparedPage::query()
                ->whereHas('sourcePage', fn ($query) => $query->where('url_hash', hash('sha256', $seasonTwoUrl)))
                ->value('source_page_id'),
            'status' => 'queued',
        ]);
        Queue::assertPushed(PrepareSeasonvarImportTitlePage::class, 2);
    }

    /** @param list<int> $seasonNumbers */
    private function titleWithSeasonUrls(array $seasonNumbers): CatalogTitle
    {
        $source = Source::factory()->create([
            'code' => 'seasonvar',
            'base_url' => 'https://seasonvar.ru',
            'crawl_delay_seconds' => 0,
        ]);
        $title = CatalogTitle::factory()->for($source)->create([
            'external_id' => '24212',
            'source_url' => $this->seasonUrl($seasonNumbers[0]),
            'source_url_hash' => hash('sha256', $this->seasonUrl($seasonNumbers[0])),
        ]);

        foreach ($seasonNumbers as $seasonNumber) {
            $url = $this->seasonUrl($seasonNumber);
            Season::factory()->for($title)->create([
                'number' => $seasonNumber,
                'sort_order' => $seasonNumber,
                'source_url' => $url,
                'source_url_hash' => hash('sha256', $url),
            ]);
        }

        return $title;
    }

    private function seasonUrl(int $seasonNumber): string
    {
        return sprintf(
            'https://seasonvar.ru/serial-24212-Ryzhaya_psbdtie-%d-season.html',
            $seasonNumber,
        );
    }
}
