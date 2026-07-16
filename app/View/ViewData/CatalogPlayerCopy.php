<?php

declare(strict_types=1);

namespace App\View\ViewData;

use Illuminate\Contracts\Translation\Translator;

final readonly class CatalogPlayerCopy
{
    public function __construct(private Translator $translator) {}

    /**
     * @return array{runtime: array<string, string>, controls: array<string, string>}
     */
    public function current(): array
    {
        return [
            'runtime' => [
                'preparing' => $this->text('runtime.preparing'),
                'loading' => $this->text('runtime.loading'),
                'ready' => $this->text('runtime.ready'),
                'playing' => $this->text('runtime.playing'),
                'paused' => $this->text('runtime.paused'),
                'seeking' => $this->text('runtime.seeking'),
                'buffering' => $this->text('runtime.buffering'),
                'retryingNetwork' => $this->text('runtime.retrying_network'),
                'retryingMedia' => $this->text('runtime.retrying_media'),
                'expired' => $this->text('runtime.expired'),
                'playbackError' => $this->text('runtime.playback_error'),
                'fatal' => $this->text('runtime.fatal'),
                'ended' => $this->text('runtime.ended'),
                'captionsUnavailable' => $this->text('runtime.captions_unavailable'),
            ],
            'controls' => [
                'restart' => $this->text('controls.restart'),
                'rewind' => $this->text('controls.rewind'),
                'play' => $this->text('controls.play'),
                'pause' => $this->text('controls.pause'),
                'fastForward' => $this->text('controls.fast_forward'),
                'seek' => $this->text('controls.seek'),
                'seekLabel' => $this->text('controls.seek_label'),
                'played' => $this->text('controls.played'),
                'buffered' => $this->text('controls.buffered'),
                'currentTime' => $this->text('controls.current_time'),
                'duration' => $this->text('controls.duration'),
                'volume' => $this->text('controls.volume'),
                'mute' => $this->text('controls.mute'),
                'unmute' => $this->text('controls.unmute'),
                'enableCaptions' => $this->text('controls.enable_captions'),
                'disableCaptions' => $this->text('controls.disable_captions'),
                'download' => $this->text('controls.download'),
                'enterFullscreen' => $this->text('controls.enter_fullscreen'),
                'exitFullscreen' => $this->text('controls.exit_fullscreen'),
                'frameTitle' => $this->text('controls.frame_title'),
                'captions' => $this->text('controls.captions'),
                'settings' => $this->text('controls.settings'),
                'pip' => $this->text('controls.pip'),
                'menuBack' => $this->text('controls.menu_back'),
                'speed' => $this->text('controls.speed'),
                'normal' => $this->text('controls.normal'),
                'quality' => $this->text('controls.quality'),
                'loop' => $this->text('controls.loop'),
                'start' => $this->text('controls.start'),
                'end' => $this->text('controls.end'),
                'all' => $this->text('controls.all'),
                'reset' => $this->text('controls.reset'),
                'disabled' => $this->text('controls.disabled'),
                'enabled' => $this->text('controls.enabled'),
                'advertisement' => $this->text('controls.advertisement'),
            ],
        ];
    }

    private function text(string $suffix): string
    {
        $key = 'catalog.player.'.$suffix;
        $value = $this->translator->get($key);

        return is_string($value) && $value !== '' && $value !== $key ? $value : '';
    }
}
