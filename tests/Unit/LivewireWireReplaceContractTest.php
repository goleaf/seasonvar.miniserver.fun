<?php

declare(strict_types=1);

namespace Tests\Unit;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

final class LivewireWireReplaceContractTest extends TestCase
{
    public function test_only_live_catalog_leaf_checkboxes_replace_themselves(): void
    {
        $markup = collect(File::allFiles(resource_path('views')))
            ->map(fn ($file): string => File::get($file->getPathname()))
            ->implode("\n");
        $runtime = collect(File::allFiles(resource_path('js')))
            ->map(fn ($file): string => File::get($file->getPathname()))
            ->implode("\n");

        preg_match_all('/<input\b[^>]*\bwire:replace\.self\b[^>]*>/s', $markup, $replacementInputs);

        $this->assertCount(4, $replacementInputs[0]);
        $this->assertSame(4, substr_count($markup, 'wire:replace.self'));

        foreach ($replacementInputs[0] as $input) {
            $this->assertStringContainsString('type="checkbox"', $input);
            $this->assertMatchesRegularExpression('/\bwire:model\.live(?:\.[^=\s]+)*=/', $input);
        }

        $this->assertDoesNotMatchRegularExpression('/wire:replace(?!\.self)/', $markup);
        $this->assertStringNotContainsString('customElements.define(', $runtime);
        $this->assertStringNotContainsString('.attachShadow(', $runtime);
    }

    public function test_player_keeps_its_keyed_ignore_lifecycle_instead_of_replacement(): void
    {
        $player = File::get(resource_path('views/livewire/catalog-title-player.blade.php'));
        $runtime = File::get(resource_path('js/player.js'));

        $this->assertStringContainsString('wire:key="catalog-player-media-shell-', $player);
        $this->assertStringContainsString('wire:ignore', $player);
        $this->assertStringNotContainsString('wire:replace', $player);
        $this->assertStringContainsString('new this.Plyr(this.video', $runtime);
        $this->assertStringContainsString('new this.Hls(', $runtime);
        $this->assertStringContainsString('this.plyr.destroy(function ()', $runtime);
        $this->assertStringContainsString('this.hls?.destroy()', $runtime);
    }
}
