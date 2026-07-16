<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\View\ViewData\CatalogPlayerCopy;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

final class CatalogPlayerCopyTest extends TestCase
{
    public function test_player_copy_has_identical_complete_non_empty_ru_and_en_payloads(): void
    {
        $payloads = [];

        foreach (['ru', 'en'] as $locale) {
            app()->setLocale($locale);
            $payloads[$locale] = app(CatalogPlayerCopy::class)->current();

            $this->assertSame([
                'preparing', 'loading', 'ready', 'playing', 'paused', 'seeking',
                'buffering', 'retryingNetwork', 'retryingMedia', 'expired',
                'playbackError', 'fatal', 'ended', 'captionsUnavailable',
            ], array_keys($payloads[$locale]['runtime']));
            $this->assertSame([
                'restart', 'rewind', 'play', 'pause', 'fastForward', 'seek',
                'played', 'buffered', 'currentTime', 'duration', 'volume',
                'mute', 'unmute', 'enableCaptions', 'disableCaptions',
                'enterFullscreen', 'exitFullscreen', 'settings', 'pip',
            ], array_keys($payloads[$locale]['controls']));
            $this->assertNotContains('', Arr::flatten($payloads[$locale]));
        }

        $this->assertSame(array_keys(Arr::dot($payloads['ru'])), array_keys(Arr::dot($payloads['en'])));
        $this->assertNotSame($payloads['ru']['runtime']['expired'], $payloads['en']['runtime']['expired']);
    }

    public function test_player_blade_uses_escaped_copy_and_a_separate_caption_status(): void
    {
        $view = File::get(resource_path('views/livewire/catalog-title-player.blade.php'));

        $this->assertStringContainsString('data-player-copy=', $view);
        $this->assertStringContainsString('Js::encode($playerCopy)', $view);
        $this->assertStringContainsString('data-player-caption-status', $view);
        $this->assertStringContainsString('aria-live="polite"', $view);
    }
}
