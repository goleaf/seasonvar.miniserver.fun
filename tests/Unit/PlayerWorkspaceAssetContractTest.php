<?php

declare(strict_types=1);

namespace Tests\Unit;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

final class PlayerWorkspaceAssetContractTest extends TestCase
{
    public function test_theatre_mode_has_scoped_cleanup_focus_and_escape_guards(): void
    {
        $navigation = File::get(resource_path('js/player-navigation.js'));
        $styles = File::get(resource_path('css/app.css'));

        self::assertStringContainsString('player-theatre-active', $navigation);
        self::assertStringContainsString('data-player-theatre-toggle', $navigation);
        self::assertStringContainsString("event.key !== 'Escape'", $navigation);
        self::assertStringContainsString('document.fullscreenElement', $navigation);
        self::assertStringContainsString("root.querySelector('dialog[open]')", $navigation);
        self::assertStringContainsString('requestAnimationFrame', $navigation);
        self::assertStringContainsString('cancelAnimationFrame', $navigation);
        self::assertStringContainsString('window.scrollTo', $navigation);
        self::assertStringContainsString("behavior: 'auto'", $navigation);
        self::assertStringContainsString('preventScroll: true', $navigation);
        self::assertStringContainsString('theatreReturnPosition', $navigation);
        self::assertStringContainsString('data-player-theatre-icon', $navigation);
        self::assertStringContainsString("'fa-compress'", $navigation);
        self::assertStringContainsString('cleanupTheatre', $navigation);
        self::assertStringContainsString("closest('[data-player-theatre-toggle]')", $navigation);
        self::assertStringContainsString('syncTheatreUi', $navigation);
        self::assertStringContainsString('[data-player-context-control][open]', $navigation);
        self::assertStringNotContainsString('localStorage', $navigation);
        self::assertStringNotContainsString('sessionStorage', $navigation);
        self::assertStringContainsString('body.player-theatre-active', $styles);
        self::assertStringContainsString('[data-player-workspace-region]', $styles);
        self::assertStringContainsString('[data-site-header]', $styles);
        self::assertStringContainsString('[data-mobile-bottom-navigation]', $styles);
        self::assertStringContainsString('[data-site-footer]', $styles);
        self::assertStringContainsString('[data-player-seasons-panel]', $styles);
        self::assertStringContainsString('[data-player-episode-option]', $styles);
    }

    public function test_runtime_mirrors_states_and_recovery_opens_existing_source_menu(): void
    {
        $runtime = File::get(resource_path('js/player.js'));
        $menu = File::get(resource_path('js/player-menu.js'));

        self::assertStringContainsString('root.dataset.playerRuntimeState', $runtime);
        self::assertStringContainsString('data-player-choose-source', $runtime);
        self::assertStringContainsString("this.menu?.open('translations')", $runtime);
        self::assertStringContainsString('data-player-context-season', $runtime);
        self::assertStringContainsString('data-player-context-quality', $runtime);
        self::assertStringContainsString('data-player-context-subtitles', $runtime);
        self::assertStringContainsString('syncWorkspaceOptions', $runtime);
        self::assertStringContainsString('syncWorkspaceIssueLinks', $runtime);
        self::assertStringContainsString("searchParams.delete('context')", $runtime);
        self::assertStringContainsString('playerWorkspaceUrl', $runtime);
        self::assertStringContainsString('open(initialLevel', $menu);
        self::assertStringContainsString("initialLevel === 'translations'", $menu);
    }
}
