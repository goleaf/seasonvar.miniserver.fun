<?php

declare(strict_types=1);

namespace Tests\Unit;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

final class PlaybackQualityPlayerContractTest extends TestCase
{
    public function test_existing_player_lifecycle_owns_safe_quality_telemetry_and_report_ux(): void
    {
        $player = File::get(resource_path('js/player.js'));
        $view = File::get(resource_path('views/livewire/catalog-title-player.blade.php'));

        $this->assertSame(1, substr_count($player, 'new this.Plyr'));
        $this->assertStringContainsString("void this.sendQualitySample('ready')", $player);
        $this->assertStringContainsString("void this.sendQualitySample('heartbeat')", $player);
        $this->assertStringContainsString("void this.sendQualitySample('error'", $player);
        $this->assertStringContainsString("void this.sendQualitySample('fallback')", $player);
        $this->assertStringContainsString("void this.sendQualitySample('ended')", $player);
        $this->assertSame(1, substr_count($player, 'await this.networkTest()'));
        $this->assertStringContainsString("credentials: 'same-origin'", $player);
        $this->assertStringContainsString("cache: 'no-store'", $player);
        $this->assertStringNotContainsString('source_url:', $player);
        $this->assertStringNotContainsString('user_agent:', $player);
        $this->assertStringNotContainsString('ip_address:', $player);
        $this->assertStringNotContainsString('provider_code:', $player);
        $this->assertStringContainsString('data-playback-quality-context=', $view);
        $this->assertStringContainsString('data-playback-quality-url=', $view);
        $this->assertStringContainsString('data-playback-network-test-url=', $view);
        $this->assertStringContainsString('data-player-quality-report', $view);
        $this->assertStringContainsString("__('issues.video_not_working')", $view);
        $this->assertSame('Основной источник не ответил.', trans('catalog.player.runtime.source_failed', locale: 'ru'));
        $this->assertSame('Открываем резервный источник…', trans('catalog.player.runtime.source_fallback', locale: 'ru'));
    }
}
