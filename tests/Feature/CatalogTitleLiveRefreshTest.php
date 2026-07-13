<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\RefreshSeasonvarCatalogTitle;
use App\Livewire\CatalogTitleDetail;
use App\Livewire\CatalogTitlePlayer;
use App\Models\CatalogTitle;
use App\Models\Episode;
use App\Models\LicensedMedia;
use App\Models\Season;
use App\Services\Seasonvar\CatalogTitleRefreshStateStore;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

class CatalogTitleLiveRefreshTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'cache-architecture.stores.domain' => 'array',
            'seasonvar.queue.lock_store' => 'array',
            'seasonvar.title_refresh.fresh_minutes' => 15,
            'seasonvar.title_refresh.state_ttl_seconds' => 86_400,
            'seasonvar.title_refresh.active_seconds' => 21_900,
            'seasonvar.title_refresh.dispatch_lock_seconds' => 10,
        ]);

        Cache::store('array')->flush();
        Queue::fake();
    }

    public function test_the_browser_initializes_refresh_after_ssr_and_polls_the_complete_livewire_shell_every_three_seconds(): void
    {
        $title = $this->refreshableTitle(['title' => 'Старое название']);

        $this->get(route('titles.show', $title))
            ->assertOk()
            ->assertSeeLivewire('catalog-title-detail')
            ->assertSeeLivewire('catalog-title-player')
            ->assertSee('Старое название')
            ->assertSee('wire:init="startRefresh"', false)
            ->assertDontSee('wire:poll.3s.visible="refreshCatalog"', false)
            ->assertDontSee('Обновляем данные');

        Queue::assertNothingPushed();

        Livewire::test(CatalogTitleDetail::class, ['catalogTitleId' => $title->id])
            ->call('startRefresh')
            ->assertSee('wire:poll.3s.visible="refreshCatalog"', false)
            ->assertSee('Обновляем данные');

        Queue::assertPushed(RefreshSeasonvarCatalogTitle::class, 1);
    }

    public function test_livewire_poll_reloads_all_title_data_notifies_the_player_and_stops_after_completion(): void
    {
        $title = $this->refreshableTitle(['title' => 'Старое название']);
        $component = Livewire::test(CatalogTitleDetail::class, ['catalogTitleId' => $title->id]);

        $title->update(['title' => 'Новое название', 'description' => 'Новое описание']);

        $component
            ->call('refreshCatalog')
            ->assertSee('Новое название')
            ->assertSee('Новое описание')
            ->assertDispatched('catalog-title-refreshed', catalogTitleId: $title->id);

        app(CatalogTitleRefreshStateStore::class)->completed($title->id, 73);

        $component
            ->call('refreshCatalog')
            ->assertSee('Данные обновлены')
            ->assertDontSee('wire:poll.3s.visible="refreshCatalog"', false);
    }

    public function test_failed_refresh_keeps_current_catalog_data_and_exposes_no_source_url_or_error(): void
    {
        $title = $this->refreshableTitle(['title' => 'Последние сохраненные данные']);
        $component = Livewire::test(CatalogTitleDetail::class, ['catalogTitleId' => $title->id]);

        app(CatalogTitleRefreshStateStore::class)->failed($title->id);

        $component
            ->call('refreshCatalog')
            ->assertSee('Последние сохраненные данные')
            ->assertSee('Не удалось обновить')
            ->assertDontSee((string) $title->source_url)
            ->assertDontSee('wire:poll.3s.visible="refreshCatalog"', false);
    }

    public function test_livewire_title_detail_returns_not_found_for_an_invisible_title(): void
    {
        $title = $this->refreshableTitle([
            'is_published' => false,
            'publication_status' => 'draft',
        ]);

        try {
            Livewire::test(CatalogTitleDetail::class, ['catalogTitleId' => $title->id]);
            $this->fail('Невидимый тайтл не должен монтироваться в Livewire.');
        } catch (ModelNotFoundException) {
            $this->assertTrue(true);
        }

        Queue::assertNothingPushed();
    }

    public function test_player_refresh_event_reloads_new_releases_and_preserves_a_valid_selection(): void
    {
        $title = $this->refreshableTitle();
        $firstSeason = Season::factory()->create([
            'catalog_title_id' => $title->id,
            'number' => 1,
            'title' => 'Первый сезон',
        ]);
        $firstEpisode = $this->publishedEpisode($title, $firstSeason, 'Первый выпуск', 1);
        $component = Livewire::test(CatalogTitlePlayer::class, ['catalogTitleId' => $title->id])
            ->call('selectEpisode', $firstEpisode->id)
            ->assertSet('episode', (string) $firstEpisode->id);

        $secondSeason = Season::factory()->create([
            'catalog_title_id' => $title->id,
            'number' => 2,
            'title' => 'Новый второй сезон',
        ]);
        $this->publishedEpisode($title, $secondSeason, 'Новый второй выпуск', 1);

        $component
            ->dispatch('catalog-title-refreshed', catalogTitleId: $title->id + 999)
            ->assertSet('episode', (string) $firstEpisode->id)
            ->dispatch('catalog-title-refreshed', catalogTitleId: $title->id)
            ->assertSet('episode', (string) $firstEpisode->id)
            ->assertSeeHtml('wire:key="season-option-'.$secondSeason->id.'"');
    }

    private function refreshableTitle(array $attributes = []): CatalogTitle
    {
        $url = 'https://seasonvar.ru/serial-42-Test-1-season.html';

        return CatalogTitle::factory()->create([
            'source_url' => $url,
            'source_url_hash' => hash('sha256', $url),
            ...$attributes,
        ]);
    }

    private function publishedEpisode(CatalogTitle $title, Season $season, string $name, int $number): Episode
    {
        $episode = Episode::factory()->create([
            'season_id' => $season->id,
            'number' => $number,
            'title' => $name,
        ]);
        LicensedMedia::factory()->create([
            'catalog_title_id' => $title->id,
            'season_id' => $season->id,
            'episode_id' => $episode->id,
            'status' => 'published',
            'published_at' => now(),
        ]);

        return $episode;
    }
}
