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

    public function test_player_assets_define_one_cleanup_safe_livewire_session_lifecycle(): void
    {
        $app = File::get(resource_path('js/app.js'));
        $player = File::get(resource_path('js/player.js'));

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
        $this->assertStringContainsString("'Ссылка на просмотр устарела.'", $player);
        $this->assertStringNotContainsString('._catalogHls', $app.$player);
        $this->assertStringNotContainsString('._catalogPlyr', $app.$player);
        $this->assertStringContainsString("'livewire:navigating'", $app);
        $this->assertStringContainsString("'livewire:navigated'", $app);
        $this->assertStringContainsString("'pagehide'", $app);
        $this->assertStringContainsString("'pageshow'", $app);
    }
}
