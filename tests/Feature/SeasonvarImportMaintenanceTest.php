<?php

namespace Tests\Feature;

use App\DTOs\MediaHealthCheckResultData;
use App\Models\Actor;
use App\Models\CatalogTitle;
use App\Models\CatalogTitleRecommendation;
use App\Models\Country;
use App\Models\Episode;
use App\Models\Genre;
use App\Models\LicensedMedia;
use App\Models\Season;
use App\Models\SeasonvarImportEvent;
use App\Models\SeasonvarImportRun;
use App\Models\Source;
use App\Models\SourcePage;
use App\Models\SourcePageSnapshot;
use App\Models\Taxonomy;
use App\Models\Translation;
use App\Services\Catalog\CatalogMetadataDeduplicator;
use App\Services\Media\ExternalMediaMetadata;
use App\Services\Media\MediaSourceHealthManager;
use App\Services\Seasonvar\SeasonvarCatalogImporter;
use App\Services\Seasonvar\SeasonvarCatalogParser;
use App\Services\Seasonvar\SeasonvarImportPipeline;
use App\Services\Seasonvar\SeasonvarImportProcessInspector;
use App\Services\Seasonvar\SeasonvarMediaAvailabilityChecker;
use App\Services\Seasonvar\SeasonvarRefreshPlanner;
use App\Services\Seasonvar\SeasonvarSitemapMirror;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SeasonvarImportMaintenanceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'seasonvar.crawl_delay_seconds' => 0,
            'seasonvar.import.chunk_size' => 5,
            'seasonvar.queue.lock_store' => 'array',
            'seasonvar.media_check.chunk_size' => 5,
            'seasonvar.media_identity.chunk_size' => 5,
        ]);
    }

    public function test_it_skips_successfully_when_import_lock_is_held(): void
    {
        $this->fakeImportProcessInspector(running: true, checks: ['fake-posix:alive', 'fake-ps:match']);
        $lock = Cache::store((string) config('seasonvar.queue.lock_store'))->lock('seasonvar-import', 60);
        $this->assertTrue($lock->get());

        try {
            $this->artisan('seasonvar:import', ['--no-discovery' => true])
                ->expectsOutputToContain('Активный процесс обновления подтвержден')
                ->expectsOutputToContain('Обновление уже запущено')
                ->assertExitCode(0);
        } finally {
            $lock->release();
        }
    }

    public function test_it_releases_orphaned_import_lock_when_no_process_is_confirmed(): void
    {
        Http::preventStrayRequests();
        $this->fakeImportProcessInspector(running: false, checks: ['fake-posix:missing', 'fake-ps:no-match']);
        $lock = Cache::store((string) config('seasonvar.queue.lock_store'))->lock('seasonvar-import', 60);
        $this->assertTrue($lock->get());

        try {
            $this->artisan('seasonvar:import', ['--no-discovery' => true])
                ->expectsOutputToContain('Найдена блокировка импорта')
                ->expectsOutputToContain('Проверки процесса')
                ->assertExitCode(0);
        } finally {
            $lock->forceRelease();
        }

        $run = SeasonvarImportRun::query()->latest('id')->firstOrFail();

        $this->assertSame('completed', $run->status);
        $this->assertSame(99999, $run->process_id);
        $this->assertSame('test-host', $run->process_host);
        $this->assertSame('php artisan seasonvar:import --no-discovery', $run->process_command);
    }

    public function test_it_recovers_an_unconfirmed_import_lock_when_previous_run_stopped_without_finishing(): void
    {
        Http::preventStrayRequests();
        $this->fakeImportProcessInspector(running: false, checks: ['fake-proc:missing', 'fake-pgrep:no-match']);
        $lock = Cache::store((string) config('seasonvar.queue.lock_store'))->lock('seasonvar-import', 60);
        $this->assertTrue($lock->get());
        $unconfirmedRun = SeasonvarImportRun::query()->create([
            'mode' => 'sitemap',
            'status' => 'running',
            'force' => false,
            'forever' => false,
            'started_at' => now()->subHour(),
        ]);
        SeasonvarImportRun::query()
            ->whereKey($unconfirmedRun->id)
            ->update([
                'created_at' => now()->subHour(),
                'updated_at' => now()->subMinutes(10),
            ]);

        try {
            $this->artisan('seasonvar:import', ['--no-discovery' => true])
                ->expectsOutputToContain('Найден зависший запуск импорта')
                ->assertExitCode(0);
        } finally {
            $lock->forceRelease();
        }

        $unconfirmedRun->refresh();

        $this->assertSame('failed', $unconfirmedRun->status);
        $this->assertSame('Предыдущий запуск не имеет подтвержденного активного Linux-процесса и был закрыт автоматически.', $unconfirmedRun->last_error);
        $this->assertNotNull($unconfirmedRun->finished_at);
    }

    public function test_it_marks_malformed_nested_source_urls_as_unavailable_without_requesting_them(): void
    {
        Http::preventStrayRequests();

        $source = Source::factory()->create([
            'code' => 'seasonvar',
            'base_url' => 'https://seasonvar.ru',
            'crawl_delay_seconds' => 0,
        ]);
        $url = 'https://seasonvar.ru/serial-615--Bez_sleda_pssmtlk-1-season.html/serial-29641-Mariya_Vern_psydwch-8-season.html';

        SourcePage::factory()->create([
            'source_id' => $source->id,
            'url' => $url,
            'url_hash' => hash('sha256', $url),
            'page_type' => 'serial',
            'parse_status' => 'pending',
            'import_status' => 'pending',
        ]);

        $this->artisan('seasonvar:import', ['--no-discovery' => true])
            ->assertExitCode(0);

        $this->assertDatabaseHas('source_pages', [
            'url' => $url,
            'parse_status' => 'failed',
            'import_status' => 'gone',
            'error_message' => 'Некорректная склеенная ссылка',
        ]);
    }

    public function test_it_checks_old_media_without_check_status_during_import_cycle(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'media.example.com/*' => Http::response('', 206),
        ]);

        $catalogTitle = CatalogTitle::factory()->create();
        $media = LicensedMedia::factory()->create([
            'catalog_title_id' => $catalogTitle->id,
            'playback_url' => 'https://media.example.com/video/s01e01.mp4',
            'path' => 'https://media.example.com/video/s01e01.mp4',
            'status' => 'draft',
            'check_status' => null,
            'checked_at' => null,
        ]);

        $this->artisan('seasonvar:import', ['--no-discovery' => true])
            ->assertExitCode(0);

        $media->refresh();

        $this->assertSame('published', $media->status);
        $this->assertSame('available', $media->check_status);
        $this->assertSame(206, $media->last_http_status);
        $this->assertNotNull($media->checked_at);

        $event = SeasonvarImportEvent::query()
            ->where('event', 'seasonvar-media-url-checked')
            ->firstOrFail();

        $this->assertSame('[redacted-url]', $event->context['url']);
        $this->assertSame(206, $event->context['http_status']);
        $this->assertStringNotContainsString('media.example.com', (string) json_encode($event->context));
    }

    public function test_it_limits_external_media_health_checks_per_import_cycle(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'media.example.com/*' => Http::response('', 206),
        ]);
        config([
            'seasonvar.media_check.max_per_cycle' => 2,
            'seasonvar.media_check.retries' => 1,
        ]);

        $catalogTitle = CatalogTitle::factory()->create();

        collect(range(1, 4))->each(function (int $episodeNumber) use ($catalogTitle): void {
            LicensedMedia::factory()->create([
                'catalog_title_id' => $catalogTitle->id,
                'playback_url' => "https://media.example.com/video/s01e{$episodeNumber}.mp4",
                'path' => "https://media.example.com/video/s01e{$episodeNumber}.mp4",
                'status' => 'draft',
                'check_status' => null,
                'checked_at' => null,
                'next_check_at' => null,
            ]);
        });

        $this->artisan('seasonvar:import', ['--no-discovery' => true])
            ->assertExitCode(0);

        $this->assertSame(2, LicensedMedia::query()->whereNotNull('checked_at')->count());
        $this->assertSame(2, LicensedMedia::query()->whereNull('checked_at')->count());
        Http::assertSentCount(2);

        $event = SeasonvarImportEvent::query()
            ->where('event', 'seasonvar-media-backlog-started')
            ->firstOrFail();

        $this->assertSame(2, $event->context['max_per_cycle']);
    }

    public function test_media_availability_checks_reject_unsafe_urls_and_do_not_follow_redirects(): void
    {
        Http::preventStrayRequests();
        config(['seasonvar.media_check.max_response_bytes' => 16]);
        Http::fake([
            'media.example.com/redirect.m3u8' => Http::response('', 302, [
                'Location' => 'http://127.0.0.1/private.m3u8',
            ]),
            'media.example.com/oversized.mp4' => Http::response(str_repeat('x', 17), 206),
            'media.example.com/manifest.m3u8' => Http::response("#EXTM3U\n", 200),
        ]);
        $events = [];
        $progress = function (string $event, array $context) use (&$events): void {
            $events[] = compact('event', 'context');
        };
        $checker = app(SeasonvarMediaAvailabilityChecker::class);

        $unsafe = $checker->check('https://127.0.0.1/private.m3u8', $progress);
        $redirect = $checker->check('https://media.example.com/redirect.m3u8', $progress);
        $oversized = $checker->check('https://media.example.com/oversized.mp4', $progress);
        $manifest = $checker->check('https://media.example.com/manifest.m3u8', $progress);

        $this->assertFalse($unsafe->available);
        $this->assertSame('invalid_url', $unsafe->checkStatus);
        $this->assertSame('invalid_url', $unsafe->errorCategory?->value);
        $this->assertTrue($unsafe->permanentFailure);
        $this->assertFalse($redirect->available);
        $this->assertSame('unavailable', $redirect->checkStatus);
        $this->assertSame('unsafe_redirect', $redirect->errorCategory?->value);
        $this->assertSame(302, $redirect->httpStatus);
        $this->assertSame('response_too_large', $oversized->errorCategory?->value);
        $this->assertTrue($oversized->permanentFailure);
        $this->assertTrue($manifest->available);
        $this->assertSame('[redacted-url]', $events[0]['context']['url']);
        $this->assertSame('[redacted-url]', $events[1]['context']['url']);
        Http::assertSentCount(3);
    }

    public function test_media_health_uses_failure_thresholds_and_recovers_after_a_successful_check(): void
    {
        Http::preventStrayRequests();
        config([
            'seasonvar.media_check.retries' => 1,
            'seasonvar.media_check.unavailable_after_failures' => 3,
            'seasonvar.media_check.retry_base_minutes' => 5,
        ]);
        Http::fakeSequence('media.example.com/*')
            ->push('', 503)
            ->push('', 503)
            ->push('', 503)
            ->push('', 206, ['Content-Length' => '1'])
            ->push('', 404);

        $media = LicensedMedia::factory()->create([
            'playback_url' => 'https://media.example.com/video/s01e01.mp4',
            'path' => 'https://media.example.com/video/s01e01.mp4',
            'status' => 'published',
            'check_status' => 'available',
            'health_status' => 'active',
        ]);
        $checker = app(SeasonvarMediaAvailabilityChecker::class);
        $health = app(MediaSourceHealthManager::class);

        foreach ([1, 2] as $failureCount) {
            $health->record($media, $checker->check((string) $media->playback_url));
            $media->refresh();

            $this->assertSame('degraded', $media->health_status->value);
            $this->assertSame('published', $media->status);
            $this->assertSame($failureCount, $media->consecutive_failures);
            $this->assertSame('provider_temporary', $media->last_error_category?->value);
            $this->assertNotNull($media->next_check_at);
        }

        $health->record($media, $checker->check((string) $media->playback_url));
        $media->refresh();

        $this->assertSame('unavailable', $media->health_status->value);
        $this->assertSame('unavailable', $media->status);
        $this->assertSame(3, $media->consecutive_failures);

        $health->record($media, $checker->check((string) $media->playback_url));
        $media->refresh();

        $this->assertSame('active', $media->health_status->value);
        $this->assertSame('published', $media->status);
        $this->assertSame(0, $media->consecutive_failures);
        $this->assertNull($media->last_error_category);
        $this->assertNotNull($media->last_successful_check_at);
        $this->assertNotNull($media->check_latency_ms);

        $health->record($media, $checker->check((string) $media->playback_url));
        $media->refresh();

        $this->assertSame('unavailable', $media->health_status->value);
        $this->assertSame(1, $media->consecutive_failures);
        $this->assertSame('not_found', $media->last_error_category?->value);

        $media->update(['health_status' => 'disabled']);
        $health->record(
            $media,
            new MediaHealthCheckResultData(true, 'available', 206, now(), 1),
        );

        $this->assertSame('disabled', $media->fresh()->health_status->value);
    }

    public function test_media_health_classifies_timeouts_without_logging_the_source_url(): void
    {
        Http::preventStrayRequests();
        config(['seasonvar.media_check.retries' => 1]);
        Http::fake([
            'media.example.com/*' => Http::failedConnection('cURL error 28: Operation timed out'),
        ]);
        $events = [];

        $result = app(SeasonvarMediaAvailabilityChecker::class)->check(
            'https://media.example.com/private/source.mp4?token=secret',
            function (string $event, array $context) use (&$events): void {
                $events[] = compact('event', 'context');
            },
        );

        $this->assertFalse($result->available);
        $this->assertSame('timeout', $result->errorCategory?->value);
        $this->assertSame('check_failed', $result->checkStatus);
        $this->assertStringNotContainsString(
            'private/source.mp4',
            (string) json_encode($events, JSON_THROW_ON_ERROR),
        );
        $this->assertStringNotContainsString(
            'token=secret',
            (string) json_encode($events, JSON_THROW_ON_ERROR),
        );
    }

    public function test_media_health_checks_are_not_skipped_by_a_local_request_budget(): void
    {
        Http::preventStrayRequests();
        config(['seasonvar.media_check.retries' => 1]);
        Http::fake([
            'media.example.com/*' => Http::sequence()
                ->push('', 206, ['Content-Length' => '0'])
                ->push('', 206, ['Content-Length' => '0']),
        ]);
        $checker = app(SeasonvarMediaAvailabilityChecker::class);

        $first = $checker->check('https://media.example.com/first.mp4');
        $second = $checker->check('https://media.example.com/second.mp4');

        $this->assertTrue($first->available);
        $this->assertTrue($second->available);
        $this->assertSame('available', $second->checkStatus);
        $this->assertNull($second->errorCategory);
        Http::assertSentCount(2);
    }

    public function test_it_marks_legacy_parsed_source_pages_as_imported(): void
    {
        Http::preventStrayRequests();

        $source = Source::factory()->create([
            'code' => 'seasonvar',
            'base_url' => 'https://seasonvar.ru',
            'crawl_delay_seconds' => 0,
        ]);
        $url = 'https://seasonvar.ru/serial-615--Bez_sleda_pssmtlk-1-season.html';
        $page = SourcePage::factory()->create([
            'source_id' => $source->id,
            'url' => $url,
            'url_hash' => hash('sha256', $url),
            'page_type' => 'serial',
            'parse_status' => 'parsed',
            'import_status' => 'pending',
            'last_crawled_at' => now()->subHours(23),
        ]);

        $this->artisan('seasonvar:import', ['--no-discovery' => true])
            ->assertExitCode(0);

        $page->refresh();

        $this->assertSame('parsed', $page->import_status);
        $this->assertNotNull($page->last_imported_at);
    }

    public function test_it_rebuilds_catalog_title_recommendations_after_import_cycle(): void
    {
        Http::preventStrayRequests();
        config([
            'seasonvar.media_check.enabled' => false,
            'seasonvar.recommendations.min_score' => 600,
        ]);

        $genre = Genre::query()->create([
            'name' => 'Детектив',
            'slug' => 'detektiv',
        ]);
        $catalogTitle = CatalogTitle::factory()->create([
            'title' => 'Импортный главный сериал',
            'slug' => 'importnyi-glavnyi-serial',
            'year' => 2020,
        ]);
        $recommendedTitle = CatalogTitle::factory()->create([
            'title' => 'Импортный похожий сериал',
            'slug' => 'importnyi-poxozij-serial',
            'year' => 2021,
        ]);
        $catalogTitle->genres()->attach($genre->id);
        $recommendedTitle->genres()->attach($genre->id);
        LicensedMedia::factory()->create([
            'catalog_title_id' => $recommendedTitle->id,
            'status' => 'published',
            'published_at' => now(),
        ]);

        $this->artisan('seasonvar:import', ['--no-discovery' => true])
            ->assertExitCode(0);

        $recommendation = CatalogTitleRecommendation::query()
            ->where('catalog_title_id', $catalogTitle->id)
            ->where('recommended_title_id', $recommendedTitle->id)
            ->first();
        $run = SeasonvarImportRun::query()->latest('id')->firstOrFail();

        $this->assertNotNull($recommendation);
        $this->assertSame(1, $recommendation->rank);
        $this->assertSame('v2', $recommendation->algorithm_version);
        $this->assertSame('full', $run->summary['last_recommendations']['mode']);
        $this->assertSame('v2', $run->summary['last_recommendations']['algorithm_version']);
        $this->assertSame(2, $run->summary['last_recommendations']['titles']);
        $this->assertSame(1, $run->summary['last_recommendations']['titles_with_recommendations']);
        $this->assertSame(1, $run->summary['last_recommendations']['titles_without_recommendations']);
        $this->assertSame(12, $run->summary['last_recommendations']['max_per_title']);
        $this->assertGreaterThan(0, $run->summary['last_recommendations']['stored']);
        $this->assertGreaterThanOrEqual(0, $run->summary['last_recommendations']['duration_ms']);
    }

    public function test_it_cleans_invalid_catalog_relation_names_during_import_cycle(): void
    {
        Http::preventStrayRequests();
        config([
            'seasonvar.media_check.enabled' => false,
        ]);

        $catalogTitle = CatalogTitle::factory()->create();
        $validCountry = Country::query()->create([
            'name' => 'Россия',
            'slug' => 'rossiia',
        ]);
        $invalidCountry = Country::query()->create([
            'name' => 'Добро пожаловать в Англию. Типичная жизнь в деревушке здесь не отличается от нашей.',
            'slug' => 'opisanie-vmesto-strany',
        ]);
        $cityCountry = Country::query()->create([
            'name' => 'Москва',
            'slug' => 'moskva',
        ]);
        $validTranslation = Translation::query()->create([
            'name' => 'LostFilm',
            'slug' => 'lostfilm',
        ]);
        $invalidTranslation = Translation::query()->create([
            'name' => 'США',
            'slug' => 'ssa',
        ]);
        $legacyInvalidCountry = Taxonomy::query()->create([
            'type' => 'country',
            'name' => 'Главные герои этого сериала - брат и сестра. Это описание не является страной.',
            'slug' => 'legacy-opisanie-vmesto-strany',
        ]);

        $catalogTitle->countries()->attach([$validCountry->id, $invalidCountry->id, $cityCountry->id]);
        $catalogTitle->translations()->attach([$validTranslation->id, $invalidTranslation->id]);
        $catalogTitle->taxonomies()->attach($legacyInvalidCountry->id);

        $this->artisan('seasonvar:import', ['--no-discovery' => true])
            ->assertExitCode(0);

        $run = SeasonvarImportRun::query()->latest('id')->firstOrFail();
        $cleanupEvents = SeasonvarImportEvent::query()
            ->where('seasonvar_import_run_id', $run->id)
            ->where('event', 'catalog-relations-cleanup-complete')
            ->orderBy('id')
            ->get();

        $this->assertDatabaseHas('countries', ['id' => $validCountry->id]);
        $this->assertDatabaseHas('translations', ['id' => $validTranslation->id]);
        $this->assertDatabaseMissing('countries', ['id' => $invalidCountry->id]);
        $this->assertDatabaseMissing('countries', ['id' => $cityCountry->id]);
        $this->assertDatabaseMissing('translations', ['id' => $invalidTranslation->id]);
        $this->assertDatabaseMissing('taxonomies', ['id' => $legacyInvalidCountry->id]);
        $this->assertDatabaseMissing('catalog_title_country', [
            'catalog_title_id' => $catalogTitle->id,
            'country_id' => $invalidCountry->id,
        ]);
        $this->assertDatabaseMissing('catalog_title_country', [
            'catalog_title_id' => $catalogTitle->id,
            'country_id' => $cityCountry->id,
        ]);
        $this->assertDatabaseMissing('catalog_title_translation', [
            'catalog_title_id' => $catalogTitle->id,
            'translation_id' => $invalidTranslation->id,
        ]);
        $this->assertDatabaseMissing('catalog_title_taxonomy', [
            'catalog_title_id' => $catalogTitle->id,
            'taxonomy_id' => $legacyInvalidCountry->id,
        ]);
        $this->assertGreaterThanOrEqual(3, $run->summary['last_relation_cleanup']['records_removed']);
        $this->assertGreaterThanOrEqual(3, $run->summary['last_relation_cleanup']['links_removed']);
        $this->assertSame(1, $run->summary['last_relation_cleanup']['legacy_records_removed']);
        $this->assertSame(1, $run->summary['last_relation_cleanup']['legacy_links_removed']);
        $this->assertCount(2, $cleanupEvents);
        $this->assertGreaterThanOrEqual(3, (int) $cleanupEvents->first()->context['records_removed']);
        $this->assertSame(0, (int) $cleanupEvents->last()->context['records_removed']);
    }

    public function test_it_merges_canonical_catalog_relations_and_preserves_title_links(): void
    {
        $firstTitle = CatalogTitle::factory()->create();
        $secondTitle = CatalogTitle::factory()->create();
        $latinActor = Actor::query()->create([
            'name' => 'Atsuko Tanaka',
            'slug' => 'atsuko-tanaka-source',
            'source_url' => 'https://seasonvar.ru/actor/Atsuko%20Tanaka',
        ]);
        $cyrillicActor = Actor::query()->create([
            'name' => 'Ацуко Танака',
            'slug' => 'atsuko-tanaka-cyrillic',
            'source_url' => 'https://seasonvar.ru/actor/%D0%90%D1%86%D1%83%D0%BA%D0%BE%20%D0%A2%D0%B0%D0%BD%D0%B0%D0%BA%D0%B0',
        ]);
        $invalidActor = Actor::query()->create([
            'name' => 'Сериал Akter 1 сезон полностью',
            'slug' => 'serial-akter-1-season',
        ]);

        $firstTitle->actors()->attach([$latinActor->id, $cyrillicActor->id, $invalidActor->id]);
        $secondTitle->actors()->attach($cyrillicActor->id);

        $result = app(CatalogMetadataDeduplicator::class)->run();
        $actor = Actor::query()->sole();

        $this->assertSame('atsuko-tanaka', $actor->slug);
        $this->assertSame('Ацуко Танака', $actor->name);
        $this->assertEqualsCanonicalizing([$firstTitle->id, $secondTitle->id], $actor->catalogTitles()->pluck('catalog_titles.id')->all());
        $this->assertSame(1, $result['records_removed']);
        $this->assertSame(1, $result['records_merged']);
        $this->assertSame(1, $result['links_moved']);
        $this->assertSame(1, $result['duplicate_links_removed']);

        $secondResult = app(CatalogMetadataDeduplicator::class)->run();

        $this->assertSame(0, $secondResult['records_removed']);
        $this->assertSame(0, $secondResult['records_merged']);
        $this->assertDatabaseCount('actors', 1);
        $this->assertDatabaseCount('catalog_title_actor', 2);
    }

    public function test_it_canonicalizes_relation_slugs_without_transient_unique_collisions(): void
    {
        $firstActor = Actor::query()->create([
            'name' => 'Мицуиси Кэн',
            'slug' => 'micuisi-ken',
        ]);
        $secondActor = Actor::query()->create([
            'name' => 'Митсуйши Кен',
            'slug' => 'mitsuisi-ken',
        ]);

        app(CatalogMetadataDeduplicator::class)->run();

        $this->assertSame('mitsuisi-ken', $firstActor->fresh()->slug);
        $this->assertSame('mitsuishi-ken', $secondActor->fresh()->slug);
        $this->assertDatabaseCount('actors', 2);
    }

    public function test_it_processes_all_pending_pages_across_import_chunks(): void
    {
        Http::preventStrayRequests();
        config([
            'seasonvar.import.chunk_size' => 1,
            'seasonvar.media_check.enabled' => false,
        ]);

        $source = Source::factory()->create([
            'code' => 'seasonvar',
            'base_url' => 'https://seasonvar.ru',
            'crawl_delay_seconds' => 0,
        ]);
        $urls = collect(range(47915, 47917))
            ->map(fn (int $id): string => "https://seasonvar.ru/serial-{$id}-CHernyj_spisok_Na_kuhne-1-season.html");
        $body = $this->refreshPlannerSeasonPageHtml([
            1 => 'Начало',
        ]);

        foreach ($urls as $url) {
            SourcePage::factory()->create([
                'source_id' => $source->id,
                'url' => $url,
                'url_hash' => hash('sha256', $url),
                'page_type' => 'serial',
                'parse_status' => 'pending',
                'import_status' => 'pending',
                'last_imported_at' => null,
            ]);
        }

        Http::fake([
            'seasonvar.ru/*' => Http::response($body),
        ]);

        $this->artisan('seasonvar:import', ['--no-discovery' => true])
            ->assertExitCode(0);

        $run = SeasonvarImportRun::query()->latest('id')->firstOrFail();

        $this->assertSame(3, $run->selected);
        $this->assertSame(3, SourcePage::query()->whereIn('url', $urls)->where('parse_status', 'parsed')->count());
    }

    public function test_it_updates_import_run_counters_after_each_processed_page_chunk(): void
    {
        Http::preventStrayRequests();
        config([
            'seasonvar.import.chunk_size' => 1,
            'seasonvar.media_check.enabled' => false,
        ]);

        $source = Source::factory()->create([
            'code' => 'seasonvar',
            'base_url' => 'https://seasonvar.ru',
            'crawl_delay_seconds' => 0,
        ]);
        $urls = collect(range(47915, 47916))
            ->map(fn (int $id): string => "https://seasonvar.ru/serial-{$id}-CHernyj_spisok_Na_kuhne-1-season.html");
        $body = $this->refreshPlannerSeasonPageHtml([
            1 => 'Начало',
        ]);

        foreach ($urls as $url) {
            SourcePage::factory()->create([
                'source_id' => $source->id,
                'url' => $url,
                'url_hash' => hash('sha256', $url),
                'page_type' => 'serial',
                'parse_status' => 'pending',
                'import_status' => 'pending',
                'last_imported_at' => null,
            ]);
        }

        Http::fake([
            'seasonvar.ru/*' => Http::response($body),
        ]);

        $observedCounters = [];
        $run = app(SeasonvarImportPipeline::class)->run(
            discover: false,
            progress: function (string $event) use (&$observedCounters): void {
                if ($event !== 'seasonvar-import-page-chunk-complete') {
                    return;
                }

                $latestRun = SeasonvarImportRun::query()->latest('id')->firstOrFail();
                $observedCounters[] = [
                    'selected' => (int) $latestRun->selected,
                    'parsed' => (int) $latestRun->parsed,
                ];
            },
        );

        $this->assertSame([
            ['selected' => 1, 'parsed' => 1],
            ['selected' => 2, 'parsed' => 2],
        ], $observedCounters);
        $this->assertSame(2, $run->selected);
        $this->assertSame(2, $run->parsed);
    }

    public function test_it_backfills_all_legacy_source_page_statuses_across_chunks(): void
    {
        Http::preventStrayRequests();
        config([
            'seasonvar.import.chunk_size' => 1,
            'seasonvar.media_check.enabled' => false,
        ]);

        $source = Source::factory()->create([
            'code' => 'seasonvar',
            'base_url' => 'https://seasonvar.ru',
            'crawl_delay_seconds' => 0,
        ]);

        foreach (range(1, 3) as $index) {
            $url = "https://seasonvar.ru/serial-615--Bez_sleda_pssmtlk-{$index}-season.html";

            SourcePage::factory()->create([
                'source_id' => $source->id,
                'url' => $url,
                'url_hash' => hash('sha256', $url),
                'page_type' => 'serial',
                'parse_status' => 'parsed',
                'import_status' => 'pending',
                'last_crawled_at' => now()->subHours(23),
                'last_imported_at' => null,
            ]);
        }

        $this->artisan('seasonvar:import', ['--no-discovery' => true])
            ->assertExitCode(0);

        $this->assertSame(3, SourcePage::query()->where('parse_status', 'parsed')->where('import_status', 'parsed')->count());
    }

    public function test_it_stores_all_urls_from_recursive_sitemap_completely(): void
    {
        Http::preventStrayRequests();

        $serialUrls = collect(range(1, 6))
            ->map(fn (int $index): string => "https://seasonvar.ru/serial-700{$index}-Polnyj_import-1-season.html");
        $actorUrl = 'https://seasonvar.ru/actor/ivan-ivanov';

        Http::fake([
            'seasonvar.ru/robots.txt' => Http::response("User-agent: *\nAllow: /\n"),
            'seasonvar.ru/sitemap_index.xml' => Http::response($this->sitemapIndexXml([
                'https://seasonvar.ru/nested-index.xml',
                'https://seasonvar.ru/plain-urlset.xml',
            ])),
            'seasonvar.ru/nested-index.xml' => Http::response($this->sitemapIndexXml([
                'https://seasonvar.ru/serials.xml.gz',
            ])),
            'seasonvar.ru/serials.xml.gz' => Http::response(gzencode($this->sitemapUrlsetXml([
                ...$serialUrls->take(4)->all(),
                $actorUrl,
            ]))),
            'seasonvar.ru/plain-urlset.xml' => Http::response($this->sitemapUrlsetXml(
                $serialUrls->skip(4)->values()->all(),
            )),
        ]);

        $mirror = app(SeasonvarSitemapMirror::class)->mirror();
        $stored = app(SeasonvarCatalogImporter::class)->storeDiscoveredUrls($mirror['urls']);

        $this->assertSame(7, count($mirror['urls']));
        $this->assertSame(7, $stored);
        $this->assertSame(6, SourcePage::query()->where('page_type', 'serial')->count());
        $this->assertDatabaseHas('source_pages', [
            'url' => $actorUrl,
            'page_type' => 'actor',
        ]);

        foreach ($serialUrls as $serialUrl) {
            $this->assertDatabaseHas('source_pages', [
                'url' => $serialUrl,
                'page_type' => 'serial',
            ]);
        }
    }

    public function test_refresh_planner_respects_automatic_and_explicit_page_type_selection(): void
    {
        config([
            'seasonvar.page_types.actor.enabled' => true,
            'seasonvar.page_types.actor.automatic' => false,
            'seasonvar.page_types.genre.enabled' => true,
            'seasonvar.page_types.genre.automatic' => true,
            'seasonvar.page_types.genre.chunk_size' => 1,
        ]);
        $source = Source::factory()->create([
            'code' => 'seasonvar',
            'base_url' => 'https://seasonvar.ru',
            'crawl_delay_seconds' => 0,
        ]);
        $actorPage = SourcePage::factory()->create([
            'source_id' => $source->id,
            'url' => 'https://seasonvar.ru/actor/1001-aleksandr-ivanov',
            'url_hash' => hash('sha256', 'https://seasonvar.ru/actor/1001-aleksandr-ivanov'),
            'page_type' => 'actor',
            'parse_status' => 'pending',
        ]);
        $genrePage = SourcePage::factory()->create([
            'source_id' => $source->id,
            'url' => 'https://seasonvar.ru/genre/drama',
            'url_hash' => hash('sha256', 'https://seasonvar.ru/genre/drama'),
            'page_type' => 'genre',
            'parse_status' => 'pending',
        ]);

        $automatic = collect(app(SeasonvarRefreshPlanner::class)->pageChunksForImportCycle(
            10,
            now()->subDay(),
        ))->flatten(1)->pluck('id')->all();
        $explicitActor = collect(app(SeasonvarRefreshPlanner::class)->pageChunksForImportCycle(
            10,
            now()->subDay(),
            pageTypes: ['actor'],
        ))->flatten(1)->pluck('id')->all();

        $this->assertSame([$genrePage->id], $automatic);
        $this->assertSame([$actorPage->id], $explicitActor);
    }

    public function test_page_type_option_runs_only_an_explicitly_enabled_metadata_handler(): void
    {
        Http::preventStrayRequests();
        config([
            'seasonvar.media_check.enabled' => false,
            'seasonvar.page_types.actor.enabled' => true,
            'seasonvar.page_types.actor.automatic' => false,
        ]);
        $source = Source::factory()->create([
            'code' => 'seasonvar',
            'base_url' => 'https://seasonvar.ru',
            'crawl_delay_seconds' => 0,
        ]);
        $url = 'https://seasonvar.ru/actor/1001-aleksandr-ivanov';
        SourcePage::factory()->create([
            'source_id' => $source->id,
            'url' => $url,
            'url_hash' => hash('sha256', $url),
            'page_type' => 'actor',
            'parse_status' => 'pending',
        ]);
        Http::fake([$url => Http::response('<html><head><title>Александр Иванов</title></head><body><h1>Александр Иванов</h1></body></html>')]);

        $this->artisan('seasonvar:import', [
            '--no-discovery' => true,
            '--page-type' => ['actor'],
        ])->assertExitCode(0);

        $this->assertDatabaseHas('actors', [
            'name' => 'Александр Иванов',
            'source_url' => $url,
        ]);
        $this->artisan('seasonvar:import', [
            '--no-discovery' => true,
            '--page-type' => ['static'],
        ])
            ->expectsOutputToContain('нет разрешённого parser/importer')
            ->assertExitCode(1);
    }

    public function test_it_rechecks_recent_parsed_page_when_existing_episode_has_no_video(): void
    {
        $this->travelTo('2026-07-09 12:00:00');
        Http::preventStrayRequests();
        config(['seasonvar.media_check.enabled' => false]);

        $source = Source::factory()->create([
            'code' => 'seasonvar',
            'base_url' => 'https://seasonvar.ru',
            'crawl_delay_seconds' => 0,
        ]);
        $url = 'https://seasonvar.ru/serial-47915-CHernyj_spisok_Na_kuhne-1-season.html';
        $body = $this->refreshPlannerSeasonPageHtml([
            1 => 'Начало',
            2 => 'Проверка',
        ], ['https://media.example.com/kitchen/cernyi-spisok-na-kuxne-s01e02.mp4']);
        $page = SourcePage::factory()->create([
            'source_id' => $source->id,
            'url' => $url,
            'url_hash' => hash('sha256', $url),
            'page_type' => 'serial',
            'content_hash' => hash('sha256', $body),
            'parse_status' => 'parsed',
            'import_status' => 'parsed',
            'last_imported_at' => now(),
        ]);
        $catalogTitle = CatalogTitle::factory()->create([
            'source_id' => $source->id,
            'source_page_id' => $page->id,
            'external_id' => '47915',
            'slug' => 'chernyi-spisok-na-kuhne',
            'title' => 'Черный список: На кухне',
            'source_url' => $url,
            'source_url_hash' => hash('sha256', $url),
        ]);
        $season = Season::factory()->create([
            'catalog_title_id' => $catalogTitle->id,
            'source_page_id' => $page->id,
            'number' => 1,
            'source_url' => $url,
            'source_url_hash' => hash('sha256', $url),
        ]);
        $firstEpisode = Episode::factory()->create([
            'season_id' => $season->id,
            'number' => 1,
            'title' => 'Начало',
        ]);
        $secondEpisode = Episode::factory()->create([
            'season_id' => $season->id,
            'number' => 2,
            'title' => 'Проверка',
        ]);
        LicensedMedia::factory()->create([
            'catalog_title_id' => $catalogTitle->id,
            'season_id' => $season->id,
            'episode_id' => $firstEpisode->id,
            'title' => '1 серия',
            'storage_disk' => 'seasonvar_parsed',
            'path' => 'https://media.example.com/kitchen/cernyi-spisok-na-kuxne-s01e01.mp4',
            'playback_url' => 'https://media.example.com/kitchen/cernyi-spisok-na-kuxne-s01e01.mp4',
            'status' => 'published',
            'check_status' => 'available',
            'checked_at' => now(),
        ]);

        Http::fake([
            'seasonvar.ru/serial-47915-CHernyj_spisok_Na_kuhne-1-season.html' => Http::response($body),
        ]);

        $this->artisan('seasonvar:import', ['--no-discovery' => true])
            ->assertExitCode(0);

        $this->assertDatabaseHas('licensed_media', [
            'catalog_title_id' => $catalogTitle->id,
            'season_id' => $season->id,
            'episode_id' => $secondEpisode->id,
            'playback_url' => 'https://media.example.com/kitchen/cernyi-spisok-na-kuxne-s01e02.mp4',
            'status' => 'published',
        ]);

        $page->refresh();
        $this->assertSame('parsed', $page->import_status);
        $this->assertSame([], $page->missing_data_flags);
        $this->assertNull($page->retry_after_at);
    }

    public function test_it_does_not_recheck_recent_complete_parsed_page_without_refresh_reason(): void
    {
        Http::preventStrayRequests();
        config(['seasonvar.media_check.enabled' => false]);

        $source = Source::factory()->create([
            'code' => 'seasonvar',
            'base_url' => 'https://seasonvar.ru',
            'crawl_delay_seconds' => 0,
        ]);
        $url = 'https://seasonvar.ru/serial-47915-CHernyj_spisok_Na_kuhne-1-season.html';
        $page = SourcePage::factory()->create([
            'source_id' => $source->id,
            'url' => $url,
            'url_hash' => hash('sha256', $url),
            'page_type' => 'serial',
            'parse_status' => 'parsed',
            'import_status' => 'parsed',
            'last_imported_at' => now(),
        ]);
        $catalogTitle = CatalogTitle::factory()->create([
            'source_id' => $source->id,
            'source_page_id' => $page->id,
            'source_url' => $url,
            'source_url_hash' => hash('sha256', $url),
        ]);
        $season = Season::factory()->create([
            'catalog_title_id' => $catalogTitle->id,
            'source_page_id' => $page->id,
            'number' => 1,
            'source_url' => $url,
            'source_url_hash' => hash('sha256', $url),
        ]);
        $episode = Episode::factory()->create([
            'season_id' => $season->id,
            'number' => 1,
        ]);
        LicensedMedia::factory()->create([
            'catalog_title_id' => $catalogTitle->id,
            'season_id' => $season->id,
            'episode_id' => $episode->id,
            'storage_disk' => 'seasonvar_parsed',
            'path' => 'https://media.example.com/kitchen/cernyi-spisok-na-kuxne-s01e01.mp4',
            'playback_url' => 'https://media.example.com/kitchen/cernyi-spisok-na-kuxne-s01e01.mp4',
            'status' => 'published',
            'check_status' => 'available',
            'checked_at' => now(),
            'quality' => '720p',
            'format' => 'mp4',
        ]);

        $this->artisan('seasonvar:import', ['--no-discovery' => true])
            ->assertExitCode(0);
    }

    public function test_it_does_not_resave_unchanged_parsed_media_during_forced_refresh(): void
    {
        $this->travelTo('2026-07-09 11:00:00');
        Http::preventStrayRequests();
        config(['seasonvar.media_check.enabled' => false]);

        $source = Source::factory()->create([
            'code' => 'seasonvar',
            'base_url' => 'https://seasonvar.ru',
            'crawl_delay_seconds' => 0,
        ]);
        $url = 'https://seasonvar.ru/serial-47915-CHernyj_spisok_Na_kuhne-1-season.html';
        $mediaUrl = 'https://media.example.com/kitchen/cernyi-spisok-na-kuxne-s01e01.720p.mp4';
        $mediaTitle = 'cernyi-spisok-na-kuxne-s01e01.720p.mp4';
        $body = $this->refreshPlannerSeasonPageHtml([
            1 => 'Начало',
        ], [$mediaUrl]);
        $page = SourcePage::factory()->create([
            'source_id' => $source->id,
            'url' => $url,
            'url_hash' => hash('sha256', $url),
            'page_type' => 'serial',
            'content_hash' => hash('sha256', $body),
            'parse_status' => 'parsed',
            'import_status' => 'parsed',
            'last_imported_at' => now(),
        ]);
        $catalogTitle = CatalogTitle::factory()->create([
            'source_id' => $source->id,
            'source_page_id' => $page->id,
            'external_id' => '47915',
            'slug' => 'chernyi-spisok-na-kuhne',
            'title' => 'Черный список: На кухне',
            'source_url' => $url,
            'source_url_hash' => hash('sha256', $url),
        ]);
        $season = Season::factory()->create([
            'catalog_title_id' => $catalogTitle->id,
            'source_page_id' => $page->id,
            'number' => 1,
            'title' => 'Сериал Черный список: На кухне 1 сезон',
            'source_url' => $url,
            'source_url_hash' => hash('sha256', $url),
        ]);
        $episode = Episode::factory()->create([
            'season_id' => $season->id,
            'source_page_id' => $page->id,
            'number' => 1,
            'title' => 'Начало',
            'source_url' => $url.'#1_seriya',
            'source_url_hash' => hash('sha256', $url.'#1_seriya'),
        ]);
        $sourceMediaKey = app(ExternalMediaMetadata::class)->sourceMediaKey(
            'seasonvar',
            $catalogTitle->source_url_hash,
            $season->number,
            $episode->number,
            $url,
            $mediaUrl,
            $mediaTitle,
            '720p',
            'mp4',
        );
        $media = LicensedMedia::factory()->create([
            'catalog_title_id' => $catalogTitle->id,
            'season_id' => $season->id,
            'episode_id' => $episode->id,
            'title' => $mediaTitle,
            'storage_disk' => 'seasonvar_parsed',
            'path' => $mediaUrl,
            'playback_url' => $mediaUrl,
            'source_media_key' => $sourceMediaKey,
            'source_url' => $url,
            'quality' => '720p',
            'translation_name' => null,
            'variant_type' => 'voiceover',
            'variant_name' => null,
            'variant_key' => 'voiceover-default',
            'has_subtitles' => false,
            'format' => 'mp4',
            'status' => 'published',
            'check_status' => 'not_checked',
            'checked_at' => null,
            'published_at' => now(),
        ]);
        $mediaUpdatedAt = $media->updated_at?->toDateTimeString();

        $this->travelTo('2026-07-09 12:00:00');
        Http::fake([
            'seasonvar.ru/serial-47915-CHernyj_spisok_Na_kuhne-1-season.html' => Http::response($body),
        ]);

        $this->artisan('seasonvar:import', [
            'url' => $url,
            '--force' => true,
        ])->assertExitCode(0);

        $media->refresh();

        $this->assertSame($mediaUpdatedAt, $media->updated_at?->toDateTimeString());
    }

    public function test_refresh_planner_prioritizes_pages_with_episodes_without_video(): void
    {
        $source = Source::factory()->create([
            'code' => 'seasonvar',
            'base_url' => 'https://seasonvar.ru',
            'crawl_delay_seconds' => 0,
        ]);
        $missingVideoPage = SourcePage::factory()->create([
            'source_id' => $source->id,
            'page_type' => 'serial',
            'parse_status' => 'parsed',
            'import_status' => 'parsed',
            'last_imported_at' => now()->subDays(10),
            'retry_after_at' => null,
        ]);
        $catalogTitle = CatalogTitle::factory()->create([
            'source_id' => $source->id,
            'source_page_id' => $missingVideoPage->id,
        ]);
        $season = Season::factory()->create([
            'catalog_title_id' => $catalogTitle->id,
            'source_page_id' => $missingVideoPage->id,
            'number' => 1,
        ]);
        Episode::factory()->create([
            'season_id' => $season->id,
            'source_page_id' => $missingVideoPage->id,
            'number' => 1,
        ]);
        $pendingPage = SourcePage::factory()->create([
            'source_id' => $source->id,
            'page_type' => 'serial',
            'parse_status' => 'pending',
            'import_status' => 'pending',
            'last_imported_at' => null,
        ]);
        SourcePage::factory()->create([
            'source_id' => $source->id,
            'page_type' => 'serial',
            'parse_status' => 'parsed',
            'import_status' => 'parsed',
            'last_imported_at' => now()->subDays(30),
        ]);
        $events = [];

        $pages = collect();

        foreach (app(SeasonvarRefreshPlanner::class)->pageChunksForImportCycle(
            1,
            now()->subHours(168),
            null,
            function (string $event, array $context) use (&$events): void {
                $events[] = ['event' => $event, 'context' => $context];
            },
        ) as $chunk) {
            $pages = $pages->merge($chunk);
        }

        $selectedReasons = collect($events)
            ->filter(fn (array $event): bool => $event['context']['selected'] > 0)
            ->pluck('context.reason')
            ->all();

        $this->assertSame([$missingVideoPage->id, $pendingPage->id], $pages->take(2)->pluck('id')->all());
        $this->assertSame(['episodes_without_video', 'pending', 'stale_metadata'], $selectedReasons);
    }

    public function test_refresh_planner_retries_a_bounded_attention_batch_on_every_start(): void
    {
        $source = Source::factory()->create([
            'code' => 'seasonvar',
            'base_url' => 'https://seasonvar.ru',
            'crawl_delay_seconds' => 0,
        ]);
        $attentionPages = collect(range(1, 3))->map(function (int $index) use ($source): SourcePage {
            return SourcePage::factory()->create([
                'source_id' => $source->id,
                'page_type' => 'serial',
                'parse_status' => 'parsed',
                'import_status' => 'missing_data',
                'missing_data_flags' => ['episodes_without_video'],
                'last_imported_at' => now()->subHours(4 - $index),
                'retry_after_at' => now()->addHours(23 + $index),
            ]);
        });
        $events = [];
        $pages = collect();

        foreach (app(SeasonvarRefreshPlanner::class)->pageChunksForImportCycle(
            2,
            now()->subHours(168),
            null,
            function (string $event, array $context) use (&$events): void {
                $events[] = ['event' => $event, 'context' => $context];
            },
        ) as $chunk) {
            $pages = $pages->merge($chunk);
        }

        $this->assertSame($attentionPages->take(2)->pluck('id')->all(), $pages->pluck('id')->all());
        $this->assertSame('needs_attention', $events[0]['context']['reason'] ?? null);
        $this->assertSame(2, $events[0]['context']['selected'] ?? null);
    }

    public function test_refresh_planner_fills_the_attention_batch_with_claimable_pages(): void
    {
        $source = Source::factory()->create([
            'code' => 'seasonvar',
            'base_url' => 'https://seasonvar.ru',
            'crawl_delay_seconds' => 0,
        ]);

        collect(range(1, 2))->each(function (int $index) use ($source): void {
            SourcePage::factory()->create([
                'source_id' => $source->id,
                'page_type' => 'serial',
                'parse_status' => 'parsed',
                'import_status' => 'missing_data',
                'missing_data_flags' => ['episodes_without_video'],
                'last_imported_at' => now()->subHours(4 - $index),
                'retry_after_at' => now()->addHours($index),
                'import_claim_token' => 'live-claim-'.$index,
                'import_claimed_at' => now(),
                'import_claim_expires_at' => now()->addHour(),
                'import_claim_run_id' => null,
            ]);
        });

        $expiredClaimPage = SourcePage::factory()->create([
            'source_id' => $source->id,
            'page_type' => 'serial',
            'parse_status' => 'parsed',
            'import_status' => 'missing_data',
            'missing_data_flags' => ['episodes_without_video'],
            'last_imported_at' => now()->subHour(),
            'retry_after_at' => now()->addHours(3),
            'import_claim_token' => 'expired-claim',
            'import_claimed_at' => now()->subHours(2),
            'import_claim_expires_at' => now()->subHour(),
            'import_claim_run_id' => null,
        ]);
        $unclaimedPage = SourcePage::factory()->create([
            'source_id' => $source->id,
            'page_type' => 'serial',
            'parse_status' => 'parsed',
            'import_status' => 'missing_data',
            'missing_data_flags' => ['episodes_without_video'],
            'last_imported_at' => now(),
            'retry_after_at' => now()->addHours(4),
        ]);
        $pages = collect();

        foreach (app(SeasonvarRefreshPlanner::class)->pageChunksForImportCycle(
            2,
            now()->subHours(168),
        ) as $chunk) {
            $pages = $pages->merge($chunk);
        }

        $this->assertSame([$expiredClaimPage->id, $unclaimedPage->id], $pages->pluck('id')->all());
    }

    public function test_refresh_planner_selects_direct_season_page_when_linked_season_has_no_episodes(): void
    {
        $source = Source::factory()->create([
            'code' => 'seasonvar',
            'base_url' => 'https://seasonvar.ru',
            'crawl_delay_seconds' => 0,
        ]);
        $canonicalPage = SourcePage::factory()->create([
            'source_id' => $source->id,
            'page_type' => 'serial',
            'parse_status' => 'parsed',
            'import_status' => 'parsed',
            'last_imported_at' => now(),
            'retry_after_at' => null,
        ]);
        $seasonUrl = 'https://seasonvar.ru/serial-1750--Amerikanskij_papasha-_pszhdcp-6-sezon.html';
        $seasonPage = SourcePage::factory()->create([
            'source_id' => $source->id,
            'url' => $seasonUrl,
            'url_hash' => hash('sha256', $seasonUrl),
            'page_type' => 'serial',
            'parse_status' => 'parsed',
            'import_status' => 'parsed',
            'last_imported_at' => now(),
            'retry_after_at' => null,
        ]);
        $catalogTitle = CatalogTitle::factory()->create([
            'source_id' => $source->id,
            'source_page_id' => $canonicalPage->id,
        ]);
        Season::factory()->create([
            'catalog_title_id' => $catalogTitle->id,
            'source_page_id' => $canonicalPage->id,
            'source_url' => $seasonUrl,
            'source_url_hash' => hash('sha256', $seasonUrl),
            'number' => 6,
        ]);
        $events = [];
        $pages = collect();

        foreach (app(SeasonvarRefreshPlanner::class)->pageChunksForImportCycle(
            5,
            now()->subHours(168),
            null,
            function (string $event, array $context) use (&$events): void {
                $events[] = ['event' => $event, 'context' => $context];
            },
        ) as $chunk) {
            $pages = $pages->merge($chunk);
        }

        $selectedReasons = collect($events)
            ->filter(fn (array $event): bool => $event['context']['selected'] > 0)
            ->pluck('context.reason')
            ->all();

        $this->assertSame($seasonPage->id, $pages->first()?->id);
        $this->assertSame('seasons_without_episodes', $selectedReasons[0] ?? null);
    }

    public function test_importer_clamps_transaction_attempts_configuration(): void
    {
        $method = new \ReflectionMethod(SeasonvarCatalogImporter::class, 'importTransactionAttempts');

        config(['seasonvar.import.transaction_attempts' => 0]);
        $this->assertSame(1, $method->invoke(app(SeasonvarCatalogImporter::class)));

        config(['seasonvar.import.transaction_attempts' => 5]);
        $this->assertSame(5, $method->invoke(app(SeasonvarCatalogImporter::class)));

        config(['seasonvar.import.transaction_attempts' => 99]);
        $this->assertSame(10, $method->invoke(app(SeasonvarCatalogImporter::class)));
    }

    public function test_it_backfills_missing_media_quality_and_format_during_import_cycle(): void
    {
        Http::preventStrayRequests();

        $catalogTitle = CatalogTitle::factory()->create();
        $media = LicensedMedia::factory()->create([
            'catalog_title_id' => $catalogTitle->id,
            'title' => 'Серия 1 WEB-DL',
            'playback_url' => 'https://media.example.com/video/series.s01e01.1920x1080.mp4',
            'path' => 'https://media.example.com/video/series.s01e01.1920x1080.mp4',
            'status' => 'published',
            'check_status' => 'available',
            'checked_at' => now(),
            'quality' => null,
            'format' => null,
        ]);

        $this->artisan('seasonvar:import', ['--no-discovery' => true])
            ->assertExitCode(0);

        $media->refresh();

        $this->assertSame('1080p', $media->quality);
        $this->assertSame('mp4', $media->format);
    }

    public function test_targeted_url_import_does_not_run_global_media_metadata_backfill(): void
    {
        Http::preventStrayRequests();
        config(['seasonvar.media_check.enabled' => false]);

        $unrelatedTitle = CatalogTitle::factory()->create();
        $unrelatedMedia = LicensedMedia::factory()->create([
            'catalog_title_id' => $unrelatedTitle->id,
            'title' => 'Серия WEB-DL',
            'playback_url' => 'https://media.example.com/unrelated.1920x1080.mp4',
            'path' => 'https://media.example.com/unrelated.1920x1080.mp4',
            'quality' => null,
            'format' => null,
        ]);
        $url = 'https://seasonvar.ru/serial-47915-CHernyj_spisok_Na_kuhne-1-season.html';

        Http::fake([
            'seasonvar.ru/serial-47915-CHernyj_spisok_Na_kuhne-1-season.html' => Http::response(
                $this->refreshPlannerSeasonPageHtml([1 => 'Начало']),
            ),
        ]);

        $this->artisan('seasonvar:import', [
            'url' => $url,
            '--force' => true,
        ])->assertExitCode(0);

        $unrelatedMedia->refresh();

        $this->assertNull($unrelatedMedia->quality);
        $this->assertNull($unrelatedMedia->format);
    }

    public function test_it_backfills_all_missing_media_metadata_across_chunks(): void
    {
        Http::preventStrayRequests();
        config([
            'seasonvar.media_metadata.chunk_size' => 1,
            'seasonvar.media_check.enabled' => false,
        ]);

        $catalogTitle = CatalogTitle::factory()->create();

        foreach (range(1, 3) as $episodeNumber) {
            LicensedMedia::factory()->create([
                'catalog_title_id' => $catalogTitle->id,
                'title' => "{$episodeNumber} серия WEB-DL",
                'playback_url' => "https://media.example.com/video/series.s01e0{$episodeNumber}.1920x1080.mp4",
                'path' => "https://media.example.com/video/series.s01e0{$episodeNumber}.1920x1080.mp4",
                'status' => 'published',
                'check_status' => 'available',
                'checked_at' => now(),
                'quality' => null,
                'format' => null,
            ]);
        }

        $this->artisan('seasonvar:import', ['--no-discovery' => true])
            ->assertExitCode(0);

        $this->assertSame(3, LicensedMedia::query()->where('quality', '1080p')->where('format', 'mp4')->count());
    }

    public function test_it_detects_dvd_media_quality_during_import_cycle(): void
    {
        Http::preventStrayRequests();

        $catalogTitle = CatalogTitle::factory()->create();
        $media = LicensedMedia::factory()->create([
            'catalog_title_id' => $catalogTitle->id,
            'title' => '2 серия [DVD]',
            'playback_url' => 'https://media.example.com/video/show.s01e02.dvd.mp4',
            'path' => 'https://media.example.com/video/show.s01e02.dvd.mp4',
            'status' => 'published',
            'check_status' => 'available',
            'checked_at' => now(),
            'quality' => null,
            'format' => null,
        ]);

        $this->artisan('seasonvar:import', ['--no-discovery' => true])
            ->assertExitCode(0);

        $media->refresh();

        $this->assertSame('480p', $media->quality);
        $this->assertSame('mp4', $media->format);
    }

    public function test_it_backfills_missing_media_source_keys_during_import_cycle(): void
    {
        Http::preventStrayRequests();

        $catalogTitle = CatalogTitle::factory()->create([
            'source_url_hash' => hash('sha256', 'https://seasonvar.ru/serial-615--Bez_sleda_pssmtlk-1-season.html'),
        ]);
        $season = Season::factory()->create([
            'catalog_title_id' => $catalogTitle->id,
            'number' => 1,
        ]);
        $episode = Episode::factory()->create([
            'season_id' => $season->id,
            'number' => 1,
        ]);
        $media = LicensedMedia::factory()->create([
            'catalog_title_id' => $catalogTitle->id,
            'season_id' => $season->id,
            'episode_id' => $episode->id,
            'title' => '1 серия SD/HD',
            'storage_disk' => 'seasonvar_parsed',
            'playback_url' => 'https://media.example.com/video/without-a-trace.s01e01.720p.mp4',
            'path' => 'https://media.example.com/video/without-a-trace.s01e01.720p.mp4',
            'source_url' => 'https://seasonvar.ru/playls2/hash/trans/123/plist.txt?time=1',
            'source_media_key' => null,
            'quality' => null,
            'format' => null,
            'status' => 'published',
            'check_status' => 'available',
            'checked_at' => now(),
        ]);

        $this->artisan('seasonvar:import', ['--no-discovery' => true])
            ->assertExitCode(0);

        $media->refresh();
        $expectedSourceMediaKey = app(ExternalMediaMetadata::class)->sourceMediaKey(
            'seasonvar',
            $catalogTitle->source_url_hash,
            $season->number,
            $episode->number,
            'https://seasonvar.ru/playls2/hash/trans/123/plist.txt?time=1',
            'https://media.example.com/video/without-a-trace.s01e01.720p.mp4',
            '1 серия SD/HD',
            '720p',
            'mp4',
        );

        $this->assertSame($expectedSourceMediaKey, $media->source_media_key);
        $this->assertSame('720p', $media->quality);
        $this->assertSame('mp4', $media->format);
    }

    public function test_unchanged_remote_page_is_reparsed_when_its_metadata_version_is_stale(): void
    {
        Http::preventStrayRequests();
        config(['seasonvar.media_check.enabled' => false]);
        $source = Source::factory()->create([
            'code' => 'seasonvar',
            'base_url' => 'https://seasonvar.ru',
            'crawl_delay_seconds' => 0,
        ]);
        $url = 'https://seasonvar.ru/serial-47915-CHernyj_spisok_Na_kuhne-1-season.html';
        $body = $this->refreshPlannerSeasonPageHtml([1 => 'Начало']);
        $page = SourcePage::factory()->create([
            'source_id' => $source->id,
            'url' => $url,
            'url_hash' => hash('sha256', $url),
            'content_hash' => hash('sha256', $body),
            'parse_status' => 'parsed',
            'import_status' => 'parsed',
            'metadata_parser_version' => 0,
            'metadata_attempted_version' => 0,
        ]);
        $title = CatalogTitle::factory()->create([
            'source_id' => $source->id,
            'source_page_id' => $page->id,
            'external_id' => '47915',
            'source_url' => $url,
            'source_url_hash' => hash('sha256', $url),
            'relation_metadata_version' => 0,
        ]);
        LicensedMedia::factory()->create([
            'catalog_title_id' => $title->id,
            'status' => 'published',
            'published_at' => now(),
        ]);
        Http::fake([
            $url => Http::response($body),
        ]);
        $events = [];

        app(SeasonvarCatalogImporter::class)->parsePage(
            $page,
            function (string $event) use (&$events): void {
                $events[] = $event;
            },
        );

        $this->assertNotContains('page-parse-skipped-unchanged', $events);
        $this->assertSame(SeasonvarCatalogParser::METADATA_VERSION, $page->fresh()->metadata_parser_version);
        $this->assertSame(SeasonvarCatalogParser::METADATA_VERSION, $page->metadata_attempted_version);
        $this->assertSame('present', $page->metadata_presence['genres']);
        $this->assertSame(SeasonvarCatalogParser::METADATA_VERSION, $title->fresh()->relation_metadata_version);
        Http::assertSentCount(1);
    }

    public function test_refresh_planner_reserves_unattempted_snapshots_and_bounds_stale_metadata_refresh(): void
    {
        config(['seasonvar.metadata_backfill.page_limit' => 1]);
        $source = Source::factory()->create();
        $reserved = SourcePage::factory()->create([
            'source_id' => $source->id,
            'parse_status' => 'parsed',
            'import_status' => 'parsed',
            'last_imported_at' => now(),
            'metadata_parser_version' => 0,
            'metadata_attempted_version' => 0,
        ]);
        CatalogTitle::factory()->create([
            'source_id' => $source->id,
            'source_page_id' => $reserved->id,
        ]);
        SourcePageSnapshot::query()->create([
            'source_page_id' => $reserved->id,
            'url' => $reserved->url,
            'content_hash' => hash('sha256', 'reserved'),
            'http_status' => 200,
            'body_bytes' => 64,
            'html' => '<html><head><title>Reserved</title></head><body><h1>Reserved</h1></body></html>',
            'captured_at' => now(),
        ]);
        $withoutSnapshot = SourcePage::factory()->create([
            'source_id' => $source->id,
            'parse_status' => 'parsed',
            'import_status' => 'parsed',
            'last_imported_at' => now()->subWeeks(2),
            'metadata_parser_version' => 0,
            'metadata_attempted_version' => 0,
        ]);
        $attempted = SourcePage::factory()->create([
            'source_id' => $source->id,
            'parse_status' => 'parsed',
            'import_status' => 'parsed',
            'last_imported_at' => now()->subWeeks(2),
            'metadata_parser_version' => 0,
            'metadata_attempted_version' => SeasonvarCatalogParser::METADATA_VERSION,
        ]);
        SourcePageSnapshot::query()->create([
            'source_page_id' => $attempted->id,
            'url' => $attempted->url,
            'content_hash' => hash('sha256', 'attempted'),
            'http_status' => 200,
            'body_bytes' => 64,
            'html' => '<html><body>Rejected snapshot</body></html>',
            'captured_at' => now(),
        ]);
        $events = [];
        $selected = collect();

        foreach (app(SeasonvarRefreshPlanner::class)->pageChunksForImportCycle(
            10,
            now()->subWeek(),
            null,
            function (string $event, array $context) use (&$events): void {
                $events[] = compact('event', 'context');
            },
        ) as $pages) {
            $selected = $selected->merge($pages);
        }

        $this->assertSame([$withoutSnapshot->id], $selected->pluck('id')->all());
        $this->assertNotContains($reserved->id, $selected->pluck('id')->all());
        $this->assertNotContains($attempted->id, $selected->pluck('id')->all());
        $this->assertSame('stale_metadata', collect($events)->first()['context']['reason']);
    }

    public function test_import_cycle_persists_prefixed_metadata_backfill_counters_separately_from_page_failures(): void
    {
        Http::preventStrayRequests();
        config(['seasonvar.media_check.enabled' => false]);
        $page = SourcePage::factory()->create([
            'parse_status' => 'parsed',
            'import_status' => 'parsed',
            'last_imported_at' => now(),
        ]);
        $title = CatalogTitle::factory()->create([
            'source_id' => $page->source_id,
            'source_page_id' => $page->id,
            'relation_metadata_version' => 0,
        ]);
        LicensedMedia::factory()->create([
            'catalog_title_id' => $title->id,
            'status' => 'published',
            'published_at' => now(),
        ]);
        $html = '<html><head><title>Локальный сериал</title></head><body><h1>Локальный сериал</h1><div class="pgs-sinfo_list">Студия: Bones</div></body></html>';
        SourcePageSnapshot::query()->create([
            'source_page_id' => $page->id,
            'url' => $page->url,
            'content_hash' => hash('sha256', $html),
            'http_status' => 200,
            'body_bytes' => mb_strlen($html, '8bit'),
            'html' => $html,
            'captured_at' => now(),
        ]);

        $this->artisan('seasonvar:import', ['--no-discovery' => true])
            ->assertExitCode(0);

        $run = SeasonvarImportRun::query()->latest('id')->firstOrFail();
        $cycleEvent = SeasonvarImportEvent::query()
            ->where('seasonvar_import_run_id', $run->id)
            ->where('event', 'seasonvar-import-cycle-complete')
            ->firstOrFail();

        $this->assertSame(1, $run->summary['last_metadata_backfill']['pages_checked']);
        $this->assertSame(1, $run->summary['last_metadata_backfill']['pages_updated']);
        $this->assertSame(0, $run->summary['last_metadata_backfill']['failed']);
        $this->assertSame(0, $run->failed);
        $this->assertSame(1, $cycleEvent->context['metadata_pages_checked']);
        $this->assertSame(1, $cycleEvent->context['metadata_pages_updated']);
        $this->assertArrayHasKey('metadata_titles_checked', $cycleEvent->context);
        $this->assertArrayHasKey('metadata_titles_updated', $cycleEvent->context);
        $this->assertArrayHasKey('metadata_relations_attached', $cycleEvent->context);
        $this->assertArrayHasKey('metadata_failed', $cycleEvent->context);
        Http::assertNothingSent();
    }

    /**
     * @param  array<int, string>  $checks
     */
    private function fakeImportProcessInspector(bool $running, array $checks): void
    {
        $this->app->instance(SeasonvarImportProcessInspector::class, new class($running, $checks) extends SeasonvarImportProcessInspector
        {
            /**
             * @param  array<int, string>  $checks
             */
            public function __construct(private readonly bool $running, private readonly array $checks) {}

            /**
             * @return array{pid: int|null, host: string|null, command: string|null, recorded_at: string}
             */
            public function currentProcess(): array
            {
                return [
                    'pid' => 99999,
                    'host' => 'test-host',
                    'command' => 'php artisan seasonvar:import --no-discovery',
                    'recorded_at' => now()->toIso8601String(),
                ];
            }

            /**
             * @param  array<string, mixed>|null  $lockProcess
             * @param  Collection<int, SeasonvarImportRun>  $runningRuns
             * @return array{running: bool, verified: bool, pid: int|null, run_id: int|null, source: string|null, checks: array<int, string>}
             */
            public function inspect(?array $lockProcess, Collection $runningRuns): array
            {
                return [
                    'running' => $this->running,
                    'verified' => true,
                    'pid' => $this->running ? 12345 : null,
                    'run_id' => $this->running && $runningRuns->isNotEmpty() ? (int) $runningRuns->first()->id : null,
                    'source' => $this->running ? 'fake' : null,
                    'checks' => $this->checks,
                ];
            }
        });
    }

    /**
     * @param  array<int, string>  $episodes
     * @param  list<string>  $mediaUrls
     */
    private function refreshPlannerSeasonPageHtml(array $episodes, array $mediaUrls = []): string
    {
        $episodeItems = collect($episodes)
            ->mapWithKeys(fn (string $title, int $number): array => [
                "{$number}_seriya" => ['n' => (string) $number, 'title' => $title],
            ])
            ->all();
        $episodesJson = json_encode([$episodeItems], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        $mediaJson = json_encode($mediaUrls, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        return <<<HTML
            <html>
                <head>
                    <title>Черный список: На кухне 1 сезон смотреть онлайн</title>
                    <meta name="description" content="Описание передачи">
                </head>
                <body>
                    <h1>Сериал Черный список: На кухне 1 сезон онлайн</h1>
                    <div class="pgs-sinfo_list">
                        Жанр: Кулинария
                        Страна: Россия
                        Вышел: 2024
                        Перевод: Оригинал
                    </div>
                    <div class="pgs-seaslist">
                        <a href="/serial-47915-CHernyj_spisok_Na_kuhne-1-season.html">1 сезон (Оригинал)</a>
                    </div>
                    <script>
                        var arEpisodes = {$episodesJson};
                        var parsedMedia = {$mediaJson};
                    </script>
                </body>
            </html>
            HTML;
    }

    /**
     * @param  list<string>  $urls
     */
    private function sitemapIndexXml(array $urls): string
    {
        $items = collect($urls)
            ->map(fn (string $url): string => "        <sitemap><loc>{$url}</loc></sitemap>")
            ->implode("\n");

        return <<<XML
            <?xml version="1.0" encoding="UTF-8"?>
            <sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
            {$items}
            </sitemapindex>
            XML;
    }

    /**
     * @param  list<string>  $urls
     */
    private function sitemapUrlsetXml(array $urls): string
    {
        $items = collect($urls)
            ->map(fn (string $url): string => "        <url><loc>{$url}</loc></url>")
            ->implode("\n");

        return <<<XML
            <?xml version="1.0" encoding="UTF-8"?>
            <urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
            {$items}
            </urlset>
            XML;
    }
}
