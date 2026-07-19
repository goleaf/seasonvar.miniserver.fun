<?php

declare(strict_types=1);

namespace Tests\Unit;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

final class LivewireWirePollContractTest extends TestCase
{
    public function test_only_visible_active_state_workflows_use_polling(): void
    {
        $markup = collect(File::allFiles(resource_path('views')))
            ->map(fn ($file): string => File::get($file->getPathname()))
            ->implode("\n");

        $this->assertSame(2, substr_count($markup, 'wire:poll.'));
        $this->assertStringContainsString('wire:poll.3s.visible="refreshCatalog"', $markup);
        $this->assertStringContainsString('wire:poll.5s.visible="refreshRuns"', $markup);
        $this->assertStringNotContainsString('wire:poll.keep-alive', $markup);
        $this->assertDoesNotMatchRegularExpression('/wire:poll(?:\s|=|>)/', $markup);
        $this->assertStringNotContainsString(
            'wire:poll',
            File::get(resource_path('views/livewire/stats-dashboard.blade.php')),
        );
    }

    public function test_canonical_docs_match_the_requestless_stats_snapshot(): void
    {
        foreach ([
            base_path('docs/architecture.md'),
            base_path('docs/performance.md'),
            base_path('docs/UI_STANDARDS.md'),
        ] as $path) {
            $documentation = File::get($path);

            $this->assertStringContainsString('`/stats` не использует `wire:poll`', $documentation);
            $this->assertStringNotContainsString('`/stats` использует `wire:poll.15s.visible`', $documentation);
        }

        $frontend = File::get(base_path('docs/frontend.md'));

        $this->assertStringContainsString('wire:poll.3s.visible="refreshCatalog"', $frontend);
        $this->assertStringContainsString('wire:poll.5s.visible="refreshRuns"', $frontend);
        $this->assertStringContainsString('`/stats` не использует `wire:poll`', $frontend);
    }
}
