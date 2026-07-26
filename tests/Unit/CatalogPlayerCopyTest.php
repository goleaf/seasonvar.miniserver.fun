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
                'offline', 'stalled', 'sourceFailed', 'sourceFallback', 'sourceChanged',
                'authorizationRefreshed', 'fallbackUnavailable', 'finalEpisode',
                'restartFailed', 'loadingTransition',
                'transitionUnavailable', 'transitionLimited',
                'preferredTranslationUnavailable', 'playRequired',
            ], array_keys($payloads[$locale]['runtime']));
            $this->assertSame([
                'restart', 'rewind', 'play', 'pause', 'fastForward', 'seek',
                'seekLabel', 'played', 'buffered', 'currentTime', 'duration', 'volume',
                'mute', 'unmute', 'enableCaptions', 'disableCaptions',
                'download', 'enterFullscreen', 'exitFullscreen', 'frameTitle',
                'captions', 'settings', 'pip', 'menuBack', 'speed', 'normal',
                'quality', 'loop', 'start', 'end', 'all', 'reset', 'disabled',
                'enabled', 'advertisement',
            ], array_keys($payloads[$locale]['controls']));
            $this->assertSame([
                'open', 'close', 'title', 'seasons', 'episodes', 'translations',
                'back', 'previousPage', 'nextPage', 'page', 'seasonEmpty',
                'loading', 'retry',
            ], array_keys($payloads[$locale]['menu']));
            $this->assertNotContains('', Arr::flatten($payloads[$locale]));
        }

        $this->assertSame(array_keys(Arr::dot($payloads['ru'])), array_keys(Arr::dot($payloads['en'])));
        $this->assertNotSame($payloads['ru']['runtime']['expired'], $payloads['en']['runtime']['expired']);
        $this->assertSame('Сезоны', $payloads['ru']['menu']['seasons']);
        $this->assertSame('Серии', $payloads['ru']['menu']['episodes']);
        $this->assertSame('Переводы', $payloads['ru']['menu']['translations']);
        $this->assertSame('Назад', $payloads['ru']['menu']['back']);
        $this->assertSame('Предыдущая страница', $payloads['ru']['menu']['previousPage']);
        $this->assertSame('Следующая страница', $payloads['ru']['menu']['nextPage']);
        $this->assertSame('Seasons', $payloads['en']['menu']['seasons']);
        $this->assertSame('Episodes', $payloads['en']['menu']['episodes']);
        $this->assertSame('Translations', $payloads['en']['menu']['translations']);

        $placeholderPattern = '/:[A-Za-z_][A-Za-z0-9_]*/';

        foreach (array_keys(Arr::dot($payloads['ru'])) as $key) {
            preg_match_all($placeholderPattern, (string) data_get($payloads['ru'], $key), $ruMatches);
            preg_match_all($placeholderPattern, (string) data_get($payloads['en'], $key), $enMatches);
            self::assertSame($ruMatches[0], $enMatches[0], $key);
        }
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
