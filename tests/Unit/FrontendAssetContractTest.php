<?php

namespace Tests\Unit;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

class FrontendAssetContractTest extends TestCase
{
    public function test_frontend_assets_are_local_and_cyrillic_safe(): void
    {
        $app = File::get(resource_path('js/app.js'));
        $player = File::get(resource_path('js/player.js'));
        $styles = File::get(resource_path('css/app.css'));
        $vite = File::get(base_path('vite.config.js'));
        $layout = File::get(resource_path('views/layouts/app.blade.php'));
        $npmConfig = File::get(base_path('.npmrc'));
        $npmLock = File::get(base_path('package-lock.json'));

        $this->assertStringNotContainsString('all.min.css', $app);
        $this->assertStringContainsString('fontawesome.min.css', $app);
        $this->assertStringContainsString('solid.min.css', $app);
        $this->assertStringContainsString('regular.min.css', $app);
        $this->assertStringContainsString('../images/plyr.svg?url', $player);
        $this->assertStringContainsString('iconUrl: plyrIconUrl', $player);
        $this->assertStringNotContainsString('cdn.plyr.io', $player);
        $this->assertStringNotContainsString('Instrument Sans', $styles);
        $this->assertStringNotContainsString("bunny('Instrument Sans'", $vite);
        $this->assertStringNotContainsString("Vite::fonts('instrument-sans')", $layout);
        $this->assertStringContainsString('registry=https://registry.npmjs.org/', $npmConfig);
        $this->assertStringNotContainsString('registry.npmmirror.com', $npmLock);
        $this->assertStringContainsString('textarea,', $styles);
        $this->assertStringContainsString('outline: 2px solid var(--color-emerald-700)', $styles);
        $this->assertStringContainsString('[data-focus-frame]:focus-within', $styles);
        $this->assertStringContainsString('a[href],', $styles);
        $this->assertStringContainsString('cursor: pointer;', $styles);
        $this->assertStringContainsString("[aria-disabled='true']", $styles);
        $this->assertStringContainsString('cursor: not-allowed;', $styles);
        $this->assertStringContainsString('[data-loading]', $styles);
        $this->assertStringContainsString('cursor: wait;', $styles);
        $this->assertStringContainsString('.ui-icon {', $styles);
        $this->assertStringContainsString('inline-size: 1.25em', $styles);
        $this->assertStringContainsString('block-size: 1em', $styles);
        $this->assertStringContainsString('flex: 0 0 auto', $styles);
        $this->assertStringContainsString('.ui-icon--start {', $styles);
        $this->assertStringContainsString('margin-block-start: 0.125em', $styles);
    }

    public function test_livewire_pagination_uses_scoped_post_morph_scroll_targets(): void
    {
        $app = File::get(resource_path('js/app.js'));
        $pagination = File::get(resource_path('views/vendor/livewire/tailwind.blade.php'));
        $views = collect([
            resource_path('views/catalog/titles.blade.php'),
            resource_path('views/livewire/catalog-directory-browser.blade.php'),
            resource_path('views/livewire/viewing-activity.blade.php'),
            resource_path('views/livewire/catalog-administration-manager.blade.php'),
        ])->map(fn (string $path): string => File::get($path))->implode("\n");

        $this->assertStringContainsString('data-pagination-scroll-to', $pagination);
        $this->assertStringContainsString('data-pagination-region-name', $pagination);
        $this->assertStringContainsString('wire:click.prevent="gotoPage', $pagination);
        $this->assertStringContainsString('href="{{ $url }}"', $pagination);
        $this->assertStringContainsString('pendingPaginationScrollTo', $app);
        $this->assertStringContainsString("window.Livewire.hook('morphed'", $app);
        $this->assertStringContainsString("window.Livewire.hook('island.morphed'", $app);
        $this->assertStringContainsString('smoothAnchorScroll', $app);
        $this->assertStringContainsString('resolvePaginationScrollTarget', $app);
        $this->assertStringContainsString('name="catalog-results"', $views);
        $this->assertStringContainsString('name="directory-results"', $views);
        $this->assertStringContainsString('name="viewing-history-results"', $views);
        $this->assertStringContainsString('name="admin-catalog-results"', $views);
    }

    public function test_every_livewire_paginator_declares_a_unique_island_region_contract(): void
    {
        $templates = collect(File::allFiles(resource_path('views')))
            ->filter(fn (\SplFileInfo $file): bool => str_ends_with($file->getFilename(), '.blade.php'))
            ->mapWithKeys(fn (\SplFileInfo $file): array => [$file->getPathname() => File::get($file->getPathname())])
            ->filter(fn (string $contents): bool => str_contains($contents, '->links('));

        $this->assertCount(40, $templates);
        $this->assertSame(54, $templates->sum(fn (string $contents): int => substr_count($contents, '->links(')));

        foreach ($templates as $path => $contents) {
            preg_match_all("/->links\\(data: \\['region' => '([^']+)'/", $contents, $matches);
            $links = substr_count($contents, '->links(');

            $this->assertCount($links, $matches[1], $path);
            $this->assertSame($matches[1], array_values(array_unique($matches[1])), $path);
            $this->assertSame($links, substr_count($contents, '<x-ui.pagination-region'), $path);
            $this->assertStringContainsString('@island(', $contents, $path);
            $this->assertStringContainsString('with: $this->', $contents, $path);
        }
    }

    public function test_pagination_region_runtime_uses_livewire_loading_and_dynamic_header_geometry(): void
    {
        $regionPath = resource_path('views/components/ui/pagination-region.blade.php');
        $islandConcernPath = app_path('Livewire/Concerns/InteractsWithPaginationIslands.php');

        $this->assertFileExists($regionPath);
        $this->assertFileExists($islandConcernPath);

        $region = File::get($regionPath);
        $islandConcern = File::get($islandConcernPath);
        $pagination = File::get(resource_path('views/vendor/livewire/tailwind.blade.php'));
        $app = File::get(resource_path('js/app.js'));
        $styles = File::get(resource_path('css/app.css'));
        $russian = File::get(lang_path('ru/pagination.php'));
        $english = File::get(lang_path('en/pagination.php'));

        foreach (['data-pagination-region', 'data-pagination-scroll-target', 'data-pagination-loading', 'data-pagination-content', 'aria-busy="false"', "__('pagination.loading')"] as $marker) {
            $this->assertStringContainsString($marker, $region);
        }

        $this->assertStringNotContainsString('@php', $pagination);
        $this->assertStringNotContainsString('scrollIntoView', $pagination);
        $this->assertStringContainsString('data-pagination-page-name', $pagination);
        $this->assertStringContainsString('data-loading:pointer-events-none', $pagination);
        $this->assertStringContainsString('--pagination-scroll-gap: 1rem', $styles);
        $this->assertStringContainsString("[data-pagination-region][aria-busy='true'] [data-pagination-loading]", $styles);
        $this->assertStringContainsString(':has([data-pagination-control][data-loading])', $styles);
        $this->assertStringContainsString('paginationHeaderOffset', $app);
        $this->assertStringContainsString("position === 'sticky' || position === 'fixed'", $app);
        $this->assertStringContainsString('easeInOutCubic', $app);
        $this->assertStringContainsString('cancelActiveScroll', $app);
        $this->assertStringContainsString('520', $app);
        $this->assertStringContainsString('820', $app);
        $this->assertStringContainsString('window.Livewire.interceptMessage', $app);
        $this->assertStringContainsString('clearPaginationScrollForComponent', $app);
        $this->assertStringContainsString('message.component?.id', $app);
        $this->assertStringNotContainsString('onFinish(clearPaginationScroll)', $app);
        $this->assertStringContainsString("window.Livewire.hook('island.morphed'", $app);
        $this->assertStringContainsString('#[Computed]', $islandConcern);
        $this->assertStringContainsString('paginationIslandPage', $islandConcern);
        $this->assertStringContainsString('renderingInteractsWithPaginationIslands', $islandConcern);
        $this->assertStringContainsString("app()->call([\$this, 'render'])", $islandConcern);
        $this->assertStringContainsString("'loading' => 'Загружаем страницу'", $russian);
        $this->assertStringContainsString("'loading' => 'Loading page'", $english);
    }

    public function test_title_detail_uses_the_complete_livewire_refresh_shell(): void
    {
        $component = File::get(app_path('Livewire/CatalogTitleDetail.php'));
        $detail = File::get(resource_path('views/livewire/catalog-title-detail.blade.php'));

        $this->assertStringContainsString('wire:init="startRefresh"', $detail);
        $this->assertStringContainsString('wire:poll.3s.visible="refreshCatalog"', $detail);
        $this->assertStringContainsString('data-livewire-catalog-title-detail', $detail);
        $this->assertStringContainsString('data-title-refresh-status', $detail);
        $this->assertStringContainsString("->extends('layouts.app'", $component);
        $this->assertFileDoesNotExist(resource_path('views/catalog/show.blade.php'));
    }

    public function test_player_assets_define_one_cleanup_safe_livewire_session_lifecycle(): void
    {
        $app = File::get(resource_path('js/app.js'));
        $player = File::get(resource_path('js/player.js'));
        $playerView = File::get(resource_path('views/livewire/catalog-title-player.blade.php'));

        $this->assertStringContainsString('class CatalogPlayerSession', $player);
        $this->assertStringContainsString('const playerSessions = new WeakMap()', $player);
        $this->assertStringContainsString('new AbortController()', $player);
        $this->assertStringContainsString('PROGRESS_HEARTBEAT_MS = 30_000', $player);
        $this->assertStringContainsString('STABLE_SEEK_DELAY_MS = 750', $player);
        $this->assertStringContainsString('this.progressSequence = 0', $player);
        $this->assertStringContainsString('eventSequence: ++this.progressSequence', $player);
        $this->assertStringContainsString('if (!completed && this.hasDispatchedProgress && progressDelta === 0)', $player);
        $this->assertStringContainsString('data-progress-session', File::get(resource_path('views/livewire/catalog-title-player.blade.php')));
        $this->assertStringContainsString("addEventListener('play'", $player);
        $this->assertStringContainsString("addEventListener('pause'", $player);
        $this->assertStringContainsString("addEventListener('seeking'", $player);
        $this->assertStringContainsString("addEventListener('seeked'", $player);
        $this->assertStringContainsString("addEventListener('timeupdate'", $player);
        $this->assertStringContainsString("addEventListener('ended'", $player);
        $this->assertStringContainsString("addEventListener('error'", $player);
        $this->assertStringContainsString("addEventListener('visibilitychange'", $player);
        $this->assertStringContainsString('setInterval(', $player);
        $this->assertStringContainsString('destroyCatalogPlayersWithin', $player);
        $this->assertStringContainsString('flushCatalogPlayersWithin', $player);
        $this->assertStringContainsString('this.plyr.destroy(function ()', $player);
        $this->assertStringContainsString('clearPlayerMarkers(this)', $player);
        $this->assertSame(0, preg_match('/[А-Яа-яЁё]/u', $player));
        $this->assertStringContainsString('const playerCopyFor = (video)', $player);
        $this->assertStringContainsString('i18n: this.copy.controls', $player);
        $this->assertStringContainsString("blankVideo: 'data:video/mp4;base64,'", $player);
        $this->assertStringContainsString('initializeCaptionTracks()', $player);
        $this->assertStringContainsString('clearRecoveryTimer()', $player);
        $this->assertGreaterThanOrEqual(5, substr_count($player, 'clearRecoveryTimer()'));
        $this->assertStringContainsString("querySelectorAll('track[kind=\"subtitles\"], track[kind=\"captions\"]')", $player);
        $this->assertStringContainsString('manifestLoadPolicy:', $player);
        $this->assertStringContainsString('playlistLoadPolicy:', $player);
        $this->assertStringContainsString('fragLoadPolicy:', $player);
        $this->assertStringContainsString('replaceHls(hlsSource = this.video.dataset.hlsSrc)', $player);
        $this->assertStringContainsString('this.video.src = hlsSource', $player);
        $this->assertStringContainsString("@if (\$showView->selectedMediaFormat !== 'm3u8')", $playerView);
        $this->assertStringNotContainsString("'Ссылка на просмотр устарела.'", $player);
        $this->assertStringNotContainsString("'The playback link has expired.'", $player);
        $this->assertStringNotContainsString('._catalogHls', $app.$player);
        $this->assertStringNotContainsString('._catalogPlyr', $app.$player);
        $this->assertStringContainsString("'livewire:navigating'", $app);
        $this->assertStringContainsString("'livewire:navigated'", $app);
        $this->assertStringContainsString("'pagehide'", $app);
        $this->assertStringContainsString("'pageshow'", $app);
    }
}
