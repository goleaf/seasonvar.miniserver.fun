<?php

declare(strict_types=1);

namespace Tests\Unit;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

final class LivewireWireRefContractTest extends TestCase
{
    public function test_title_refresh_targets_its_one_referenced_player_child(): void
    {
        $markup = collect(File::allFiles(resource_path('views')))
            ->map(fn ($file): string => File::get($file->getPathname()))
            ->implode("\n");
        $detailView = File::get(resource_path('views/livewire/catalog-title-detail.blade.php'));
        $detail = File::get(app_path('Livewire/CatalogTitleDetail.php'));
        $player = File::get(app_path('Livewire/CatalogTitlePlayer.php'));

        $this->assertSame(1, substr_count($markup, 'wire:ref='));
        $this->assertMatchesRegularExpression(
            '/<livewire:catalog-title-player\b(?:(?!\/>).)*wire:ref="player"(?:(?!\/>).)*\/>/s',
            $detailView,
        );
        $this->assertStringContainsString(':wire:key="\'catalog-title-player-\'.$title->id"', $detailView);
        $this->assertStringContainsString("->to(ref: 'player')", $detail);
        $this->assertStringNotContainsString('->to(component: CatalogTitlePlayer::class)', $detail);
        $this->assertStringContainsString("#[On('catalog-title-refreshed')]", $player);
        $this->assertStringContainsString('if ($catalogTitleId !== $this->catalogTitleId)', $player);
    }
}
