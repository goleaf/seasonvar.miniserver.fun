<?php

declare(strict_types=1);

namespace Tests\Unit;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

final class LivewireWireIgnoreContractTest extends TestCase
{
    public function test_only_the_keyed_player_shell_is_ignored(): void
    {
        $markup = collect(File::allFiles(resource_path('views')))
            ->map(fn ($file): string => File::get($file->getPathname()))
            ->implode("\n");
        $player = File::get(resource_path('views/livewire/catalog-title-player.blade.php'));

        $this->assertSame(1, substr_count($markup, 'wire:ignore'));
        $this->assertSame(1, substr_count($player, '<video'));
        $this->assertStringNotContainsString('wire:ignore.self', $markup);
        $this->assertStringContainsString('data-player-menu-bootstrap=', $player);
        $this->assertMatchesRegularExpression(
            '/wire:key="catalog-player-media-shell-\{\{ \$selectedMedia->id \}\}-\{\{ \$authorizationVersion \}\}"\s+wire:ignore\s+data-player-shell/s',
            $player,
        );
    }

    public function test_livewire_controls_stay_outside_the_library_owned_shell(): void
    {
        $player = File::get(resource_path('views/livewire/catalog-title-player.blade.php'));
        $runtime = File::get(resource_path('js/player.js'));
        $ignore = strpos($player, 'wire:ignore');

        $this->assertIsInt($ignore);
        $this->assertLessThan($ignore, strpos($player, 'wire:target="selectMedia"'));
        $this->assertGreaterThan($ignore, strpos($player, 'data-player-restart-episode'));
        $this->assertStringContainsString('wire:click.prevent="selectEpisode({{ $episodeOption->id }})"', $player);
        $this->assertStringContainsString('wire:click.prevent="selectMedia({{ $option[\'mediaId\'] }})"', $player);
        $this->assertStringContainsString('data-player-transition-episode="{{ $episodeOption->id }}"', $player);
        $this->assertStringContainsString('data-player-transition-media="{{ $option[\'mediaId\'] }}"', $player);
        $this->assertStringContainsString('data-catalog-history', $player);
        $this->assertStringContainsString('href="{{ route(\'titles.show\', $showView->episodeQuery($episodeOption)).\'#player\' }}"', $player);
        $this->assertStringContainsString('new this.Plyr(this.video', $runtime);
        $this->assertStringContainsString('new this.Hls(', $runtime);
        $this->assertStringContainsString('this.plyr.destroy(function ()', $runtime);
        $this->assertStringContainsString('this.hls?.destroy()', $runtime);
    }

    public function test_player_switches_sources_in_place_without_an_autoplay_countdown(): void
    {
        $player = File::get(resource_path('views/livewire/catalog-title-player.blade.php'));
        $runtime = File::get(resource_path('js/player.js'));

        $this->assertStringContainsString('async applyTransition(', $runtime);
        $this->assertStringContainsString('prefetchNextTransition()', $runtime);
        $this->assertStringNotContainsString('data-player-autoplay-countdown', $player);
        $this->assertStringNotContainsString('data-player-countdown-seconds', $player);
        $this->assertStringNotContainsString('startAutoplayCountdown', $runtime);
        $this->assertStringNotContainsString('countdownRemaining', $runtime);
    }
}
