<?php

namespace Tests\Feature;

use App\DTOs\VerifiedExternalUrlData;
use App\Enums\AdminAuditAction;
use App\Enums\ReleaseKind;
use App\Jobs\StartSeasonvarQueuedImport;
use App\Livewire\CatalogAdministrationManager;
use App\Livewire\CatalogDirectoryBrowser;
use App\Livewire\CatalogHomePage;
use App\Livewire\CatalogSeries;
use App\Livewire\CatalogTitleDetail;
use App\Livewire\CatalogTitlePlayer;
use App\Livewire\SeasonvarImportManager;
use App\Livewire\StatsDashboard;
use App\Livewire\ViewingActivity;
use App\Models\Actor;
use App\Models\AdminAuditEvent;
use App\Models\CatalogTitle;
use App\Models\CatalogTitleAlias;
use App\Models\CatalogTitleRating;
use App\Models\CatalogTitleRecommendation;
use App\Models\CatalogTitleUserState;
use App\Models\Country;
use App\Models\Episode;
use App\Models\EpisodeViewProgress;
use App\Models\Genre;
use App\Models\LicensedMedia;
use App\Models\Season;
use App\Models\SeasonvarImportEvent;
use App\Models\SeasonvarImportRun;
use App\Models\SourcePage;
use App\Models\Tag;
use App\Models\User;
use App\Services\Catalog\CatalogDirectoryRegistry;
use App\Services\Catalog\CatalogHomeMetricsCache;
use App\Services\Catalog\CatalogHomeSnapshotCache;
use App\Services\Catalog\CatalogPlaybackProgressSession;
use App\Services\Catalog\CatalogStatsPageBuilder;
use App\Services\Catalog\CatalogStatsPosterUrlGuard;
use App\Services\Catalog\CatalogTaxonomyRegistry;
use App\Services\Catalog\CatalogUserStateService;
use App\Support\Cache\CacheDomain;
use App\Support\Cache\CacheVersionRegistry;
use App\Support\CatalogTitleDisplayName;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

class CatalogPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_home_route_is_owned_by_full_page_livewire(): void
    {
        $this->assertSame(
            CatalogHomePage::class,
            Route::getRoutes()->getByName('home')?->getActionName(),
        );

        $this->get(route('home'))
            ->assertOk()
            ->assertSeeLivewire('catalog-home-page');
    }

    public function test_title_route_is_owned_by_full_page_livewire(): void
    {
        $title = CatalogTitle::factory()->create();

        $this->assertSame(
            CatalogTitleDetail::class,
            Route::getRoutes()->getByName('titles.show')?->getActionName(),
        );

        $this->get(route('titles.show', $title))
            ->assertOk()
            ->assertSeeLivewire('catalog-title-detail');
    }

    public function test_stats_route_is_owned_by_full_page_livewire(): void
    {
        $this->assertSame(
            StatsDashboard::class,
            Route::getRoutes()->getByName('stats')?->getActionName(),
        );

        $this->get(route('stats'))
            ->assertOk()
            ->assertSeeLivewire('stats-dashboard');
    }

    public function test_catalog_and_people_directories_render_separate_script_groups(): void
    {
        $title = CatalogTitle::factory()->create();
        $actors = collect([
            ['name' => 'Борис Актёр', 'slug' => 'boris-akter'],
            ['name' => 'Ёлка Актриса', 'slug' => 'elka-aktrisa'],
            ['name' => 'Alice Actor', 'slug' => 'alice-actor'],
            ['name' => 'Zed Actor', 'slug' => 'zed-actor'],
            ['name' => '123 Actor', 'slug' => '123-actor'],
        ])->map(fn (array $attributes) => Actor::query()->create($attributes));
        $title->actors()->attach($actors->pluck('id'));

        $catalog = $this->get(route('titles.index'))->assertOk()->getContent();
        $actorsPage = $this->get(route('actors.index'))->assertOk()->getContent();

        $this->assertStringContainsString('data-catalog-alphabet-group="cyrillic"', $catalog);
        $this->assertStringContainsString('data-catalog-alphabet-group="latin"', $catalog);
        $this->assertSame(2, substr_count($catalog, 'data-alphabet-letter="Z"'));
        $this->assertMatchesRegularExpression('/data-directory-alphabet-group="cyrillic".*Б.*Ё/s', $actorsPage);
        $this->assertMatchesRegularExpression('/data-directory-alphabet-group="latin".*A.*Z/s', $actorsPage);
        $this->assertStringContainsString('data-directory-alphabet-symbols', $actorsPage);
    }

    public function test_the_application_returns_a_successful_response(): void
    {
        $response = $this->get('/');

        $response->assertStatus(200);
        $response->assertDontSeeText('Состояние базы');
        $response->assertSeeText('Сейчас можно смотреть');
        $response->assertDontSeeText('Быстрый выбор');
    }

    public function test_every_public_catalog_directory_hub_is_available(): void
    {
        foreach (app(CatalogDirectoryRegistry::class)->all() as $directory) {
            $this->get(route($directory->indexRouteName))
                ->assertOk()
                ->assertSeeLivewire('catalog-directory-browser')
                ->assertSeeText($directory->title)
                ->assertSee('<link rel="canonical" href="'.route($directory->indexRouteName).'">', false);
        }
    }

    public function test_directory_counts_and_rows_include_only_visible_published_titles(): void
    {
        $genre = Genre::query()->create(['name' => 'Научная фантастика', 'slug' => 'nauchnaia-fantastika']);
        $published = CatalogTitle::factory()->create(['title' => 'Видимый сериал']);
        $draft = CatalogTitle::factory()->create([
            'title' => 'Скрытый черновик',
            'is_published' => false,
            'publication_status' => 'draft',
        ]);
        $published->genres()->attach($genre);
        $draft->genres()->attach($genre);

        $this->get(route('genres.index'))
            ->assertOk()
            ->assertSee('data-directory-results-list', false)
            ->assertSeeText('1 значение')
            ->assertSeeText('1 сериал')
            ->assertSeeText('Научная фантастика')
            ->assertSee('href="'.route('titles.taxonomy', ['type' => 'genre', 'taxonomy' => $genre->slug]).'"', false)
            ->assertDontSeeText('Скрытый черновик');
    }

    public function test_friendly_directory_details_redirect_permanently_to_generic_canonical_pages(): void
    {
        $title = CatalogTitle::factory()->create(['year' => 2026]);
        $taxonomies = app(CatalogTaxonomyRegistry::class);

        foreach (app(CatalogDirectoryRegistry::class)->all()->whereNotNull('filterType')->values() as $index => $directory) {
            $filterType = $directory->filterType->value;
            $modelClass = $taxonomies->modelClass($filterType);
            $taxonomy = $modelClass::query()->create([
                'name' => 'Значение '.($index + 1),
                'slug' => 'znachenie-'.($index + 1),
            ]);
            $title->{$taxonomies->relationName($filterType)}()->attach($taxonomy);

            $this->get(route($directory->detailRouteName, $taxonomy->slug))
                ->assertStatus(301)
                ->assertRedirect(route('titles.taxonomy', ['type' => $filterType, 'taxonomy' => $taxonomy->slug]));
        }

        $this->get(route('years.show', 2026))
            ->assertStatus(301)
            ->assertRedirect(route('titles.year', ['year' => 2026]));

        $this->get('/genres/neizvestnyi-zhanr')->assertNotFound();
        $this->get('/genres/NEVALID')->assertNotFound();
        $this->get('/years/1899')->assertNotFound();
        $this->get('/directories-do-not-exist')->assertRedirect(route('home'));
    }

    public function test_directory_livewire_state_is_url_bound_normalized_and_resets_pagination(): void
    {
        foreach (range(1, 55) as $number) {
            $actor = Actor::query()->create([
                'name' => sprintf('Актёр %02d', $number),
                'slug' => 'akter-'.$number,
            ]);
            $title = CatalogTitle::factory()->create();
            $title->actors()->attach($actor);
        }

        Livewire::withQueryParams([
            'q' => '  Актёр  ',
            'letter' => 'А',
            'sort' => 'count_desc',
            'page' => 2,
        ])->test(CatalogDirectoryBrowser::class, ['directory' => 'actors'])
            ->assertSet('search', 'Актёр')
            ->assertSet('letter', 'А')
            ->assertSet('sort', 'count_desc')
            ->set('search', 'Актёр 10')
            ->assertSet('paginators.page', 1)
            ->assertSeeText('Актёр 10')
            ->assertSeeText('Найдено 1 значение')
            ->assertDontSeeText('Актёр 05');

        Livewire::withQueryParams([
            'q' => ['unsafe'],
            'letter' => ['А'],
            'sort' => 'drop_table',
            'decade' => 'not-a-decade',
        ])->test(CatalogDirectoryBrowser::class, ['directory' => 'actors'])
            ->assertSet('search', '')
            ->assertSet('letter', '')
            ->assertSet('sort', 'name_asc')
            ->assertSet('decade', null)
            ->assertHasNoErrors();
    }

    public function test_year_directory_has_only_valid_non_empty_years_and_decade_navigation(): void
    {
        config(['catalog.directories.maximum_year' => 2027]);
        CatalogTitle::factory()->create(['title' => 'Сериал 1998', 'year' => 1998]);
        CatalogTitle::factory()->create(['title' => 'Сериал 2027', 'year' => 2027]);
        CatalogTitle::factory()->create(['title' => 'Сериал 2028', 'year' => 2028]);
        CatalogTitle::factory()->create(['title' => 'Сериал 1899', 'year' => 1899]);

        $this->get(route('years.index'))
            ->assertOk()
            ->assertSeeText('1990-е')
            ->assertSeeText('2020-е')
            ->assertSeeText('1998')
            ->assertSeeText('2027')
            ->assertDontSeeText('2028')
            ->assertDontSeeText('1899');
    }

    public function test_directory_metadata_uses_one_canonical_and_valid_collection_json_ld(): void
    {
        $actor = Actor::query()->create(['name' => 'Иван Петров', 'slug' => 'ivan-petrov']);
        $title = CatalogTitle::factory()->create();
        $title->actors()->attach($actor);
        $canonical = route('actors.index');

        $response = $this->get(route('actors.index', ['q' => 'Иван', 'letter' => 'И']));

        $response
            ->assertOk()
            ->assertSee('<link rel="canonical" href="'.$canonical.'">', false)
            ->assertSee('<meta name="robots" content="noindex,nofollow,max-image-preview:large,max-snippet:-1,max-video-preview:-1">', false)
            ->assertSee('<meta property="og:url" content="'.$canonical.'">', false)
            ->assertSee('"@type":"CollectionPage"', false)
            ->assertSee('"@type":"ItemList"', false)
            ->assertSeeText('Иван Петров');
    }

    public function test_directory_render_uses_a_bounded_number_of_grouped_queries(): void
    {
        foreach (range(1, 30) as $number) {
            $actor = Actor::query()->create([
                'name' => 'Исполнитель '.$number,
                'slug' => 'ispolnitel-'.$number,
            ]);
            CatalogTitle::factory()->create()->actors()->attach($actor);
        }

        $queries = [];
        DB::listen(function (QueryExecuted $query) use (&$queries): void {
            if (str_contains($query->sql, 'actors') || str_contains($query->sql, 'catalog_title_actor')) {
                $queries[] = $query->sql;
            }
        });

        $this->get(route('actors.index'))->assertOk();

        $this->assertLessThanOrEqual(7, count($queries));
        $this->assertTrue(collect($queries)->contains(
            fn (string $sql): bool => str_contains(strtolower($sql), 'count(distinct'),
        ));
    }

    public function test_livewire_assets_are_loaded_once_on_ordinary_and_directory_pages(): void
    {
        foreach ([route('home'), route('genres.index')] as $url) {
            Livewire::flushState();
            $content = $this->get($url)->assertOk()->getContent();

            $this->assertSame(
                1,
                preg_match_all('~/vendor/livewire/livewire(?:\.min)?\.js\?id=~', $content),
            );
            $this->assertSame(1, substr_count($content, 'data-csrf='));
        }
    }

    public function test_import_admin_route_is_authorized_and_polls_only_while_a_run_is_active(): void
    {
        config([
            'seasonvar.admin_emails' => ['admin@example.com'],
            'seasonvar.queue.lock_store' => 'array',
        ]);
        Queue::fake();
        $admin = User::factory()->create(['email' => 'admin@example.com']);
        $ordinaryUser = User::factory()->create(['email' => 'viewer@example.com']);
        LicensedMedia::factory()->create([
            'health_status' => 'degraded',
            'consecutive_failures' => 2,
            'next_check_at' => now()->subMinute(),
        ]);

        $this->get(route('admin.imports'))->assertForbidden();
        $this->actingAs($ordinaryUser)->get(route('admin.imports'))->assertForbidden();
        $this->actingAs($admin)
            ->get(route('admin.imports'))
            ->assertOk()
            ->assertSeeLivewire('seasonvar-import-manager')
            ->assertSeeText('Импорт Seasonvar')
            ->assertSeeText('Здоровье видеоисточников')
            ->assertSeeText('Нестабильно')
            ->assertSeeText('Ожидают проверки')
            ->assertDontSee('wire:poll.5s.visible="refreshRuns"', false);

        $component = Livewire::actingAs($admin)
            ->test(SeasonvarImportManager::class)
            ->assertDontSeeHtml('wire:poll.5s.visible="refreshRuns"')
            ->set('force', true)
            ->set('discover', false)
            ->call('startImport')
            ->assertHasNoErrors()
            ->assertSeeText('Ожидает запуска')
            ->assertSeeHtml('wire:poll.5s.visible="refreshRuns"');

        Queue::assertPushedTimes(StartSeasonvarQueuedImport::class, 1);
        $run = SeasonvarImportRun::query()->sole();
        $run->update([
            'status' => 'completed',
            'started_at' => now()->subMinute(),
            'finished_at' => now(),
        ]);

        $component
            ->call('refreshRuns')
            ->assertSeeText('Завершён')
            ->assertDontSeeHtml('wire:poll.5s.visible="refreshRuns"');
    }

    public function test_catalog_admin_route_uses_the_existing_admin_allowlist(): void
    {
        config(['seasonvar.admin_emails' => ['admin@example.com']]);
        $admin = User::factory()->create(['email' => 'admin@example.com']);
        $ordinaryUser = User::factory()->create(['email' => 'viewer@example.com']);

        $this->get('/admin/catalog')->assertForbidden();
        $this->actingAs($ordinaryUser)->get('/admin/catalog')->assertForbidden();
        $this->actingAs($admin)
            ->get('/admin/catalog')
            ->assertOk()
            ->assertSeeLivewire('catalog-administration-manager')
            ->assertSeeText('Управление каталогом');

        Livewire::actingAs($ordinaryUser)
            ->test(CatalogAdministrationManager::class)
            ->assertForbidden();
    }

    public function test_catalog_admin_updates_editorial_fields_relations_and_publication_without_deleting_user_state(): void
    {
        config(['seasonvar.admin_emails' => ['admin@example.com']]);
        $admin = User::factory()->create(['email' => 'admin@example.com']);
        $viewer = User::factory()->create();
        $title = CatalogTitle::factory()->create([
            'provider_field_values' => ['title' => 'Название провайдера'],
        ]);
        $genre = Genre::query()->create(['name' => 'Драма', 'slug' => 'drama']);
        CatalogTitleUserState::query()->create([
            'user_id' => $viewer->id,
            'catalog_title_id' => $title->id,
            'in_watchlist' => true,
            'rating' => 8,
        ]);
        $versions = app(CacheVersionRegistry::class);
        $statsVersion = $versions->version(CacheDomain::CatalogStats);
        $homeVersion = $versions->version(CacheDomain::Homepage);

        Livewire::actingAs($admin)
            ->test(CatalogAdministrationManager::class)
            ->call('selectTitle', $title->id)
            ->set('titleForm.title', 'Редакторское название')
            ->set('titleForm.original_title', 'Editorial title')
            ->set('titleForm.slug', 'redaktorskoe-nazvanie')
            ->set('titleForm.external_id', 'editorial-42')
            ->set('titleForm.publication_status', 'hidden')
            ->set('titleForm.available_from', '2026-08-01 10:00')
            ->set('titleForm.available_until', '2026-09-01 10:00')
            ->call('saveTitle')
            ->assertHasNoErrors()
            ->call('attachRelation', 'genre', $genre->id)
            ->assertHasNoErrors()
            ->call('archiveTitle')
            ->assertHasNoErrors();

        $title->refresh();
        $this->assertSame('Редакторское название', $title->title);
        $this->assertSame('Editorial title', $title->original_title);
        $this->assertSame('redaktorskoe-nazvanie', $title->slug);
        $this->assertSame('editorial-42', $title->external_id);
        $this->assertSame('hidden', $title->publication_status->value);
        $this->assertFalse($title->is_published);
        $this->assertSame(['title' => 'Название провайдера'], $title->provider_field_values);
        $this->assertTrue($title->genres()->whereKey($genre->id)->exists());
        $this->assertModelExists(CatalogTitleUserState::query()->whereBelongsTo($viewer)->sole());
        $this->assertGreaterThan($statsVersion, $versions->version(CacheDomain::CatalogStats));
        $this->assertGreaterThan($homeVersion, $versions->version(CacheDomain::Homepage));
        $this->get('/titles/redaktorskoe-nazvanie')->assertNotFound();
        $this->assertSame(
            [
                AdminAuditAction::TitleUpdated,
                AdminAuditAction::RelationAttached,
                AdminAuditAction::TitleArchived,
            ],
            AdminAuditEvent::query()->orderBy('id')->pluck('action')->all(),
        );
        $this->assertTrue(AdminAuditEvent::query()->get()->every(
            fn (AdminAuditEvent $event): bool => $event->actor_id === $admin->id
                && $event->resource_type === 'catalog_title'
                && $event->resource_id === $title->id,
        ));
    }

    public function test_catalog_admin_preserves_the_previous_public_slug_as_a_permanent_redirect(): void
    {
        config(['seasonvar.admin_emails' => ['admin@example.com']]);
        $admin = User::factory()->create(['email' => 'admin@example.com']);
        $title = CatalogTitle::factory()->create([
            'slug' => 'prezhnii-adres',
            'title' => 'Стабильный адрес',
        ]);

        Livewire::actingAs($admin)
            ->test(CatalogAdministrationManager::class)
            ->call('selectTitle', $title->id)
            ->set('titleForm.slug', 'novyi-adres')
            ->call('saveTitle')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('catalog_title_slugs', [
            'catalog_title_id' => $title->id,
            'slug' => 'prezhnii-adres',
        ]);

        $this->get('/titles/prezhnii-adres?episode=999')
            ->assertStatus(301)
            ->assertRedirect(route('titles.show', ['catalogTitle' => 'novyi-adres']));
        $this->get('/titles/novyi-adres')->assertOk();
    }

    public function test_catalog_admin_rejects_duplicate_identity_and_stale_concurrent_edits(): void
    {
        config(['seasonvar.admin_emails' => ['admin@example.com']]);
        $admin = User::factory()->create(['email' => 'admin@example.com']);
        $title = CatalogTitle::factory()->create(['original_title' => 'Исходное название']);
        $duplicate = CatalogTitle::factory()->create([
            'source_id' => $title->source_id,
            'slug' => 'already-used',
            'external_id' => 'already-used-id',
        ]);
        $firstEditor = Livewire::actingAs($admin)
            ->test(CatalogAdministrationManager::class)
            ->call('selectTitle', $title->id)
            ->assertSeeHtml('wire:confirm=');
        $secondEditor = Livewire::actingAs($admin)
            ->test(CatalogAdministrationManager::class)
            ->call('selectTitle', $title->id);

        $firstEditor
            ->set('titleForm.title', 'Первая редакция')
            ->call('saveTitle')
            ->assertHasNoErrors();

        $secondEditor
            ->set('titleForm.original_title', 'Запоздалая редакция')
            ->call('saveTitle')
            ->assertHasErrors('titleForm');

        $this->assertSame('Первая редакция', $title->fresh()->title);
        $this->assertSame('Исходное название', $title->fresh()->original_title);

        Livewire::actingAs($admin)
            ->test(CatalogAdministrationManager::class)
            ->call('selectTitle', $title->id)
            ->set('titleForm.slug', $duplicate->slug)
            ->set('titleForm.external_id', $duplicate->external_id)
            ->call('saveTitle')
            ->assertHasErrors(['titleForm.slug', 'titleForm.external_id']);

        $season = Season::factory()->for($title)->create();
        $firstSeasonEditor = Livewire::actingAs($admin)
            ->test(CatalogAdministrationManager::class)
            ->call('selectTitle', $title->id)
            ->call('editSeason', $season->id);
        $secondSeasonEditor = Livewire::actingAs($admin)
            ->test(CatalogAdministrationManager::class)
            ->call('selectTitle', $title->id)
            ->call('editSeason', $season->id);

        $firstSeasonEditor
            ->set('seasonForm.title', 'Редакция сезона')
            ->call('saveSeason')
            ->assertHasNoErrors();
        $secondSeasonEditor
            ->set('seasonForm.title', 'Устаревшая редакция сезона')
            ->call('saveSeason')
            ->assertHasErrors('seasonForm');

        $this->assertSame('Редакция сезона', $season->fresh()->title);
        $this->assertDatabaseCount('admin_audit_events', 2);
    }

    public function test_catalog_admin_scheduled_publication_and_hierarchy_binding_are_enforced_server_side(): void
    {
        config(['seasonvar.admin_emails' => ['admin@example.com']]);
        $admin = User::factory()->create(['email' => 'admin@example.com']);
        $title = CatalogTitle::factory()->create(['slug' => 'scheduled-admin-title']);
        $foreignEpisode = Episode::factory()->create();
        $component = Livewire::actingAs($admin)
            ->test(CatalogAdministrationManager::class)
            ->call('selectTitle', $title->id)
            ->set('titleForm.publication_status', 'published')
            ->set('titleForm.available_from', now()->addDay()->format('Y-m-d H:i'))
            ->call('saveTitle')
            ->assertHasNoErrors();

        $this->get(route('titles.show', $title))->assertNotFound();

        $component
            ->set('titleForm.available_from', now()->subMinute()->format('Y-m-d H:i'))
            ->call('saveTitle')
            ->assertHasNoErrors();

        $this->get(route('titles.show', $title->fresh()))->assertOk();
        $this->assertThrows(
            fn () => $component->call('editEpisode', $foreignEpisode->id),
            ModelNotFoundException::class,
        );
    }

    public function test_catalog_admin_manages_release_hierarchy_and_video_sources_without_cascading_user_data_deletion(): void
    {
        config(['seasonvar.admin_emails' => ['admin@example.com']]);
        $admin = User::factory()->create(['email' => 'admin@example.com']);
        $viewer = User::factory()->create();
        $title = CatalogTitle::factory()->create();
        $component = Livewire::actingAs($admin)
            ->test(CatalogAdministrationManager::class)
            ->call('selectTitle', $title->id)
            ->call('newSeason')
            ->set('seasonForm.number', 1)
            ->set('seasonForm.title', 'Первый сезон')
            ->call('saveSeason')
            ->assertHasNoErrors();
        $season = Season::query()->whereBelongsTo($title)->sole();

        $component
            ->call('newSeason')
            ->set('seasonForm.number', 1)
            ->call('saveSeason')
            ->assertHasErrors('seasonForm.number')
            ->call('selectSeason', $season->id)
            ->call('newEpisode')
            ->set('episodeForm.number', 1)
            ->set('episodeForm.title', 'Первая серия')
            ->call('saveEpisode')
            ->assertHasNoErrors();
        $episode = Episode::query()->whereBelongsTo($season)->sole();

        $component
            ->call('newMedia')
            ->set('mediaForm.title', 'Административный источник')
            ->set('mediaForm.playback_url', 'https://media.example.com/admin/episode-1.m3u8')
            ->set('mediaForm.quality', '1080p')
            ->set('mediaForm.format', 'm3u8')
            ->set('mediaForm.status', 'published')
            ->call('saveMedia')
            ->assertHasNoErrors();
        $media = LicensedMedia::query()->whereBelongsTo($episode)->sole();

        $component
            ->call('newMedia')
            ->set('mediaForm.title', 'Дубликат')
            ->set('mediaForm.playback_url', 'https://media.example.com/admin/episode-1.m3u8')
            ->set('mediaForm.quality', '1080p')
            ->set('mediaForm.format', 'm3u8')
            ->call('saveMedia')
            ->assertHasErrors('mediaForm.playback_url');

        EpisodeViewProgress::query()->create([
            'user_id' => $viewer->id,
            'catalog_title_id' => $title->id,
            'episode_id' => $episode->id,
            'position_seconds' => 120,
            'duration_seconds' => 600,
            'first_started_at' => now(),
            'last_watched_at' => now(),
        ]);

        $component
            ->call('editEpisode', $episode->id)
            ->call('archiveEpisode')
            ->assertHasNoErrors()
            ->call('editMedia', $media->id)
            ->assertDontSee('https://media.example.com/admin/episode-1.m3u8')
            ->call('archiveMedia')
            ->assertHasNoErrors();

        $this->assertSame('hidden', $episode->fresh()->publication_status->value);
        $this->assertSame('draft', $media->fresh()->status);
        $this->assertModelExists(EpisodeViewProgress::query()->whereBelongsTo($viewer)->sole());
        $this->get(route('titles.show', $title).'?episode='.$episode->id)->assertOk()->assertDontSeeText('Первая серия');
        $this->assertSame(
            [
                AdminAuditAction::SeasonCreated,
                AdminAuditAction::EpisodeCreated,
                AdminAuditAction::MediaCreated,
                AdminAuditAction::EpisodeArchived,
                AdminAuditAction::MediaArchived,
            ],
            AdminAuditEvent::query()->orderBy('id')->pluck('action')->all(),
        );
    }

    public function test_catalog_admin_creates_and_attaches_existing_lookup_types_without_duplicate_pivots(): void
    {
        config(['seasonvar.admin_emails' => ['admin@example.com']]);
        $admin = User::factory()->create(['email' => 'admin@example.com']);
        $title = CatalogTitle::factory()->create();
        $component = Livewire::actingAs($admin)
            ->test(CatalogAdministrationManager::class)
            ->call('selectTitle', $title->id);
        $lookups = [
            'actor' => ['name' => 'Актёр редакции', 'slug' => 'akter-redakcii'],
            'director' => ['name' => 'Режиссёр редакции', 'slug' => 'rezhisser-redakcii'],
            'genre' => ['name' => 'Жанр редакции', 'slug' => 'zhanr-redakcii'],
            'country' => ['name' => 'Страна редакции', 'slug' => 'strana-redakcii'],
            'translation' => ['name' => 'Русская дорожка', 'slug' => 'russkaia-dorozhka'],
        ];

        foreach ($lookups as $type => $lookup) {
            $component
                ->call('newLookup', $type)
                ->set('lookupForm.name', $lookup['name'])
                ->set('lookupForm.slug', $lookup['slug'])
                ->call('saveLookup')
                ->assertHasNoErrors();
        }

        $title->refresh();
        $this->assertTrue($title->actors()->where('slug', 'akter-redakcii')->exists());
        $this->assertTrue($title->directors()->where('slug', 'rezhisser-redakcii')->exists());
        $this->assertTrue($title->genres()->where('slug', 'zhanr-redakcii')->exists());
        $this->assertTrue($title->countries()->where('slug', 'strana-redakcii')->exists());
        $this->assertTrue($title->translations()->where('slug', 'russkaia-dorozhka')->exists());

        $actor = $title->actors()->sole();
        $this->assertNull($actor->source_url);
        $component
            ->call('attachRelation', 'actor', $actor->id)
            ->assertHasNoErrors()
            ->call('newLookup', 'actor')
            ->set('lookupForm.name', 'Другой актёр')
            ->set('lookupForm.slug', 'akter-redakcii')
            ->call('saveLookup')
            ->assertHasErrors('lookupForm.slug');

        $this->assertSame(1, DB::table('catalog_title_actor')->where('catalog_title_id', $title->id)->where('actor_id', $actor->id)->count());
        $this->assertSame(
            [
                AdminAuditAction::LookupCreated,
                AdminAuditAction::LookupCreated,
                AdminAuditAction::LookupCreated,
                AdminAuditAction::LookupCreated,
                AdminAuditAction::LookupCreated,
                AdminAuditAction::RelationAttached,
            ],
            AdminAuditEvent::query()->orderBy('id')->pluck('action')->all(),
        );
    }

    public function test_import_admin_hides_provider_urls_credentials_and_stack_details(): void
    {
        config(['seasonvar.admin_emails' => ['admin@example.com']]);
        $admin = User::factory()->create(['email' => 'admin@example.com']);
        SeasonvarImportRun::query()->create([
            'mode' => 'sitemap',
            'execution_mode' => 'queue',
            'status' => 'failed',
            'last_error' => 'Bearer private-token https://seasonvar.ru/private?token=secret /var/www/private.php:42',
            'finished_at' => now(),
        ]);

        Livewire::actingAs($admin)
            ->test(SeasonvarImportManager::class)
            ->assertSeeText('Ошибка')
            ->assertDontSee('private-token')
            ->assertDontSee('seasonvar.ru/private')
            ->assertDontSee('/var/www/private.php');
    }

    public function test_livewire_catalog_hydrates_url_state_and_filters_results(): void
    {
        $genre = Genre::query()->create([
            'name' => 'Драма',
            'slug' => 'drama',
        ]);
        $matching = CatalogTitle::factory()->create([
            'title' => 'Искомая драма',
            'slug' => 'iskomaia-drama',
        ]);
        $matching->genres()->attach($genre);
        CatalogTitle::factory()->create([
            'title' => 'Посторонняя комедия',
            'slug' => 'postoronniaia-komediia',
        ]);

        Livewire::withQueryParams([
            'q' => 'Искомая',
            'genre' => ['drama'],
            'sort' => 'title_asc',
        ])->test(CatalogSeries::class)
            ->assertSet('filters.search', 'Искомая')
            ->assertSet('filters.genre', ['drama'])
            ->assertSet('filters.sort', 'title_asc')
            ->assertSee($matching->title)
            ->assertDontSee('Посторонняя комедия')
            ->assertSeeHtml('wire:key="catalog-title-'.$matching->id.'"');
    }

    public function test_livewire_catalog_resets_pagination_for_state_changes_and_reset_actions(): void
    {
        CatalogTitle::factory()->count(30)->create();

        Livewire::test(CatalogSeries::class)
            ->call('setPage', 2)
            ->assertSet('paginators.page', 2)
            ->set('filters.genre', ['drama'])
            ->call('applyFilters')
            ->assertSet('paginators.page', 1)
            ->call('resetGroup', 'genre')
            ->assertSet('filters.genre', [])
            ->set('filters.sort', 'year_desc')
            ->assertSet('paginators.page', 1)
            ->call('resetAll')
            ->assertSet('filters.search', '')
            ->assertSet('filters.genre', [])
            ->assertSet('filters.sort', 'updated')
            ->assertSet('filters.perPage', 24);
    }

    public function test_livewire_catalog_resets_only_exact_selection_filters(): void
    {
        CatalogTitle::factory()->create();
        $genre = Genre::query()->create([
            'name' => 'Комедия',
            'slug' => 'comedy',
        ]);

        Livewire::test(CatalogSeries::class)
            ->set('filters.search', 'Мамочка')
            ->set('filters.genre', [$genre->slug])
            ->set('filters.yearFrom', 2010)
            ->set('filters.yearTo', 2024)
            ->set('filters.seasonsMin', 2)
            ->set('filters.episodesMax', 100)
            ->set('filters.ratingSource', 'imdb')
            ->set('filters.ratingMin', 7.5)
            ->set('filters.votesMin', 1000)
            ->set('filters.video', 'available')
            ->set('filters.updated', 'month')
            ->set('filters.qualities', ['1080p', '720p'])
            ->call('resetAdvancedFilters')
            ->assertSet('filters.search', 'Мамочка')
            ->assertSet('filters.genre', [$genre->slug])
            ->assertSet('filters.yearFrom', '')
            ->assertSet('filters.yearTo', '')
            ->assertSet('filters.seasonsMin', '')
            ->assertSet('filters.episodesMax', '')
            ->assertSet('filters.ratingSource', '')
            ->assertSet('filters.ratingMin', '')
            ->assertSet('filters.votesMin', '')
            ->assertSet('filters.video', '')
            ->assertSet('filters.updated', '')
            ->assertSet('filters.qualities', []);
    }

    public function test_livewire_catalog_removes_one_choice_and_resets_only_the_requested_group(): void
    {
        CatalogTitle::factory()->count(30)->create();

        Livewire::test(CatalogSeries::class)
            ->set('filters.genre', ['drama', 'thriller'])
            ->set('filters.country', ['rossiya'])
            ->set('filters.publicationTypes', ['serial', 'anime'])
            ->set('filters.subtitles', ['available', 'missing'])
            ->call('setPage', 2)
            ->call('removeChoice', 'publication_type', 'anime')
            ->assertSet('filters.publicationTypes', ['serial'])
            ->assertSet('filters.genre', ['drama', 'thriller'])
            ->assertSet('filters.country', ['rossiya'])
            ->assertSet('filters.subtitles', ['available', 'missing'])
            ->assertSet('paginators.page', 1)
            ->call('resetGroup', 'genre')
            ->assertSet('filters.genre', [])
            ->assertSet('filters.country', ['rossiya'])
            ->assertSet('filters.publicationTypes', ['serial']);
    }

    public function test_livewire_catalog_searches_bounded_actor_options_on_the_server(): void
    {
        foreach (range(1, 24) as $number) {
            $actor = Actor::query()->create([
                'name' => sprintf('Актер %02d', $number),
                'slug' => 'akter-'.$number,
            ]);
            $title = CatalogTitle::factory()->create();
            $title->actors()->attach($actor);
        }

        $target = Actor::query()->create([
            'name' => 'Искомый редкий актер',
            'slug' => 'iskomyi-redkii-akter',
        ]);
        $targetTitle = CatalogTitle::factory()->create();
        $targetTitle->actors()->attach($target);

        $component = Livewire::test(CatalogSeries::class);
        $initialFacets = $component->instance()->catalogFacets();

        $this->assertFalse(
            $initialFacets['filterTaxonomies']->get('actor')->contains('name', $target->name),
        );

        $component
            ->set('optionSearch.actor', 'Искомый редкий')
            ->assertSet('paginators.page', 1);

        $searchedFacets = $component->instance()->catalogFacets();

        $this->assertTrue(
            $searchedFacets['filterTaxonomies']->get('actor')->contains('name', $target->name),
        );
    }

    public function test_livewire_catalog_keeps_selected_country_live_without_manual_facet_state(): void
    {
        $lithuania = Country::query()->create([
            'name' => 'Литва',
            'slug' => 'litva',
        ]);
        $matching = CatalogTitle::factory()->create(['title' => 'Литовский live-сериал']);
        $matching->countries()->attach($lithuania);
        $other = CatalogTitle::factory()->create(['title' => 'Сериал другой страны']);
        $component = Livewire::test(CatalogSeries::class)
            ->set('filters.country', ['litva'])
            ->assertSet('filters.country', ['litva'])
            ->assertSet('paginators.page', 1);
        $page = $component->instance()->catalogPage();

        $this->assertFalse(property_exists($component->instance(), 'facetsLoaded'));
        $this->assertTrue(method_exists($component->instance(), 'catalogPage'));
        $this->assertTrue(method_exists($component->instance(), 'catalogFacets'));
        $this->assertSame([$matching->id], $page['titles']->pluck('id')->all());
        $this->assertNotContains($other->id, $page['titles']->pluck('id')->all());
    }

    public function test_livewire_catalog_normalizes_null_empty_url_state_without_uninitializing_the_form(): void
    {
        CatalogTitle::factory()->create();

        Livewire::test(CatalogSeries::class)
            ->set('filters.search', null)
            ->set('filters.yearFrom', null)
            ->set('filters.video', null)
            ->assertSet('filters.search', '')
            ->assertSet('filters.yearFrom', '')
            ->assertSet('filters.video', '')
            ->assertHasNoErrors();
    }

    public function test_livewire_catalog_sanitizes_malformed_url_sort_and_page_values(): void
    {
        CatalogTitle::factory()->create(['title' => 'Безопасный результат']);

        Livewire::withQueryParams([
            'sort' => ['title_asc'],
            'page' => ['999'],
        ])->test(CatalogSeries::class)
            ->assertRedirect(route('titles.index'));
    }

    public function test_catalog_canonicalizes_unindexed_multi_value_query_keys_before_livewire_hydration(): void
    {
        $genre = Genre::query()->create(['name' => 'Драма', 'slug' => 'drama']);
        CatalogTitle::factory()->count(25)->create(['year' => 2024])
            ->each(fn (CatalogTitle $title) => $title->genres()->attach($genre));
        $url = route('titles.index').'?year[]=2024&year[]=2025&genre[]=drama&page=2';

        $this->get($url)->assertRedirect(route('titles.index', [
            'year' => [2024, 2025],
            'genre' => ['drama'],
            'page' => 2,
        ]));
    }

    public function test_livewire_catalog_recovers_to_the_last_page_when_result_count_shrinks(): void
    {
        CatalogTitle::factory()->count(25)->create();

        Livewire::withQueryParams(['page' => 999])
            ->test(CatalogSeries::class)
            ->assertRedirect(route('titles.index', ['page' => 2]));

        $this->get(route('titles.index', ['page' => 999]))
            ->assertRedirect(route('titles.index', ['page' => 2]));

        $this->followingRedirects()
            ->get(route('titles.index', ['page' => 999]))
            ->assertOk()
            ->assertSeeText('25')
            ->assertDontSeeText('Ничего не найдено.');
    }

    public function test_livewire_catalog_canonicalizes_page_one_on_scoped_routes(): void
    {
        Livewire::withQueryParams([
            'q' => 'Знахарь',
            'year' => [2025],
            'page' => 1,
        ])
            ->test(CatalogSeries::class, ['year' => 2024])
            ->assertRedirect(route('titles.year', ['year' => 2024]).'?'.Arr::query([
                'q' => 'Знахарь',
                'year' => [2025],
            ]));

        Livewire::withQueryParams(['page' => 1])
            ->test(CatalogSeries::class, ['type' => 'genre', 'taxonomy' => 'drama'])
            ->assertRedirect(route('titles.taxonomy', ['type' => 'genre', 'taxonomy' => 'drama']));
    }

    public function test_taxonomy_catalog_page_uses_natural_russian_copy_for_actor_context(): void
    {
        $actor = Actor::query()->create([
            'name' => 'Стивен Рут',
            'slug' => 'stiven-rut',
        ]);
        $catalogTitle = CatalogTitle::factory()->create([
            'title' => 'Сериал Стивена Рута',
            'slug' => 'serial-stivena-ruta',
        ]);
        $catalogTitle->actors()->attach($actor->id);

        $content = $this->get(route('titles.taxonomy', ['type' => 'actor', 'taxonomy' => $actor->slug]))
            ->assertOk()
            ->assertSeeText('Сериалы с актёром Стивен Рут')
            ->assertSeeText('Сериал Стивена Рута')
            ->getContent();

        $plainContent = html_entity_decode($content);
        $lowerPlainContent = Str::lower($plainContent);

        $this->assertStringContainsString('Сериалы с актёром Стивен Рут онлайн', $plainContent);
        $this->assertStringContainsString('Показаны сериалы с актёром Стивен Рут.', $plainContent);
        $this->assertStringContainsString('с актёром Стивен Рут · убрать', $plainContent);
        $this->assertStringContainsString('смотреть сериалы с актёром стивен рут', $lowerPlainContent);
        $this->assertStringNotContainsString('Сериалы: Стивен Рут', $plainContent);
        $this->assertStringNotContainsString('Актер: Стивен Рут', $plainContent);
        $this->assertStringNotContainsString('Актеры: Стивен Рут', $plainContent);
        $this->assertStringNotContainsString('Фильтры: Актер: Стивен Рут', $plainContent);
    }

    public function test_livewire_catalog_preserves_search_and_filters_when_sorting_changes(): void
    {
        $genre = Genre::query()->create([
            'name' => 'Драма',
            'slug' => 'drama',
        ]);
        CatalogTitle::factory()->count(30)->create([
            'title' => 'Знахарь',
        ])->each(fn (CatalogTitle $title) => $title->genres()->attach($genre));

        Livewire::withQueryParams([
            'q' => 'Знахарь',
            'genre' => ['drama'],
            'page' => 2,
        ])->test(CatalogSeries::class)
            ->call('sortBy', 'year_desc')
            ->assertSet('filters.search', 'Знахарь')
            ->assertSet('filters.genre', ['drama'])
            ->assertSet('filters.sort', 'year_desc')
            ->assertSet('paginators.page', 1);
    }

    public function test_home_page_lists_country_filters_without_four_item_cap(): void
    {
        $catalogTitle = CatalogTitle::factory()->create();

        collect(range(1, 6))->each(function (int $number) use ($catalogTitle): void {
            $country = Country::query()->create([
                'name' => 'Страна '.$number,
                'slug' => 'strana-'.$number,
            ]);

            $catalogTitle->countries()->attach($country->id);
        });

        $response = $this->get(route('home'));

        $response
            ->assertOk()
            ->assertSeeText('Страны')
            ->assertDontSeeText('Фильтр сериалов')
            ->assertSeeText('Страна 1')
            ->assertSeeText('Страна 5')
            ->assertSeeText('Страна 6');
    }

    public function test_home_watchable_titles_are_ordered_by_recent_catalog_updates(): void
    {
        $olderTitle = CatalogTitle::factory()->create([
            'title' => 'Старое доступное видео',
            'indexed_at' => now()->subDay(),
        ]);
        $newerTitle = CatalogTitle::factory()->create([
            'title' => 'Новое доступное видео',
            'indexed_at' => now(),
        ]);

        LicensedMedia::factory()->count(2)->create([
            'catalog_title_id' => $olderTitle->id,
            'status' => 'published',
            'published_at' => now()->subDay(),
        ]);
        LicensedMedia::factory()->create([
            'catalog_title_id' => $newerTitle->id,
            'status' => 'published',
            'published_at' => now(),
        ]);

        $content = $this->get(route('home'))->assertOk()->getContent();
        $sectionStart = strpos($content, 'Сейчас можно смотреть');
        $sectionEnd = strpos($content, '<aside class="space-y-4 xl:order-1">', $sectionStart ?: 0);

        $this->assertIsInt($sectionStart);
        $this->assertIsInt($sectionEnd);

        $section = substr($content, $sectionStart, $sectionEnd - $sectionStart);

        $this->assertLessThan(
            strpos($section, $olderTitle->title),
            strpos($section, $newerTitle->title),
        );
    }

    public function test_home_reuses_and_explicitly_invalidates_public_metrics(): void
    {
        CatalogTitle::factory()->create();

        $this->assertSame(1, app(CatalogHomeMetricsCache::class)->metrics()['titles']);

        CatalogTitle::factory()->create();

        $this->assertSame(1, app(CatalogHomeMetricsCache::class)->metrics()['titles']);

        app(CatalogHomeMetricsCache::class)->forget();

        $this->assertSame(2, app(CatalogHomeMetricsCache::class)->metrics()['titles']);
    }

    public function test_home_rehydrates_cached_subtitle_tag_without_mass_assignment(): void
    {
        $title = CatalogTitle::factory()->create();
        $tag = Tag::query()->create([
            'name' => 'Субтитры',
            'slug' => 'subtitry',
            'source_url' => 'https://seasonvar.ru/tag/subtitry',
        ]);
        $title->tags()->attach($tag);

        $subtitleTag = app(CatalogHomeSnapshotCache::class)->snapshot()['subtitle_tag'];

        $this->assertIsArray($subtitleTag);
        $this->assertSame(
            ['id', 'name', 'slug', 'catalog_titles_count'],
            array_keys($subtitleTag),
        );
        $this->assertArrayNotHasKey('source_url', $subtitleTag);

        $this->get(route('home'))
            ->assertOk()
            ->assertSeeText('С субтитрами');
    }

    public function test_catalog_year_filter_accepts_cached_plain_year_bucket_rows(): void
    {
        CatalogTitle::factory()->create(['year' => 2020]);

        $this->get(route('titles.index'))->assertOk();
        $this->get(route('titles.index', ['year' => [2020]]))->assertOk();
    }

    public function test_home_media_feed_has_a_status_and_publication_order_index(): void
    {
        $index = collect(Schema::getIndexes('licensed_media'))
            ->firstWhere('name', 'licensed_media_home_feed_idx');

        $this->assertIsArray($index);
        $this->assertSame(['status', 'published_at', 'id'], $index['columns']);
    }

    public function test_home_media_feed_query_uses_the_indexed_id_tie_breaker(): void
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        app(CatalogHomeSnapshotCache::class)->snapshot();

        $query = collect(DB::getQueryLog())
            ->pluck('query')
            ->first(fn (string $sql): bool => str_contains($sql, 'from "licensed_media"')
                && str_contains($sql, 'order by "published_at" desc'));

        $this->assertIsString($query);
        $this->assertStringContainsString('order by "published_at" desc, "id" desc', $query);
        $this->assertStringNotContainsString('"created_at" desc', $query);
    }

    public function test_stats_page_shows_database_statistics_without_raw_source_urls(): void
    {
        $sourceUrl = 'https://seasonvar.ru/serial-777-Skrytyj_url-1-season.html';
        $sourcePageUrl = 'https://seasonvar.ru/serial-778-Skrytyj_source_page-1-season.html';
        $runArgumentUrl = 'https://seasonvar.ru/serial-779-Skrytyj_argument-1-season.html';
        $eventContextUrl = 'https://seasonvar.ru/serial-780-Skrytyj_context-1-season.html';
        $posterUrl = 'https://media.example.com/private-poster.jpg';
        $mediaPath = 'https://media.example.com/private-video.m3u8';
        $mediaPlaybackUrl = 'https://cdn.example.com/private-playback.m3u8';
        $catalogTitle = CatalogTitle::factory()->create([
            'title' => 'Статистический сериал',
            'slug' => 'statisticheskii-serial',
            'year' => 2024,
            'poster_url' => $posterUrl,
            'source_url' => $sourceUrl,
            'source_url_hash' => hash('sha256', $sourceUrl),
        ]);
        $season = Season::factory()->create([
            'catalog_title_id' => $catalogTitle->id,
            'number' => 1,
            'episodes_total' => 8,
            'episodes_released' => 2,
        ]);
        $episode = Episode::factory()->create([
            'season_id' => $season->id,
            'number' => 1,
            'released_at' => now(),
        ]);
        LicensedMedia::factory()->create([
            'catalog_title_id' => $catalogTitle->id,
            'season_id' => $season->id,
            'episode_id' => $episode->id,
            'title' => 'Видео 1080p',
            'path' => $mediaPath,
            'playback_url' => $mediaPlaybackUrl,
            'source_url' => 'https://seasonvar.ru/video-private-source.html',
            'quality' => '1080p',
            'format' => 'm3u8',
            'status' => 'published',
            'check_status' => 'available',
            'checked_at' => now(),
            'published_at' => now(),
        ]);
        $sourcePage = SourcePage::factory()->create([
            'url' => $sourcePageUrl,
            'url_hash' => hash('sha256', $sourcePageUrl),
            'parse_status' => 'failed',
            'import_status' => 'failed',
            'missing_data_flags' => ['video'],
            'retry_after_at' => now()->addHour(),
            'last_crawled_at' => now()->subDays(45),
        ]);
        $genreId = DB::table('genres')->insertGetId([
            'name' => 'Тестовый жанр',
            'slug' => 'testovyi-zhanr',
            'source_url' => 'https://seasonvar.ru/genre-private-source.html',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('catalog_title_genre')->insert([
            'catalog_title_id' => $catalogTitle->id,
            'genre_id' => $genreId,
        ]);
        $run = SeasonvarImportRun::query()->create([
            'mode' => 'sitemap',
            'status' => 'failed',
            'argument' => $runArgumentUrl,
            'force' => true,
            'cycles' => 2,
            'discovered' => 12,
            'stored' => 10,
            'selected' => 7,
            'parsed' => 4,
            'failed' => 1,
            'media_attached' => 2,
            'media_updated' => 3,
            'media_skipped' => 5,
            'media_failed' => 6,
            'summary' => [
                'last_discovery' => [
                    'discovered' => 12,
                    'stored' => 10,
                    'cleaned' => 1,
                ],
                'last_source_status_backfill' => [
                    'selected' => 3,
                    'backfilled' => 2,
                ],
                'last_media_metadata_backlog' => [
                    'media_checked' => 3,
                    'media_updated' => 2,
                ],
                'last_media_source_key_backlog' => [
                    'media_checked' => 4,
                    'media_updated' => 1,
                ],
                'last_media_backlog' => [
                    'media_checked' => 5,
                    'media_available' => 4,
                    'media_unavailable' => 1,
                ],
                'last_relation_cleanup' => [
                    'records_removed' => 2,
                    'links_removed' => 3,
                    'legacy_records_removed' => 1,
                    'legacy_links_removed' => 1,
                ],
                'last_merge' => [
                    'titles' => 1,
                    'seasons' => 2,
                    'episodes' => 3,
                ],
                'last_url' => [
                    'url' => $runArgumentUrl,
                    'parsed' => 1,
                    'failed' => 0,
                ],
            ],
            'last_error' => 'raw stack trace secret',
            'started_at' => now()->subMinutes(10),
            'finished_at' => now(),
        ]);
        SeasonvarImportEvent::query()->create([
            'seasonvar_import_run_id' => $run->id,
            'source_page_id' => $sourcePage->id,
            'catalog_title_id' => $catalogTitle->id,
            'event' => 'parse_failed',
            'level' => 'error',
            'context' => ['url' => $eventContextUrl, 'secret' => 'private context secret'],
        ]);

        $this->actingAs(User::factory()->create());
        $this->mock(CatalogStatsPosterUrlGuard::class)
            ->shouldReceive('safeUrl')
            ->atLeast()
            ->once()
            ->with($posterUrl)
            ->andReturn($posterUrl);

        $response = $this->get(route('stats'));

        $this->assertMatchesRegularExpression(
            '~/vendor/livewire/livewire(?:\.min)?\.js\?id=~',
            $response->getContent(),
        );

        $response
            ->assertOk()
            ->assertSeeLivewire('stats-dashboard')
            ->assertDontSee('wire:poll', false)
            ->assertSeeText('Сводка каталога')
            ->assertSeeText('Снимок обновляется после изменений каталога и в плановом прогреве')
            ->assertSeeText('Показано:')
            ->assertSeeText('Сериалов каталога')
            ->assertSeeText('Сезоны и серии')
            ->assertSeeText('Видео')
            ->assertSeeText('Страницы и ссылки')
            ->assertSeeText('Индексов базы')
            ->assertSeeText('Проверка важных индексов')
            ->assertSeeText('Лента по опубликованным сериалам')
            ->assertSeeText('Индексы разделов')
            ->assertDontSeeText('Оптимизация БД')
            ->assertDontSeeText('Аудит ожидаемых индексов')
            ->assertDontSeeText('Индексы таблиц')
            ->assertSeeText('Разделы портала')
            ->assertSeeText('Внутренние ссылки')
            ->assertSeeText('Поля со ссылками')
            ->assertSeeText('Последние обновленные сериалы')
            ->assertSeeText('Требуют внимания')
            ->assertSeeText('Готовность по данным')
            ->assertSeeText('Качество сериалов')
            ->assertSeeText('Без опубликованного видео')
            ->assertSeeText('Временные срезы')
            ->assertSeeText('Последние запуски обновления')
            ->assertSeeText('Циклы')
            ->assertSeeText('Поиск')
            ->assertSeeText('Обслуживание')
            ->assertSeeText('Принудительно')
            ->assertSee('data-ui-poster-card', false)
            ->assertSee('data-ui-poster-frame', false)
            ->assertSeeText('Статусы страниц: 2 / 3')
            ->assertSeeText('Данные видео: 2 / 3')
            ->assertSeeText('Ключи видео: 1 / 4')
            ->assertSeeText('Проверка видео: 5, доступно 4, недоступно 1')
            ->assertSeeText('Справочники: удалено записей 3, связей 4')
            ->assertSeeText('Объединение: 1 / 2 / 3')
            ->assertSeeText('Некорректные ссылки: 1')
            ->assertSeeText('Разделы базы')
            ->assertSeeText('#'.$run->id)
            ->assertSee(e(route('stats.poster', $catalogTitle)), false)
            ->assertDontSeeText('Сводка каталога смотреть онлайн')
            ->assertDontSee('Route', false)
            ->assertDontSee('URI', false)
            ->assertDontSee('titles.show', false)
            ->assertDontSee('/titles/{catalogTitle}', false)
            ->assertDontSee('catalog_titles.source_url', false)
            ->assertDontSee('licensed_media.playback_url', false)
            ->assertDontSee('catalog_titles_published_year_idx', false)
            ->assertDontSee('licensed_media_status_title_episode_idx', false)
            ->assertDontSee('catalog_titles', false)
            ->assertDontSee('parse_failed', false)
            ->assertDontSee($sourceUrl, false)
            ->assertDontSee($sourcePageUrl, false)
            ->assertDontSee($runArgumentUrl, false)
            ->assertDontSee($eventContextUrl, false)
            ->assertDontSee($posterUrl, false)
            ->assertDontSee($mediaPath, false)
            ->assertDontSee($mediaPlaybackUrl, false)
            ->assertDontSee('https://seasonvar.ru/video-private-source.html', false)
            ->assertDontSee('https://seasonvar.ru/genre-private-source.html', false)
            ->assertDontSee('raw stack trace secret', false)
            ->assertDontSee('private context secret', false);

        Livewire::test(StatsDashboard::class)
            ->assertSee('Сводка каталога')
            ->assertDontSee($sourceUrl)
            ->assertDontSee($sourcePageUrl)
            ->assertDontSee($runArgumentUrl)
            ->assertDontSee($eventContextUrl)
            ->assertDontSee($posterUrl)
            ->assertDontSee($mediaPath)
            ->assertDontSee($mediaPlaybackUrl)
            ->assertDontSee('raw stack trace secret')
            ->assertDontSee('private context secret');
    }

    public function test_stats_poster_proxy_is_public_and_does_not_require_raw_url_in_page(): void
    {
        Http::preventStrayRequests();

        $posterUrl = 'https://media.example.com/private-poster.jpg';
        $catalogTitle = CatalogTitle::factory()->create([
            'title' => 'Постер статистики',
            'slug' => 'poster-statistiki',
            'poster_url' => $posterUrl,
        ]);

        Http::fake([
            $posterUrl => Http::response('fake-image-body', 200, ['Content-Type' => 'image/jpeg']),
        ]);
        $this->mock(CatalogStatsPosterUrlGuard::class)
            ->shouldReceive('verifiedUrl')
            ->once()
            ->with($posterUrl)
            ->andReturn(new VerifiedExternalUrlData($posterUrl, 'media.example.com', '93.184.216.34'));

        $response = $this->get(route('stats.poster', $catalogTitle));

        $response
            ->assertOk()
            ->assertHeader('Content-Type', 'image/jpeg')
            ->assertSee('fake-image-body', false);
    }

    public function test_unpublished_titles_cannot_be_resolved_by_public_routes(): void
    {
        Http::preventStrayRequests();

        $posterUrl = 'https://media.example.com/unpublished-poster.jpg';
        $catalogTitle = CatalogTitle::factory()->create([
            'slug' => 'unpublished-public-route',
            'poster_url' => $posterUrl,
            'is_published' => false,
        ]);

        Http::fake([
            $posterUrl => Http::response('private-image-body', 200, ['Content-Type' => 'image/jpeg']),
        ]);
        $this->mock(CatalogStatsPosterUrlGuard::class)
            ->shouldReceive('safeUrl')
            ->with($posterUrl)
            ->andReturn($posterUrl);

        $this->get(route('titles.show', $catalogTitle))->assertNotFound();
        $this->get(route('stats.poster', $catalogTitle))->assertNotFound();

        Http::assertNothingSent();
    }

    public function test_authenticated_title_binding_is_enforced_on_the_server(): void
    {
        $user = User::factory()->create();
        $catalogTitle = CatalogTitle::factory()->create([
            'title' => 'Сериал после входа',
            'slug' => 'serial-posle-vhoda',
            'audience' => 'authenticated',
        ]);

        $this->get(route('titles.show', $catalogTitle))->assertNotFound();

        $this->actingAs($user)
            ->get(route('titles.show', $catalogTitle))
            ->assertOk()
            ->assertSeeText('Сериал после входа');
    }

    public function test_title_route_rejects_malformed_ids_and_non_allowlisted_media_profile_values(): void
    {
        $catalogTitle = CatalogTitle::factory()->create();
        $route = route('titles.show', $catalogTitle);

        $this->from($route)
            ->get($route.'?'.Arr::query([
                'season' => ['1'],
                'episode' => '-1',
                'media' => 'not-an-id',
                'variant' => '../../private',
                'quality' => 'ultra',
                'format' => 'php',
            ]))
            ->assertRedirect($route)
            ->assertSessionHasErrors(['season', 'episode', 'media', 'variant', 'quality', 'format']);
    }

    public function test_livewire_actions_reject_non_scalar_arguments_without_changing_private_state(): void
    {
        $user = User::factory()->create();
        $catalogTitle = CatalogTitle::factory()->create();

        Livewire::actingAs($user)
            ->test(CatalogTitlePlayer::class, ['catalogTitleId' => $catalogTitle->id])
            ->call('selectSeason', ['1'])
            ->call('selectEpisode', ['1'])
            ->call('selectMedia', ['1'])
            ->call('setWatchlist', ['true'])
            ->call('setRating', ['8'])
            ->call('recordProgress', ['1'], ['token'], ['1'], ['0'], ['0'], ['false'])
            ->assertSet('season', '')
            ->assertSet('episode', '')
            ->assertSet('media', '');

        Livewire::test(CatalogSeries::class)
            ->call('sortBy', ['title_asc'])
            ->call('setPerPage', ['96'])
            ->call('setLetter', ['A'])
            ->call('removeYear', ['2026'])
            ->call('removeTaxonomy', ['genre'], ['drama'])
            ->call('removeExcluded', ['genre'], ['drama'])
            ->call('removeChoice', ['quality'], ['1080p']);

        $this->assertFalse(CatalogTitleUserState::query()->whereBelongsTo($user)->exists());
        $this->assertFalse(EpisodeViewProgress::query()->whereBelongsTo($user)->exists());
    }

    public function test_stats_issue_rows_merge_multiple_issue_categories(): void
    {
        $withoutPoster = CatalogTitle::factory()->create([
            'title' => 'Статистика без постера',
            'slug' => 'statistika-bez-postera',
            'poster_url' => null,
            'description' => 'Описание есть.',
        ]);
        LicensedMedia::factory()->create([
            'catalog_title_id' => $withoutPoster->id,
            'status' => 'published',
            'published_at' => now(),
        ]);

        $withoutDescription = CatalogTitle::factory()->create([
            'title' => 'Статистика без описания',
            'slug' => 'statistika-bez-opisaniya',
            'poster_url' => 'https://media.example.com/without-description.jpg',
            'description' => null,
        ]);
        LicensedMedia::factory()->create([
            'catalog_title_id' => $withoutDescription->id,
            'status' => 'published',
            'published_at' => now(),
        ]);

        $data = app(CatalogStatsPageBuilder::class)->data();

        $issueTitles = collect($data['statsIssueRows'])->pluck('title');

        $this->assertContains('Статистика без постера', $issueTitles);
        $this->assertContains('Статистика без описания', $issueTitles);
    }

    public function test_titles_page_fills_equal_size_poster_areas_without_double_frames(): void
    {
        CatalogTitle::factory()->create([
            'title' => 'Тестовый сериал',
            'slug' => 'testovyi-serial',
            'poster_url' => 'https://media.example.com/poster.jpg',
        ]);

        $response = $this->get(route('titles.index'));

        $response
            ->assertOk()
            ->assertSee('data-ui-poster-card', false)
            ->assertSee('data-ui-poster-frame', false)
            ->assertSee('data-ui-poster-layout="list"', false)
            ->assertSee('aspect-[2/3]', false)
            ->assertSee('object-contain', false)
            ->assertDontSee('object-cover', false)
            ->assertDontSee('scale-[1.02]', false)
            ->assertDontSee('ring-1 ring-slate-200', false);
    }

    public function test_titles_always_render_the_list_layout_and_description(): void
    {
        CatalogTitle::factory()->create([
            'title' => 'Сериал с описанием в списке',
            'slug' => 'serial-s-opisaniem-v-spiske',
            'description' => 'Полное описание для горизонтальной карточки.',
        ]);

        $this->get(route('titles.index', ['view' => 'grid']))
            ->assertOk()
            ->assertSee('data-ui-poster-layout="list"', false)
            ->assertDontSee('data-catalog-view-option', false)
            ->assertSeeText('Полное описание для горизонтальной карточки.');
    }

    public function test_titles_list_rows_show_the_latest_regular_season(): void
    {
        $title = CatalogTitle::factory()->create([
            'title' => 'Сериал с последним сезоном',
            'slug' => 'serial-s-poslednim-sezonom',
        ]);
        Season::factory()->for($title)->create(['number' => 1]);
        Season::factory()->for($title)->create(['number' => 4]);

        $this->get(route('titles.index'))
            ->assertOk()
            ->assertSeeText('Сезон 4');
    }

    public function test_title_page_server_renders_safe_localized_metadata_without_inferring_content_language(): void
    {
        $catalogTitle = CatalogTitle::factory()->create([
            'title' => 'Verified <em>Series</em>',
            'slug' => 'verified-series',
            'description' => '<p>Trusted description.</p><script>alert("private")</script>',
            'poster_url' => null,
        ]);
        CatalogTitleRating::query()->create([
            'catalog_title_id' => $catalogTitle->id,
            'provider' => 'imdb',
            'rating' => 8.4,
            'votes' => 21,
        ]);

        $response = $this
            ->withSession(['interface_locale_route' => 'en'])
            ->get(route('titles.show', $catalogTitle));
        $content = $response->assertOk()->getContent();
        $canonical = route('titles.show', $catalogTitle);

        $this->assertStringContainsString('<html lang="en">', $content);
        $this->assertStringContainsString('<link rel="canonical" href="'.$canonical.'">', $content);
        $this->assertStringContainsString('<meta property="og:locale" content="en_US">', $content);
        $this->assertStringContainsString('<meta property="og:title" content="Watch Verified Series online', $content);
        $this->assertStringContainsString('<meta property="og:description" content="Trusted description.">', $content);
        $this->assertStringContainsString('Trusted description.', $content);
        $this->assertStringContainsString('0 seasons', $content);
        $this->assertStringContainsString('0 episodes', $content);
        $this->assertStringContainsString('21 votes', $content);
        $this->assertStringContainsString('"@type":"TVSeries"', $content);
        $this->assertStringNotContainsString('alert', $content);
        $this->assertStringNotContainsString('&lt;em&gt;', $content);
        $this->assertDoesNotMatchRegularExpression('/"@type":"TVSeries"[^<]+"inLanguage"/', $content);
        $this->assertMatchesRegularExpression('/<h1[^>]*>.*Verified Series.*<\/h1>.*Trusted description\..*wire:snapshot/s', $content);
    }

    public function test_complex_catalog_filters_use_the_unfiltered_catalog_canonical(): void
    {
        $genre = Genre::query()->create(['name' => 'Драма', 'slug' => 'drama']);
        $country = Country::query()->create(['name' => 'Россия', 'slug' => 'rossiya']);
        CatalogTitle::factory()->create()->genres()->attach($genre);

        $response = $this->get(route('titles.index', [
            'genre' => [$genre->slug],
            'country' => [$country->slug],
            'sort' => 'title_asc',
        ]));

        $response
            ->assertOk()
            ->assertSee('<link rel="canonical" href="'.route('titles.index').'">', false)
            ->assertSee('<meta name="robots" content="noindex,nofollow', false);
    }

    public function test_catalog_state_links_are_nofollow_while_title_cards_remain_followable(): void
    {
        $catalogTitle = CatalogTitle::factory()->create([
            'title' => 'Карточка без nofollow',
            'slug' => 'kartochka-bez-nofollow',
        ]);

        $content = $this->get(route('titles.index'))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/<a[^>]*data-catalog-sort-option[^>]*rel="nofollow"[^>]*>/', $content);
        $this->assertStringNotContainsString('data-catalog-view-option', $content);
        $this->assertMatchesRegularExpression('/<a[^>]*data-catalog-alphabet-option[^>]*rel="nofollow"[^>]*>/', $content);
        $this->assertStringContainsString('href="'.route('titles.show', $catalogTitle).'"', $content);
        $this->assertDoesNotMatchRegularExpression('/<a[^>]*href="'.preg_quote(route('titles.show', $catalogTitle), '/').'"[^>]*rel="nofollow"/', $content);
    }

    public function test_public_catalog_pages_do_not_render_decorative_panel_subtitles(): void
    {
        $catalogTitle = CatalogTitle::factory()->create([
            'title' => 'Тестовый сериал без приписок',
            'slug' => 'testovyi-serial-bez-pripisok',
            'poster_url' => 'https://media.example.com/poster.jpg',
        ]);

        $hiddenPhrases = [
            'Справа показано число совпадений с текущими фильтрами.',
            'Свежие сериалы с постерами и основными счетчиками.',
            'Последние опубликованные видео и серии.',
            'Выберите серию в блоке сезонов. Плеер покажет доступное видео этой серии.',
            'Крупные кнопки подходят для телефона, планшета и ТВ.',
        ];

        foreach ([route('home'), route('titles.index'), route('titles.show', $catalogTitle)] as $url) {
            $response = $this->get($url)->assertOk();

            foreach ($hiddenPhrases as $phrase) {
                $response->assertDontSeeText($phrase);
            }

            foreach ([
                'id="table-of-contents"',
                'id="seo-summary"',
                'id="key-topics"',
                'id="semantic-glossary"',
                'id="long-tail-queries"',
                'id="popular-searches"',
                'id="quick-answers"',
            ] as $hiddenBlock) {
                $response->assertDontSee($hiddenBlock, false);
            }
        }
    }

    public function test_generated_title_search_blocks_are_hidden_on_public_title_page(): void
    {
        $catalogTitle = CatalogTitle::factory()->create([
            'title' => 'Знахарь',
            'slug' => 'znaxar',
        ]);

        $response = $this->get(route('titles.show', $catalogTitle));

        $response
            ->assertOk()
            ->assertSeeText('Знахарь')
            ->assertDontSee('id="popular-searches"', false)
            ->assertDontSee('id="semantic-clusters"', false)
            ->assertDontSee('id="long-tail-queries"', false);
    }

    public function test_title_scoped_catalog_search_stays_on_one_title(): void
    {
        $catalogTitle = CatalogTitle::factory()->create([
            'title' => 'Знахарь',
            'slug' => 'znaxar',
        ]);
        CatalogTitle::factory()->create([
            'title' => 'Другой сериал',
            'slug' => 'drugoi-serial',
            'description' => 'В описании встречается слово Знахарь, но это другой сериал.',
        ]);

        $response = $this->get(route('titles.index', [
            'q' => 'смотреть онлайн',
            'title' => $catalogTitle->slug,
        ]));

        $response
            ->assertOk()
            ->assertSeeText('Знахарь')
            ->assertSee('name="title"', false)
            ->assertSee('value="znaxar"', false)
            ->assertSee('title=znaxar', false)
            ->assertDontSeeText('Другой сериал');
    }

    public function test_catalog_search_prefers_exact_cyrillic_title_over_broad_description_matches(): void
    {
        CatalogTitle::factory()->create([
            'title' => 'Знахарь',
            'slug' => 'znaxar',
        ]);
        CatalogTitle::factory()->create([
            'title' => 'Другой сериал',
            'slug' => 'drugoi-serial',
            'description' => 'В описании встречается слово Знахарь, но название не совпадает.',
        ]);

        $response = $this->get(route('titles.index', [
            'q' => 'сериал знахарь описание жанры',
        ]));

        $response
            ->assertOk()
            ->assertSeeText('Знахарь')
            ->assertDontSeeText('Другой сериал');
    }

    public function test_catalog_search_uses_title_aliases_before_broad_description_matches(): void
    {
        $catalogTitle = CatalogTitle::factory()->create([
            'title' => 'The Witcher',
            'slug' => 'the-witcher',
            'description' => 'Основной сериал без кириллического названия.',
        ]);
        CatalogTitleAlias::query()->create([
            'catalog_title_id' => $catalogTitle->id,
            'name' => 'Ведьмак',
            'name_hash' => hash('sha256', Str::lower('Ведьмак')),
            'type' => 'alternative',
            'source' => 'test',
        ]);
        CatalogTitle::factory()->create([
            'title' => 'Другой фэнтези сериал',
            'slug' => 'drugoi-fentezi-serial',
            'description' => 'В описании случайно встречается слово Ведьмак, но это другой сериал.',
        ]);

        $response = $this->get(route('titles.index', [
            'q' => 'сериал ведьмак смотреть онлайн',
        ]));

        $response
            ->assertOk()
            ->assertSeeText('The Witcher')
            ->assertDontSeeText('Другой фэнтези сериал');
    }

    public function test_title_page_hides_aliases_that_repeat_displayed_names(): void
    {
        $title = CatalogTitle::factory()->create([
            'title' => 'Пандора (2019)/Pandora',
            'original_title' => 'Pandora',
            'slug' => 'pandora-2019pandora',
        ]);
        CatalogTitleAlias::query()->create([
            'catalog_title_id' => $title->id,
            'name' => 'Pandora',
            'name_hash' => CatalogTitleDisplayName::nameHash('Pandora'),
            'type' => 'original',
            'source' => 'info',
        ]);
        CatalogTitleAlias::query()->create([
            'catalog_title_id' => $title->id,
            'name' => 'Пандора (2019)',
            'name_hash' => CatalogTitleDisplayName::nameHash('Пандора (2019)'),
            'type' => 'source-title',
            'source' => 'title',
        ]);

        $this->get(route('titles.show', $title))
            ->assertOk()
            ->assertDontSeeText('Другие названия')
            ->assertSee('"alternateName":["Pandora"]', false);
    }

    public function test_alias_name_is_unique_for_a_title_independently_of_type(): void
    {
        $title = CatalogTitle::factory()->create();
        $hash = CatalogTitleDisplayName::nameHash('Pandora');

        CatalogTitleAlias::query()->create([
            'catalog_title_id' => $title->id,
            'name' => 'Pandora',
            'name_hash' => $hash,
            'type' => 'original',
            'source' => 'info',
        ]);

        $this->expectException(QueryException::class);

        CatalogTitleAlias::query()->create([
            'catalog_title_id' => $title->id,
            'name' => 'Pandora',
            'name_hash' => $hash,
            'type' => 'source-title',
            'source' => 'title',
        ]);
    }

    public function test_catalog_search_excludes_taxonomy_names_and_descriptions(): void
    {
        $catalogTitle = CatalogTitle::factory()->create([
            'title' => 'Сериал с актером',
            'slug' => 'serial-s-akterom',
        ]);
        $actor = Actor::query()->create([
            'name' => 'Иван Петров',
            'slug' => 'ivan-petrov',
        ]);
        $catalogTitle->actors()->attach($actor->id);
        CatalogTitle::factory()->create([
            'title' => 'Сериал без актера',
            'slug' => 'serial-bez-aktera',
            'description' => 'Описание без фамилии искомого актера.',
        ]);

        $response = $this->get(route('titles.index', [
            'q' => 'Петров',
        ]));

        $response
            ->assertOk()
            ->assertDontSeeText('Сериал с актером')
            ->assertDontSeeText('Сериал без актера');
    }

    public function test_catalog_sort_uses_descending_id_as_the_final_tie_breaker(): void
    {
        $indexedAt = now()->subHour();
        $firstTitle = CatalogTitle::factory()->create([
            'title' => 'Одинаковое название',
            'slug' => 'odinakovoe-nazvanie-pervoe',
            'year' => 2024,
            'indexed_at' => $indexedAt,
        ]);
        $secondTitle = CatalogTitle::factory()->create([
            'title' => 'Одинаковое название',
            'slug' => 'odinakovoe-nazvanie-vtoroe',
            'year' => 2024,
            'indexed_at' => $indexedAt,
        ]);
        $expectedOrder = [
            'href="'.route('titles.show', $secondTitle).'"',
            'href="'.route('titles.show', $firstTitle).'"',
        ];

        foreach (['updated', 'year_desc', 'year_asc', 'episodes_desc', 'title_asc', 'with_video'] as $sort) {
            $this->get(route('titles.index', ['sort' => $sort]))
                ->assertOk()
                ->assertSeeInOrder($expectedOrder, false);
        }
    }

    public function test_invalid_year_filter_is_rejected_before_catalog_query(): void
    {
        CatalogTitle::factory()->create([
            'title' => 'Видимый сериал',
            'slug' => 'vidimyi-serial',
            'year' => 2024,
        ]);

        $this->get(route('titles.index', ['year' => 'abcd']))
            ->assertOk()
            ->assertSeeText('Год должен быть целым числом.')
            ->assertDontSeeText('Видимый сериал');
    }

    public function test_title_page_uses_one_readable_similarity_list_without_country_section(): void
    {
        $catalogTitle = CatalogTitle::factory()->create([
            'title' => 'Черная лагуна',
            'slug' => 'cernaia-lagunael-internado',
            'year' => 2007,
        ]);
        $genre = Genre::query()->create([
            'name' => 'Детектив',
            'slug' => 'detektiv',
        ]);
        $country = Country::query()->create([
            'name' => 'Испания',
            'slug' => 'ispaniia',
        ]);
        $catalogTitle->genres()->attach($genre->id);
        $catalogTitle->countries()->attach($country->id);

        $genreTitle = CatalogTitle::factory()->create([
            'title' => 'Очень длинное название похожего сериала с переносом текста',
            'original_title' => 'Long Original Related Series Title',
            'slug' => 'ocen-dlinnoe-nazvanie',
            'year' => 2008,
            'indexed_at' => now(),
        ]);
        $genreTitle->genres()->attach($genre->id);
        $genreSeason = Season::factory()->create([
            'catalog_title_id' => $genreTitle->id,
            'number' => 2,
        ]);
        Episode::factory()
            ->count(3)
            ->sequence(['number' => 1], ['number' => 2], ['number' => 3])
            ->create([
                'season_id' => $genreSeason->id,
            ]);
        LicensedMedia::factory()->create([
            'catalog_title_id' => $genreTitle->id,
            'status' => 'published',
        ]);

        CatalogTitle::factory()->create([
            'title' => 'Похожий сериал того же года',
            'slug' => 'poxozij-serial-togo-ze-goda',
            'year' => 2007,
            'indexed_at' => now()->subMinute(),
        ]);

        $countryTitle = CatalogTitle::factory()->create([
            'title' => 'Похожий только по стране',
            'slug' => 'poxozij-tolko-po-strane',
            'year' => 2010,
            'indexed_at' => now()->subMinutes(2),
        ]);
        $countryTitle->countries()->attach($country->id);

        $response = $this->get(route('titles.show', $catalogTitle));

        $response
            ->assertOk()
            ->assertSeeText('Похожие сериалы')
            ->assertSeeText('Похожие жанры и темы')
            ->assertSeeText('Очень длинное название похожего сериала с переносом текста')
            ->assertSeeText('Long Original Related Series Title')
            ->assertSee('data-recommendation-list', false)
            ->assertSee('break-words', false)
            ->assertDontSeeText('Похожий только по стране')
            ->assertDontSeeText('По похожим жанрам')
            ->assertDontSeeText('За 2007 год')
            ->assertDontSeeText('Похожие сериалы пока не подобраны')
            ->assertDontSeeText('Еще из жанра')
            ->assertDontSeeText('Еще из страны')
            ->assertDontSeeText('Еще за 2007 год');
    }

    public function test_title_page_uses_precomputed_recommendations_in_rank_order(): void
    {
        $catalogTitle = CatalogTitle::factory()->create([
            'title' => 'Главный рекомендательный сериал',
            'slug' => 'glavnyi-rekomendatelnyi-serial',
        ]);
        $genre = Genre::query()->create([
            'name' => 'Детектив',
            'slug' => 'detektiv-rekomendacij',
        ]);
        $firstRecommendation = CatalogTitle::factory()->create([
            'title' => 'Первый точный совет',
            'slug' => 'pervyi-tocnyi-sovet',
        ]);
        $secondRecommendation = CatalogTitle::factory()->create([
            'title' => 'Второй точный совет',
            'slug' => 'vtoroi-tocnyi-sovet',
        ]);
        $catalogTitle->genres()->attach($genre->id);
        $firstRecommendation->genres()->attach($genre->id);
        $secondRecommendation->genres()->attach($genre->id);
        LicensedMedia::factory()->create([
            'catalog_title_id' => $firstRecommendation->id,
            'status' => 'published',
            'published_at' => now(),
        ]);
        LicensedMedia::factory()->create([
            'catalog_title_id' => $secondRecommendation->id,
            'status' => 'published',
            'published_at' => now(),
        ]);
        CatalogTitleRecommendation::query()->create([
            'catalog_title_id' => $catalogTitle->id,
            'recommended_title_id' => $secondRecommendation->id,
            'score' => 720,
            'rank' => 2,
            'reasons' => ['genre' => ['count' => 1, 'score' => 520]],
            'computed_at' => now(),
        ]);
        CatalogTitleRecommendation::query()->create([
            'catalog_title_id' => $catalogTitle->id,
            'recommended_title_id' => $firstRecommendation->id,
            'score' => 840,
            'rank' => 1,
            'reasons' => ['genre' => ['count' => 1, 'score' => 520]],
            'computed_at' => now(),
        ]);

        $response = $this->get(route('titles.show', $catalogTitle));

        $response
            ->assertOk()
            ->assertSeeText('Похожие сериалы')
            ->assertSeeText('Позиция 1')
            ->assertSeeText('Похожие жанры и темы')
            ->assertSee('data-recommendation-list', false)
            ->assertDontSeeText('Ближайшие совпадения')
            ->assertDontSeeText('По жанрам')
            ->assertSeeTextInOrder([
                'Первый точный совет',
                'Второй точный совет',
            ]);
    }

    public function test_title_page_recommendations_respect_the_current_viewers_publication_boundary(): void
    {
        $user = User::factory()->create();
        $catalogTitle = CatalogTitle::factory()->create([
            'title' => 'Главный сериал с ограниченными советами',
            'slug' => 'glavnyi-serial-s-ogranichennymi-sovetami',
        ]);
        $publicRecommendation = CatalogTitle::factory()->create([
            'title' => 'Публичный совет',
            'slug' => 'publichnyi-sovet',
        ]);
        $authenticatedRecommendation = CatalogTitle::factory()->create([
            'title' => 'Совет после входа',
            'slug' => 'sovet-posle-vhoda',
            'audience' => 'authenticated',
        ]);
        $hiddenRecommendation = CatalogTitle::factory()->create([
            'title' => 'Скрытый совет',
            'slug' => 'skrytyi-sovet',
            'publication_status' => 'hidden',
        ]);

        collect([$publicRecommendation, $authenticatedRecommendation, $hiddenRecommendation])
            ->each(function (CatalogTitle $recommendedTitle) use ($catalogTitle): void {
                LicensedMedia::factory()->create([
                    'catalog_title_id' => $recommendedTitle->id,
                    'status' => 'published',
                    'published_at' => now(),
                ]);
                CatalogTitleRecommendation::query()->create([
                    'catalog_title_id' => $catalogTitle->id,
                    'recommended_title_id' => $recommendedTitle->id,
                    'score' => 900 - $recommendedTitle->id,
                    'rank' => $recommendedTitle->id,
                    'reasons' => ['genre' => ['count' => 1, 'score' => 500]],
                    'computed_at' => now(),
                ]);
            });

        $this->get(route('titles.show', $catalogTitle))
            ->assertOk()
            ->assertSeeText('Публичный совет')
            ->assertDontSeeText('Совет после входа')
            ->assertDontSeeText('Скрытый совет');

        $this->actingAs($user)
            ->get(route('titles.show', $catalogTitle))
            ->assertOk()
            ->assertSeeText('Публичный совет')
            ->assertSeeText('Совет после входа')
            ->assertDontSeeText('Скрытый совет');
    }

    public function test_title_page_limits_stale_precomputed_recommendations(): void
    {
        config(['seasonvar.recommendations.max_per_title' => 3]);

        $catalogTitle = CatalogTitle::factory()->create([
            'title' => 'Главный сериал с лишними советами',
            'slug' => 'glavnyi-serial-s-lishnimi-sovetami',
        ]);

        collect(range(1, 5))->each(function (int $rank) use ($catalogTitle): void {
            $recommendedTitle = CatalogTitle::factory()->create([
                'title' => 'Лишний совет '.$rank,
                'slug' => 'lishnii-sovet-'.$rank,
            ]);

            LicensedMedia::factory()->create([
                'catalog_title_id' => $recommendedTitle->id,
                'status' => 'published',
                'published_at' => now(),
            ]);

            CatalogTitleRecommendation::query()->create([
                'catalog_title_id' => $catalogTitle->id,
                'recommended_title_id' => $recommendedTitle->id,
                'score' => 1000 - $rank,
                'rank' => $rank,
                'reasons' => ['genre' => ['count' => 1, 'score' => 500]],
                'computed_at' => now(),
            ]);
        });

        $response = $this->get(route('titles.show', $catalogTitle));

        $response
            ->assertOk()
            ->assertSeeText('Лишний совет 1')
            ->assertSeeText('Лишний совет 2')
            ->assertSeeText('Лишний совет 3')
            ->assertDontSeeText('Лишний совет 4')
            ->assertDontSeeText('Лишний совет 5');
    }

    public function test_title_page_renders_when_recommendation_table_is_missing(): void
    {
        Schema::drop('catalog_title_recommendations');

        $catalogTitle = CatalogTitle::factory()->create([
            'title' => 'Сериал без таблицы рекомендаций',
            'slug' => 'serial-bez-tablicy-rekomendacij',
            'year' => 2024,
        ]);

        $this->get(route('titles.show', $catalogTitle))
            ->assertOk()
            ->assertSeeText('Сериал без таблицы рекомендаций');
    }

    public function test_title_page_loads_only_the_active_season_episodes_and_keeps_exact_visible_counts(): void
    {
        $catalogTitle = CatalogTitle::factory()->create([
            'title' => 'Большой сериал',
            'slug' => 'bolshoi-serial',
        ]);
        $firstSeason = Season::factory()->create([
            'catalog_title_id' => $catalogTitle->id,
            'number' => 1,
        ]);
        $secondSeason = Season::factory()->create([
            'catalog_title_id' => $catalogTitle->id,
            'number' => 2,
        ]);
        $firstEpisode = Episode::factory()->create([
            'season_id' => $firstSeason->id,
            'number' => 1,
            'title' => 'Серия активного сезона',
        ]);
        $secondEpisode = Episode::factory()->create([
            'season_id' => $secondSeason->id,
            'number' => 1,
            'title' => 'Серия неактивного сезона',
        ]);
        LicensedMedia::factory()->create([
            'catalog_title_id' => $catalogTitle->id,
            'season_id' => $firstSeason->id,
            'episode_id' => $firstEpisode->id,
            'status' => 'published',
            'published_at' => now(),
        ]);
        LicensedMedia::factory()->create([
            'catalog_title_id' => $catalogTitle->id,
            'season_id' => $secondSeason->id,
            'episode_id' => $secondEpisode->id,
            'status' => 'published',
            'published_at' => now(),
        ]);

        $this->get(route('titles.show', $catalogTitle))
            ->assertOk()
            ->assertSeeText('2 серии')
            ->assertSeeText('Начать с 1 серии')
            ->assertSeeText('Серия активного сезона')
            ->assertDontSeeText('Серия неактивного сезона');

        $this->get(route('titles.show', [
            'catalogTitle' => $catalogTitle,
            'season' => $secondSeason->id,
        ]))
            ->assertOk()
            ->assertSeeText('Серия неактивного сезона')
            ->assertDontSeeText('Серия активного сезона');
    }

    public function test_catalog_title_player_resolves_continue_next_and_replay_actions_from_progress(): void
    {
        $user = User::factory()->create();
        $catalogTitle = CatalogTitle::factory()->create([
            'title' => 'Сериал с прогрессом',
            'slug' => 'serial-s-progressom',
        ]);
        $season = Season::factory()->create([
            'catalog_title_id' => $catalogTitle->id,
            'number' => 1,
        ]);
        $episodes = collect(range(1, 3))->map(function (int $number) use ($catalogTitle, $season): Episode {
            $episode = Episode::factory()->create([
                'season_id' => $season->id,
                'number' => $number,
                'title' => 'Серия '.$number,
            ]);
            LicensedMedia::factory()->create([
                'catalog_title_id' => $catalogTitle->id,
                'season_id' => $season->id,
                'episode_id' => $episode->id,
                'status' => 'published',
                'published_at' => now(),
            ]);

            return $episode;
        });
        EpisodeViewProgress::query()->create([
            'user_id' => $user->id,
            'catalog_title_id' => $catalogTitle->id,
            'episode_id' => $episodes[1]->id,
            'position_seconds' => 120,
            'duration_seconds' => 600,
            'last_watched_at' => now(),
        ]);

        Livewire::actingAs($user)
            ->test(CatalogTitlePlayer::class, ['catalogTitleId' => $catalogTitle->id])
            ->assertSeeText('Продолжить 2 серию');

        EpisodeViewProgress::query()->where('episode_id', $episodes[1]->id)->update([
            'position_seconds' => 600,
            'completed_at' => now(),
            'last_watched_at' => now(),
        ]);

        Livewire::actingAs($user)
            ->test(CatalogTitlePlayer::class, ['catalogTitleId' => $catalogTitle->id])
            ->assertSeeText('Следующая: 3 серия');

        EpisodeViewProgress::query()->where('episode_id', $episodes[1]->id)->delete();
        EpisodeViewProgress::query()->create([
            'user_id' => $user->id,
            'catalog_title_id' => $catalogTitle->id,
            'episode_id' => $episodes[2]->id,
            'position_seconds' => 600,
            'duration_seconds' => 600,
            'completed_at' => now(),
            'last_watched_at' => now(),
        ]);

        Livewire::actingAs($user)
            ->test(CatalogTitlePlayer::class, ['catalogTitleId' => $catalogTitle->id])
            ->assertSeeText('Смотреть сначала');
    }

    public function test_catalog_title_player_navigates_only_accessible_episodes_inside_the_current_release_lane(): void
    {
        $catalogTitle = CatalogTitle::factory()->create([
            'title' => 'Сериал с навигацией',
            'slug' => 'serial-s-navigatsiei',
        ]);
        $firstSeason = Season::factory()->create([
            'catalog_title_id' => $catalogTitle->id,
            'number' => 1,
            'sort_order' => 1,
        ]);
        $secondSeason = Season::factory()->create([
            'catalog_title_id' => $catalogTitle->id,
            'number' => 2,
            'sort_order' => 2,
        ]);
        $specialSeason = Season::factory()->create([
            'catalog_title_id' => $catalogTitle->id,
            'number' => 1,
            'kind' => ReleaseKind::Special,
            'sort_order' => 1,
        ]);
        $createEpisode = function (Season $season, array $attributes, bool $withSource = true) use ($catalogTitle): Episode {
            $episode = Episode::factory()->create([
                'season_id' => $season->id,
                ...$attributes,
            ]);

            if ($withSource) {
                LicensedMedia::factory()->create([
                    'catalog_title_id' => $catalogTitle->id,
                    'season_id' => $season->id,
                    'episode_id' => $episode->id,
                    'status' => 'published',
                    'published_at' => now(),
                ]);
            }

            return $episode;
        };

        $firstEpisode = $createEpisode($firstSeason, [
            'number' => 101,
            'sort_order' => 1,
            'title' => 'Первый доступный выпуск',
        ]);
        $createEpisode($firstSeason, [
            'number' => 102,
            'sort_order' => 2,
            'publication_status' => 'hidden',
            'title' => 'Скрытый выпуск',
        ]);
        $createEpisode($firstSeason, [
            'number' => 103,
            'sort_order' => 3,
            'available_until' => now()->subMinute(),
            'title' => 'Истёкший выпуск',
        ]);
        $createEpisode($firstSeason, [
            'number' => 104,
            'sort_order' => 4,
            'title' => 'Выпуск без источника',
        ], false);
        $lastFirstSeasonEpisode = $createEpisode($firstSeason, [
            'number' => 105,
            'sort_order' => 5,
            'title' => 'Последний выпуск первого сезона',
        ]);
        $firstSpecial = $createEpisode($firstSeason, [
            'number' => 1,
            'kind' => ReleaseKind::Special,
            'sort_order' => 1,
            'title' => 'Первый спецвыпуск',
        ]);
        $firstSecondSeasonEpisode = $createEpisode($secondSeason, [
            'number' => 201,
            'sort_order' => 1,
            'title' => 'Первый выпуск второго сезона',
        ]);
        $lastEpisode = $createEpisode($secondSeason, [
            'number' => 202,
            'sort_order' => 2,
            'title' => 'Последний обычный выпуск',
        ]);
        $secondSpecial = $createEpisode($secondSeason, [
            'number' => 1,
            'kind' => ReleaseKind::Special,
            'sort_order' => 1,
            'title' => 'Второй спецвыпуск',
        ]);
        $specialSeasonEpisode = $createEpisode($specialSeason, [
            'number' => 1,
            'kind' => ReleaseKind::Special,
            'sort_order' => 1,
            'title' => 'Спецвыпуск отдельного сезона',
        ]);

        $component = Livewire::test(CatalogTitlePlayer::class, ['catalogTitleId' => $catalogTitle->id])
            ->assertSeeHtml('wire:key="episode-navigation-next-'.$lastFirstSeasonEpisode->id.'"')
            ->assertDontSeeHtml('wire:key="episode-navigation-previous-')
            ->assertDontSeeText('Скрытый выпуск')
            ->assertDontSeeText('Истёкший выпуск')
            ->assertDontSeeText('Выпуск без источника')
            ->call('selectEpisode', $lastFirstSeasonEpisode->id)
            ->assertSet('season', (string) $firstSeason->id)
            ->assertSeeHtml('wire:key="episode-navigation-previous-'.$firstEpisode->id.'"')
            ->assertSeeHtml('wire:key="episode-navigation-next-'.$firstSecondSeasonEpisode->id.'"')
            ->call('selectEpisode', $firstSecondSeasonEpisode->id)
            ->assertSet('season', (string) $secondSeason->id)
            ->assertSeeHtml('wire:key="episode-navigation-previous-'.$lastFirstSeasonEpisode->id.'"')
            ->assertSeeHtml('wire:key="episode-navigation-next-'.$lastEpisode->id.'"')
            ->call('selectEpisode', $lastEpisode->id)
            ->assertDontSeeHtml('wire:key="episode-navigation-next-')
            ->call('selectEpisode', $firstSpecial->id)
            ->assertSeeHtml('wire:key="episode-navigation-next-'.$secondSpecial->id.'"')
            ->assertDontSeeHtml('wire:key="episode-navigation-next-'.$specialSeasonEpisode->id.'"')
            ->call('selectEpisode', $secondSpecial->id)
            ->assertDontSeeHtml('wire:key="episode-navigation-next-');

        $component->call('selectEpisode', $specialSeasonEpisode->id)
            ->assertSet('season', (string) $specialSeason->id)
            ->assertDontSeeHtml('wire:key="episode-navigation-previous-')
            ->assertDontSeeHtml('wire:key="episode-navigation-next-');

        $user = User::factory()->create();
        EpisodeViewProgress::query()->create([
            'user_id' => $user->id,
            'catalog_title_id' => $catalogTitle->id,
            'episode_id' => $lastEpisode->id,
            'position_seconds' => 600,
            'duration_seconds' => 600,
            'completed_at' => now(),
            'last_watched_at' => now(),
        ]);

        Livewire::actingAs($user)
            ->test(CatalogTitlePlayer::class, ['catalogTitleId' => $catalogTitle->id])
            ->assertSeeText('Смотреть сначала')
            ->assertDontSeeText('Следующая: 1 серия');
    }

    public function test_catalog_title_player_persists_only_the_authenticated_users_private_state(): void
    {
        $this->assertTrue(Schema::hasColumns('episode_view_progress', [
            'licensed_media_id',
            'progress_percent',
            'first_started_at',
            'playback_session_id',
            'playback_event_sequence',
        ]));

        $user = User::factory()->create();
        $catalogTitle = CatalogTitle::factory()->create();
        $season = Season::factory()->create(['catalog_title_id' => $catalogTitle->id]);
        $secondSeason = Season::factory()->create([
            'catalog_title_id' => $catalogTitle->id,
            'number' => $season->number + 1,
        ]);
        $episode = Episode::factory()->create(['season_id' => $season->id]);
        $media = LicensedMedia::factory()->create([
            'catalog_title_id' => $catalogTitle->id,
            'season_id' => $season->id,
            'episode_id' => $episode->id,
            'duration_seconds' => 600,
            'status' => 'published',
            'published_at' => now(),
        ]);
        $progressSessions = app(CatalogPlaybackProgressSession::class);
        $firstSession = $progressSessions->issue($user, $catalogTitle, $episode, $media);
        $component = Livewire::actingAs($user)
            ->test(CatalogTitlePlayer::class, ['catalogTitleId' => $catalogTitle->id]);

        $component
            ->call('setWatchlist', true)
            ->call('setRating', 8)
            ->call('recordProgress', $episode->id, $firstSession, 1, 0, 600, false)
            ->call('recordProgress', $episode->id, $firstSession, 1, 90, 600, false)
            ->call('recordProgress', $episode->id, $firstSession, 2, 125, 1000, false)
            ->call('selectSeason', $secondSeason->id)
            ->assertSet('season', (string) $secondSeason->id)
            ->assertSeeText('В списке просмотра')
            ->assertSeeText('Ваша оценка: 8 из 10');

        $state = CatalogTitleUserState::query()
            ->whereBelongsTo($user)
            ->whereBelongsTo($catalogTitle)
            ->sole();
        $progress = EpisodeViewProgress::query()
            ->whereBelongsTo($user)
            ->whereBelongsTo($episode)
            ->sole();

        $this->assertTrue($state->in_watchlist);
        $this->assertSame(8, $state->rating);
        $this->assertSame(125, $progress->position_seconds);
        $this->assertSame(600, $progress->duration_seconds);
        $this->assertSame(20, $progress->progress_percent);
        $this->assertSame($media->id, $progress->licensed_media_id);
        $this->assertSame(2, $progress->playback_event_sequence);
        $this->assertSame(26, mb_strlen((string) $progress->playback_session_id));
        $this->assertNotNull($progress->first_started_at);
        $this->assertNull($progress->completed_at);
        $firstStartedAt = $progress->first_started_at;

        $this->travel(1)->second();
        $secondSession = $progressSessions->issue($user, $catalogTitle, $episode, $media);

        $component
            ->call('recordProgress', $episode->id, $secondSession, 1, 200, 600, false)
            ->call('recordProgress', $episode->id, $firstSession, 3, 300, 600, false);

        $progress->refresh();
        $this->assertSame(200, $progress->position_seconds);
        $this->assertSame(1, $progress->playback_event_sequence);
        $this->assertSame($firstStartedAt?->toISOString(), $progress->first_started_at?->toISOString());

        config()->set('playback.progress.completion_percent', 100);
        config()->set('playback.progress.completion_remaining_seconds', 15);

        $component->call('recordProgress', $episode->id, $secondSession, 2, 570, 600, false);

        $progress->refresh();
        $this->assertSame(95, $progress->progress_percent);
        $this->assertNull($progress->completed_at);

        $component
            ->call('recordProgress', $episode->id, $secondSession, 3, 586, 600, false)
            ->call('recordProgress', $episode->id, $secondSession, 2, 10, 600, false);

        $progress->refresh();
        $this->assertSame(586, $progress->position_seconds);
        $this->assertSame(97, $progress->progress_percent);
        $this->assertNotNull($progress->completed_at);
        $completedAt = $progress->completed_at;

        $this->travel(1)->second();
        $replaySession = $progressSessions->issue($user, $catalogTitle, $episode, $media);

        $component
            ->call('recordProgress', $episode->id, $replaySession, 1, 10, 600, false)
            ->call('recordProgress', $episode->id, $secondSession, 4, 580, 600, false);

        $progress->refresh();
        $this->assertSame(10, $progress->position_seconds);
        $this->assertSame(1, $progress->progress_percent);
        $this->assertSame($completedAt?->toISOString(), $progress->completed_at?->toISOString());
        $this->assertSame(1, EpisodeViewProgress::query()
            ->whereBelongsTo($user)
            ->whereBelongsTo($episode)
            ->count());

        Livewire::actingAs($user)
            ->test(CatalogTitlePlayer::class, ['catalogTitleId' => $catalogTitle->id])
            ->assertSeeText('Продолжить '.$episode->number.' серию');

        $component
            ->call('recordProgress', $episode->id + 999999, $replaySession, 2, 20, 600, false)
            ->call('recordProgress', $episode->id, $replaySession, 2, -1, 600, false)
            ->call('recordProgress', $episode->id, $replaySession, 2, 20, -1, false)
            ->call('recordProgress', $episode->id, $replaySession, 2, 606, 600, false);

        $this->assertSame(10, $progress->refresh()->position_seconds);

        $missingDurationEpisode = Episode::factory()->create([
            'season_id' => $season->id,
            'number' => $episode->number + 1,
        ]);
        $missingDurationMedia = LicensedMedia::factory()->create([
            'catalog_title_id' => $catalogTitle->id,
            'season_id' => $season->id,
            'episode_id' => $missingDurationEpisode->id,
            'duration_seconds' => null,
            'status' => 'published',
            'published_at' => now(),
        ]);
        $missingDurationSession = $progressSessions->issue(
            $user,
            $catalogTitle,
            $missingDurationEpisode,
            $missingDurationMedia,
        );

        $component
            ->call('recordProgress', $missingDurationEpisode->id, $missingDurationSession, 1, 100, 0, false)
            ->call('recordProgress', $missingDurationEpisode->id, $missingDurationSession, 2, 100, 0, true);

        $missingDurationProgress = EpisodeViewProgress::query()
            ->whereBelongsTo($user)
            ->whereBelongsTo($missingDurationEpisode)
            ->sole();

        $this->assertSame(0, $missingDurationProgress->duration_seconds);
        $this->assertNull($missingDurationProgress->progress_percent);
        $this->assertNotNull($missingDurationProgress->completed_at);

        $missingDurationEpisode->update(['publication_status' => 'hidden']);

        Livewire::actingAs($user)
            ->test(CatalogTitlePlayer::class, ['catalogTitleId' => $catalogTitle->id])
            ->call('recordProgress', $missingDurationEpisode->id, $missingDurationSession, 3, 200, 0, false)
            ->assertNotFound();

        $this->assertSame(100, $missingDurationProgress->refresh()->position_seconds);

        $expiringEpisode = Episode::factory()->create([
            'season_id' => $season->id,
            'number' => $missingDurationEpisode->number + 1,
        ]);
        $expiringMedia = LicensedMedia::factory()->create([
            'catalog_title_id' => $catalogTitle->id,
            'season_id' => $season->id,
            'episode_id' => $expiringEpisode->id,
            'duration_seconds' => 600,
            'status' => 'published',
            'published_at' => now(),
        ]);
        config()->set('playback.progress.session_ttl_seconds', 60);
        $expiredSession = $progressSessions->issue($user, $catalogTitle, $expiringEpisode, $expiringMedia);
        $this->travel(61)->seconds();

        Livewire::actingAs($user)
            ->test(CatalogTitlePlayer::class, ['catalogTitleId' => $catalogTitle->id])
            ->call('recordProgress', $expiringEpisode->id, $expiredSession, 1, 30, 600, false)
            ->call('recordProgress', $expiringEpisode->id, 'invalid-session', 1, 30, 600, false);

        $this->assertFalse(EpisodeViewProgress::query()->whereBelongsTo($expiringEpisode)->exists());

        $otherUser = User::factory()->create();
        Livewire::actingAs($otherUser)
            ->test(CatalogTitlePlayer::class, ['catalogTitleId' => $catalogTitle->id])
            ->call('recordProgress', $episode->id, $replaySession, 2, 50, 600, false);

        $this->assertFalse(EpisodeViewProgress::query()->whereBelongsTo($otherUser)->exists());

        Livewire::actingAs($user)
            ->test(CatalogTitlePlayer::class, ['catalogTitleId' => $catalogTitle->id])
            ->call('setRating', '')
            ->assertSeeText('Ваша оценка');

        $this->assertNull($state->fresh()->rating);

        auth()->logout();

        Livewire::test(CatalogTitlePlayer::class, ['catalogTitleId' => $catalogTitle->id])
            ->call('setWatchlist', true)
            ->assertForbidden();

        Livewire::test(CatalogTitlePlayer::class, ['catalogTitleId' => $catalogTitle->id])
            ->call('setRating', 8)
            ->assertForbidden();
    }

    public function test_watchlist_and_user_ratings_are_idempotent_profile_scoped_and_aggregated_separately(): void
    {
        config()->set('catalog.user_rating.minimum', 2);
        config()->set('catalog.user_rating.maximum', 9);

        $catalogTitle = CatalogTitle::factory()->create();
        CatalogTitleRating::query()->create([
            'catalog_title_id' => $catalogTitle->id,
            'provider' => 'kinopoisk',
            'rating' => 9.6,
            'votes' => 1200,
            'raw_value' => '9.6/10',
        ]);
        $firstUser = User::factory()->create();
        $secondUser = User::factory()->create();

        $firstComponent = Livewire::actingAs($firstUser)
            ->test(CatalogTitlePlayer::class, ['catalogTitleId' => $catalogTitle->id])
            ->call('setWatchlist', true)
            ->call('setRating', 8);
        $firstUpdatedAt = CatalogTitleUserState::query()
            ->whereBelongsTo($firstUser)
            ->whereBelongsTo($catalogTitle)
            ->sole()
            ->updated_at;

        $this->travel(1)->second();

        $firstComponent
            ->call('setWatchlist', true)
            ->call('setRating', 8)
            ->assertSeeText('В списке просмотра')
            ->assertSeeText('В списках просмотра:')
            ->assertSeeText('8,0 из 9 (1 оценка)');

        $this->assertSame(1, CatalogTitleUserState::query()
            ->whereBelongsTo($firstUser)
            ->whereBelongsTo($catalogTitle)
            ->count());
        $this->assertSame($firstUpdatedAt?->toISOString(), CatalogTitleUserState::query()
            ->whereBelongsTo($firstUser)
            ->whereBelongsTo($catalogTitle)
            ->sole()
            ->updated_at?->toISOString());

        $emptyUser = User::factory()->create();
        Livewire::actingAs($emptyUser)
            ->test(CatalogTitlePlayer::class, ['catalogTitleId' => $catalogTitle->id])
            ->call('setWatchlist', false)
            ->call('setRating', '');

        $this->assertFalse(CatalogTitleUserState::query()->whereBelongsTo($emptyUser)->exists());

        Livewire::actingAs($secondUser)
            ->test(CatalogTitlePlayer::class, ['catalogTitleId' => $catalogTitle->id])
            ->call('setWatchlist', true)
            ->call('setRating', 6)
            ->assertSeeText('7,0 из 9 (2 оценки)');

        $this->assertSame(2, CatalogTitleUserState::query()
            ->whereBelongsTo($catalogTitle)
            ->where('in_watchlist', true)
            ->count());

        $firstComponent = Livewire::actingAs($firstUser)
            ->test(CatalogTitlePlayer::class, ['catalogTitleId' => $catalogTitle->id]);

        $firstComponent
            ->call('setRating', 9)
            ->assertSeeText('7,5 из 9 (2 оценки)')
            ->call('setWatchlist', false)
            ->call('setWatchlist', false)
            ->assertDontSeeText('В списке просмотра')
            ->call('setRating', '')
            ->assertSeeText('6,0 из 9 (1 оценка)');

        $this->assertFalse(CatalogTitleUserState::query()
            ->whereBelongsTo($firstUser)
            ->whereBelongsTo($catalogTitle)
            ->sole()
            ->in_watchlist);
        $this->assertSame(1, CatalogTitleUserState::query()
            ->whereBelongsTo($catalogTitle)
            ->where('in_watchlist', true)
            ->count());
        $this->assertNull(CatalogTitleUserState::query()
            ->whereBelongsTo($firstUser)
            ->whereBelongsTo($catalogTitle)
            ->sole()
            ->rating);
        $this->assertDatabaseHas('catalog_title_ratings', [
            'catalog_title_id' => $catalogTitle->id,
            'provider' => 'kinopoisk',
            'rating' => 9.6,
            'votes' => 1200,
        ]);
    }

    public function test_user_state_writes_validate_the_configured_rating_range_and_title_access(): void
    {
        config()->set('catalog.user_rating.minimum', 2);
        config()->set('catalog.user_rating.maximum', 5);

        $user = User::factory()->create();
        $catalogTitle = CatalogTitle::factory()->create();
        $component = Livewire::actingAs($user)
            ->test(CatalogTitlePlayer::class, ['catalogTitleId' => $catalogTitle->id]);

        $component
            ->call('setRating', 1)
            ->assertHasErrors('rating')
            ->call('setRating', 6)
            ->assertHasErrors('rating')
            ->call('setRating', 'invalid')
            ->assertHasErrors('rating')
            ->call('setRating', 5)
            ->assertHasNoErrors('rating');

        $this->assertSame(5, CatalogTitleUserState::query()
            ->whereBelongsTo($user)
            ->whereBelongsTo($catalogTitle)
            ->sole()
            ->rating);

        $catalogTitle->update(['publication_status' => 'hidden']);

        $this->assertThrows(
            fn () => app(CatalogUserStateService::class)->setWatchlist($user, $catalogTitle, true),
            AuthorizationException::class,
        );
        $this->assertThrows(
            fn () => app(CatalogUserStateService::class)->setRating($user, $catalogTitle, 4),
            AuthorizationException::class,
        );
    }

    public function test_catalog_title_player_excludes_unavailable_releases_from_counts_actions_and_selections(): void
    {
        $catalogTitle = CatalogTitle::factory()->create([
            'title' => 'Сериал с ограничениями',
            'slug' => 'serial-s-ogranicheniiami',
        ]);
        $season = Season::factory()->create(['catalog_title_id' => $catalogTitle->id, 'number' => 1]);
        $hiddenSeason = Season::factory()->create([
            'catalog_title_id' => $catalogTitle->id,
            'number' => 2,
            'publication_status' => 'hidden',
        ]);
        $foreignSeason = Season::factory()->create([
            'catalog_title_id' => CatalogTitle::factory()->create()->id,
            'number' => 1,
        ]);
        $availableEpisode = Episode::factory()->create([
            'season_id' => $season->id,
            'number' => 1,
            'title' => 'Доступная серия',
        ]);
        $hiddenEpisode = Episode::factory()->create([
            'season_id' => $season->id,
            'number' => 2,
            'title' => 'Скрытая серия',
            'publication_status' => 'hidden',
        ]);
        $missingSourceEpisode = Episode::factory()->create([
            'season_id' => $season->id,
            'number' => 3,
            'title' => 'Серия без источника',
        ]);
        $authenticatedEpisode = Episode::factory()->create([
            'season_id' => $season->id,
            'number' => 4,
            'title' => 'Серия после входа',
            'audience' => 'authenticated',
        ]);
        LicensedMedia::factory()->create([
            'catalog_title_id' => $catalogTitle->id,
            'season_id' => $season->id,
            'episode_id' => $availableEpisode->id,
            'status' => 'published',
            'published_at' => now(),
        ]);
        LicensedMedia::factory()->create([
            'catalog_title_id' => $catalogTitle->id,
            'season_id' => $season->id,
            'episode_id' => $authenticatedEpisode->id,
            'status' => 'published',
            'audience' => 'authenticated',
            'published_at' => now(),
        ]);
        LicensedMedia::factory()->create([
            'catalog_title_id' => $catalogTitle->id,
            'season_id' => $season->id,
            'episode_id' => $missingSourceEpisode->id,
            'path' => '',
            'playback_url' => null,
            'status' => 'published',
            'published_at' => now(),
        ]);
        LicensedMedia::factory()->create([
            'catalog_title_id' => $catalogTitle->id,
            'season_id' => $season->id,
            'episode_id' => $hiddenEpisode->id,
            'status' => 'published',
            'published_at' => now(),
        ]);

        Livewire::test(CatalogTitlePlayer::class, ['catalogTitleId' => $catalogTitle->id])
            ->assertSeeText('1 серия доступна')
            ->assertSeeText('Начать с 1 серии')
            ->assertSeeText('Доступная серия')
            ->assertDontSeeText('Скрытая серия')
            ->assertDontSeeText('Серия без источника')
            ->assertDontSeeText('Серия после входа')
            ->call('selectEpisode', $missingSourceEpisode->id)
            ->assertSet('episode', '')
            ->call('selectSeason', $hiddenSeason->id)
            ->assertSet('season', '')
            ->call('selectSeason', $foreignSeason->id)
            ->assertSet('season', '');

        Livewire::actingAs(User::factory()->create())
            ->test(CatalogTitlePlayer::class, ['catalogTitleId' => $catalogTitle->id])
            ->assertSeeText('2 серии доступны')
            ->assertSeeText('Серия после входа');

        Livewire::withQueryParams([
            'season' => 999999,
            'episode' => $missingSourceEpisode->id,
            'media' => 999999,
        ])->test(CatalogTitlePlayer::class, ['catalogTitleId' => $catalogTitle->id])
            ->assertSet('season', '')
            ->assertSet('episode', '')
            ->assertSet('media', '');
    }

    public function test_title_page_renders_selected_episode_media_state(): void
    {
        $catalogTitle = CatalogTitle::factory()->create([
            'title' => 'Видео сериал',
            'slug' => 'video-serial',
            'poster_url' => 'https://media.example.com/poster.jpg',
        ]);
        $season = Season::factory()->create([
            'catalog_title_id' => $catalogTitle->id,
            'number' => 1,
            'title' => 'Сезон 1',
            'episodes_released' => 2,
            'episodes_total' => 8,
            'translation_name' => 'Дубляж',
            'latest_episode_released_at' => now(),
        ]);
        $episode = Episode::factory()->create([
            'season_id' => $season->id,
            'number' => 2,
            'title' => 'Вторая серия',
        ]);
        $media = LicensedMedia::factory()->create([
            'catalog_title_id' => $catalogTitle->id,
            'season_id' => $season->id,
            'episode_id' => $episode->id,
            'title' => 'Видео 720p',
            'storage_disk' => 'seasonvar_parsed',
            'path' => 'https://data00-cdn.11cdn.org/video.m3u8',
            'playback_url' => 'https://data00-cdn.11cdn.org/video.m3u8',
            'quality' => '720p',
            'translation_name' => 'Дубляж',
            'format' => 'm3u8',
            'status' => 'published',
            'check_status' => 'available',
            'published_at' => now(),
        ]);
        $failedMedia = LicensedMedia::factory()->create([
            'catalog_title_id' => $catalogTitle->id,
            'season_id' => $season->id,
            'episode_id' => $episode->id,
            'title' => 'Сломанное видео',
            'storage_disk' => 'seasonvar_parsed',
            'path' => 'https://data00-cdn.11cdn.org/broken.m3u8',
            'playback_url' => 'https://data00-cdn.11cdn.org/broken.m3u8',
            'quality' => '1080p',
            'format' => 'm3u8',
            'status' => 'published',
            'check_status' => 'unavailable',
            'health_status' => 'unavailable',
            'published_at' => $media->published_at,
        ]);
        $lowerPriorityMedia = LicensedMedia::factory()->create([
            'catalog_title_id' => $catalogTitle->id,
            'season_id' => $season->id,
            'episode_id' => $episode->id,
            'title' => 'Резервное видео',
            'storage_disk' => 'external_playlist',
            'path' => 'https://data00-cdn.11cdn.org/fallback.m3u8',
            'playback_url' => 'https://data00-cdn.11cdn.org/fallback.m3u8',
            'quality' => '720p',
            'translation_name' => 'Дубляж',
            'format' => 'm3u8',
            'status' => 'published',
            'check_status' => 'available',
            'published_at' => $media->published_at,
            'created_at' => now()->addMinute(),
        ]);

        $response = $this->get(route('titles.show', [
            'catalogTitle' => $catalogTitle,
            'episode' => $episode->id,
        ]));

        $response
            ->assertOk()
            ->assertSeeText('Выбрана 2 серия')
            ->assertSeeText('720P / Дубляж / M3U8')
            ->assertSeeText('Быстрый доступ')
            ->assertSeeText('Сейчас открыто')
            ->assertDontSee('https://data00-cdn.11cdn.org/video.m3u8', false)
            ->assertSee('/playback/'.$media->id.'?', false)
            ->assertDontSee('/playback/'.$failedMedia->id.'?', false)
            ->assertDontSee('/playback/'.$lowerPriorityMedia->id.'?', false)
            ->assertSee('expires=', false)
            ->assertSee('signature=', false)
            ->assertSee('wire:ignore', false)
            ->assertSee('data-active-player-session="'.$catalogTitle->id.':'.$episode->id.':'.$media->id.'"', false)
            ->assertSee('data-player-session="'.$catalogTitle->id.':'.$episode->id.':'.$media->id.'"', false)
            ->assertSee('data-player-status', false)
            ->assertSee('data-player-status-icon', false)
            ->assertSee('data-player-status-text', false)
            ->assertSee('data-player-retry', false)
            ->assertSee('data-hls-src="', false)
            ->assertDontSee('type="application/x-mpegURL"', false);

        $this->get(route('titles.show', [
            'catalogTitle' => $catalogTitle,
            'episode' => $episode->id,
            'media' => $lowerPriorityMedia->id,
        ]))
            ->assertOk()
            ->assertSee('/playback/'.$lowerPriorityMedia->id.'?', false)
            ->assertDontSee('https://data00-cdn.11cdn.org/fallback.m3u8', false);
    }

    public function test_continue_watching_uses_one_accessible_recent_action_per_series(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $continuingTitle = CatalogTitle::factory()->create([
            'title' => 'Продолжаемый сериал',
            'slug' => 'prodolzhaemyi-serial',
        ]);
        $season = Season::factory()->create([
            'catalog_title_id' => $continuingTitle->id,
            'number' => 1,
        ]);
        $episodes = collect([1, 2])->map(function (int $number) use ($continuingTitle, $season): Episode {
            $episode = Episode::factory()->create([
                'season_id' => $season->id,
                'number' => $number,
                'sort_order' => $number,
                'title' => 'Продолжаемая серия '.$number,
            ]);
            LicensedMedia::factory()->create([
                'catalog_title_id' => $continuingTitle->id,
                'season_id' => $season->id,
                'episode_id' => $episode->id,
                'status' => 'published',
                'published_at' => now(),
            ]);

            return $episode;
        });
        EpisodeViewProgress::query()->create([
            'user_id' => $user->id,
            'catalog_title_id' => $continuingTitle->id,
            'episode_id' => $episodes[0]->id,
            'position_seconds' => 600,
            'duration_seconds' => 600,
            'progress_percent' => 100,
            'first_started_at' => now()->subHours(2),
            'completed_at' => now()->subHours(2),
            'last_watched_at' => now()->subHours(2),
        ]);
        EpisodeViewProgress::query()->create([
            'user_id' => $user->id,
            'catalog_title_id' => $continuingTitle->id,
            'episode_id' => $episodes[1]->id,
            'position_seconds' => 120,
            'duration_seconds' => 600,
            'progress_percent' => 20,
            'first_started_at' => now()->subHour(),
            'last_watched_at' => now()->subHour(),
        ]);

        $completedTitle = CatalogTitle::factory()->create([
            'title' => 'Полностью просмотренный сериал',
            'slug' => 'polnostiu-prosmotrennyi-serial',
        ]);
        $completedSeason = Season::factory()->create([
            'catalog_title_id' => $completedTitle->id,
            'number' => 1,
        ]);
        $completedEpisode = Episode::factory()->create([
            'season_id' => $completedSeason->id,
            'number' => 1,
            'sort_order' => 1,
        ]);
        $completedMedia = LicensedMedia::factory()->create([
            'catalog_title_id' => $completedTitle->id,
            'season_id' => $completedSeason->id,
            'episode_id' => $completedEpisode->id,
            'status' => 'published',
            'published_at' => now(),
        ]);
        EpisodeViewProgress::query()->create([
            'user_id' => $user->id,
            'catalog_title_id' => $completedTitle->id,
            'episode_id' => $completedEpisode->id,
            'position_seconds' => 600,
            'duration_seconds' => 600,
            'progress_percent' => 100,
            'first_started_at' => now()->subMinutes(30),
            'completed_at' => now()->subMinutes(30),
            'last_watched_at' => now()->subMinutes(30),
        ]);

        $hiddenTitle = CatalogTitle::factory()->create([
            'title' => 'Скрытый просмотр',
            'slug' => 'skrytyi-prosmotr',
            'publication_status' => 'hidden',
        ]);
        $hiddenSeason = Season::factory()->create(['catalog_title_id' => $hiddenTitle->id]);
        $hiddenEpisode = Episode::factory()->create(['season_id' => $hiddenSeason->id]);
        EpisodeViewProgress::query()->create([
            'user_id' => $user->id,
            'catalog_title_id' => $hiddenTitle->id,
            'episode_id' => $hiddenEpisode->id,
            'position_seconds' => 60,
            'duration_seconds' => 600,
            'first_started_at' => now(),
            'last_watched_at' => now(),
        ]);
        $mismatchedTitle = CatalogTitle::factory()->create([
            'title' => 'Сериал с чужим источником',
            'slug' => 'serial-s-chuzhim-istochnikom',
        ]);
        $mismatchedSeason = Season::factory()->create(['catalog_title_id' => $mismatchedTitle->id]);
        $mismatchedEpisode = Episode::factory()->create(['season_id' => $mismatchedSeason->id]);
        LicensedMedia::factory()->create([
            'catalog_title_id' => $continuingTitle->id,
            'season_id' => $mismatchedSeason->id,
            'episode_id' => $mismatchedEpisode->id,
            'status' => 'published',
            'published_at' => now(),
        ]);
        EpisodeViewProgress::query()->create([
            'user_id' => $user->id,
            'catalog_title_id' => $mismatchedTitle->id,
            'episode_id' => $mismatchedEpisode->id,
            'position_seconds' => 60,
            'duration_seconds' => 600,
            'first_started_at' => now(),
            'last_watched_at' => now(),
        ]);
        EpisodeViewProgress::query()->create([
            'user_id' => $otherUser->id,
            'catalog_title_id' => $continuingTitle->id,
            'episode_id' => $episodes[0]->id,
            'position_seconds' => 300,
            'duration_seconds' => 600,
            'first_started_at' => now(),
            'last_watched_at' => now(),
        ]);

        DB::flushQueryLog();
        DB::enableQueryLog();

        $component = Livewire::actingAs($user)
            ->test(ViewingActivity::class)
            ->assertSeeText('Продолжаемый сериал')
            ->assertSeeText('Продолжить с 02:00')
            ->assertSeeHtml('data-viewing-continue-list')
            ->assertSeeHtml('data-ui-poster-card')
            ->assertSeeHtml('data-ui-poster-frame')
            ->assertSeeHtml('wire:key="continue-watching-'.$continuingTitle->id.'"')
            ->assertDontSeeHtml('wire:key="continue-watching-'.$completedTitle->id.'"')
            ->assertDontSeeHtml('wire:key="continue-watching-'.$mismatchedTitle->id.'"')
            ->assertDontSeeText('Скрытый просмотр');

        $this->assertSame(1, substr_count(
            $component->html(),
            'wire:key="continue-watching-'.$continuingTitle->id.'"',
        ));
        $queries = collect(DB::getQueryLog());
        $this->assertLessThanOrEqual(12, $queries->count());
        $continueSql = $queries->pluck('query')->implode("\n");
        $this->assertStringContainsString('ROW_NUMBER() OVER', $continueSql);
        $this->assertStringContainsString('LEAD(', $continueSql);

        $newEpisode = Episode::factory()->create([
            'season_id' => $completedSeason->id,
            'number' => 2,
            'sort_order' => 2,
            'title' => 'Новая опубликованная серия',
        ]);
        $newMedia = LicensedMedia::factory()->create([
            'catalog_title_id' => $completedTitle->id,
            'season_id' => $completedSeason->id,
            'episode_id' => $newEpisode->id,
            'status' => 'published',
            'published_at' => now(),
        ]);

        Livewire::actingAs($user)
            ->test(ViewingActivity::class)
            ->assertSeeText('Полностью просмотренный сериал')
            ->assertSeeHtml('wire:key="continue-watching-'.$completedTitle->id.'"')
            ->assertSeeText('Следующая серия');

        $completedMedia->update([
            'check_status' => 'unavailable',
            'health_status' => 'unavailable',
        ]);

        Livewire::actingAs($user)
            ->test(ViewingActivity::class)
            ->assertSeeHtml('wire:key="continue-watching-'.$completedTitle->id.'"')
            ->assertSeeText('Следующая серия');

        $newMedia->update([
            'check_status' => 'unavailable',
            'health_status' => 'unavailable',
        ]);

        Livewire::actingAs($user)
            ->test(ViewingActivity::class)
            ->assertDontSeeHtml('wire:key="continue-watching-'.$completedTitle->id.'"');
    }

    public function test_viewing_history_is_profile_scoped_paginated_and_requires_actual_playback(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $title = CatalogTitle::factory()->create([
            'title' => 'История владельца',
            'slug' => 'istoriia-vladeltsa',
        ]);
        $season = Season::factory()->create(['catalog_title_id' => $title->id, 'number' => 1]);

        foreach (range(1, 13) as $number) {
            $episode = Episode::factory()->create([
                'season_id' => $season->id,
                'number' => $number,
                'sort_order' => $number,
                'title' => $number === 1 ? 'Самая старая серия истории' : 'Серия истории '.$number,
            ]);
            LicensedMedia::factory()->create([
                'catalog_title_id' => $title->id,
                'season_id' => $season->id,
                'episode_id' => $episode->id,
                'status' => 'published',
                'published_at' => now(),
            ]);
            EpisodeViewProgress::query()->create([
                'user_id' => $user->id,
                'catalog_title_id' => $title->id,
                'episode_id' => $episode->id,
                'position_seconds' => 30,
                'duration_seconds' => 600,
                'first_started_at' => now()->subMinutes(14 - $number),
                'last_watched_at' => now()->subMinutes(14 - $number),
            ]);
        }

        $pageVisitOnlyEpisode = Episode::factory()->create([
            'season_id' => $season->id,
            'number' => 99,
            'title' => 'Только открытая страница',
        ]);
        EpisodeViewProgress::query()->create([
            'user_id' => $user->id,
            'catalog_title_id' => $title->id,
            'episode_id' => $pageVisitOnlyEpisode->id,
            'position_seconds' => 0,
            'duration_seconds' => 0,
            'first_started_at' => null,
            'last_watched_at' => now(),
        ]);

        $otherTitle = CatalogTitle::factory()->create([
            'title' => 'Чужая история',
            'slug' => 'chuzhaia-istoriia',
        ]);
        $otherSeason = Season::factory()->create(['catalog_title_id' => $otherTitle->id]);
        $otherEpisode = Episode::factory()->create(['season_id' => $otherSeason->id]);
        EpisodeViewProgress::query()->create([
            'user_id' => $otherUser->id,
            'catalog_title_id' => $otherTitle->id,
            'episode_id' => $otherEpisode->id,
            'position_seconds' => 30,
            'duration_seconds' => 600,
            'first_started_at' => now(),
            'last_watched_at' => now(),
        ]);

        $this->get(route('viewing-activity'))->assertRedirect(route('login'));
        $this->actingAs($user)
            ->get(route('viewing-activity'))
            ->assertRedirect(route('library.section', 'continue-watching'));
        $this->get(route('library.section', 'history'))
            ->assertOk()
            ->assertSee('data-ui-poster-card', false)
            ->assertSee('data-ui-poster-frame', false)
            ->assertSeeText('История просмотров')
            ->assertDontSeeText('Чужая история')
            ->assertDontSeeText('Только открытая страница');

        Livewire::actingAs($user)
            ->test(ViewingActivity::class)
            ->assertSeeHtml('data-viewing-history-list')
            ->assertSeeText('История владельца')
            ->assertDontSeeText('Самая старая серия истории')
            ->call('setPage', 2, 'historyPage')
            ->assertSeeText('Самая старая серия истории')
            ->assertSet('paginators.historyPage', 2);
    }

    public function test_viewing_history_removal_and_clear_are_authorized_and_synchronize_continue_watching(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $title = CatalogTitle::factory()->create([
            'title' => 'Удаляемая история',
            'slug' => 'udaliaemaia-istoriia',
        ]);
        $season = Season::factory()->create(['catalog_title_id' => $title->id]);
        $episode = Episode::factory()->create(['season_id' => $season->id]);
        LicensedMedia::factory()->create([
            'catalog_title_id' => $title->id,
            'season_id' => $season->id,
            'episode_id' => $episode->id,
            'status' => 'published',
            'published_at' => now(),
        ]);
        $ownProgress = EpisodeViewProgress::query()->create([
            'user_id' => $user->id,
            'catalog_title_id' => $title->id,
            'episode_id' => $episode->id,
            'position_seconds' => 90,
            'duration_seconds' => 600,
            'first_started_at' => now(),
            'last_watched_at' => now(),
        ]);
        $otherProgress = EpisodeViewProgress::query()->create([
            'user_id' => $otherUser->id,
            'catalog_title_id' => $title->id,
            'episode_id' => $episode->id,
            'position_seconds' => 45,
            'duration_seconds' => 600,
            'first_started_at' => now(),
            'last_watched_at' => now(),
        ]);

        Livewire::actingAs($user)
            ->test(ViewingActivity::class)
            ->assertSeeText('Удаляемая история')
            ->assertSeeHtml('wire:confirm="Удалить этот просмотр из истории?"')
            ->call('removeHistoryItem', $otherProgress->id)
            ->assertForbidden();

        Livewire::actingAs($user)
            ->test(ViewingActivity::class)
            ->call('removeHistoryItem', $ownProgress->id)
            ->assertSeeText('История просмотров пока пуста')
            ->assertDontSeeText('Удаляемая история');

        $this->assertFalse(EpisodeViewProgress::query()->whereKey($ownProgress->id)->exists());
        $this->assertTrue(EpisodeViewProgress::query()->whereKey($otherProgress->id)->exists());

        $secondOwnProgress = EpisodeViewProgress::query()->create([
            'user_id' => $user->id,
            'catalog_title_id' => $title->id,
            'episode_id' => $episode->id,
            'position_seconds' => 120,
            'duration_seconds' => 600,
            'first_started_at' => now(),
            'last_watched_at' => now(),
        ]);

        Livewire::actingAs($user)
            ->test(ViewingActivity::class)
            ->assertSeeHtml('wire:confirm.prompt="Очистить всю историю просмотров?')
            ->call('clearHistory')
            ->assertSeeText('История просмотров пока пуста');

        $this->assertFalse(EpisodeViewProgress::query()->whereKey($secondOwnProgress->id)->exists());
        $this->assertTrue(EpisodeViewProgress::query()->whereKey($otherProgress->id)->exists());
    }
}
