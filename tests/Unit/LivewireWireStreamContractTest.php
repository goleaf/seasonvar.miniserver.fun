<?php

declare(strict_types=1);

namespace Tests\Unit;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

final class LivewireWireStreamContractTest extends TestCase
{
    public function test_application_has_no_unjustified_livewire_dom_stream(): void
    {
        $markup = collect(File::allFiles(resource_path('views')))
            ->map(fn ($file): string => File::get($file->getPathname()))
            ->implode("\n");
        $components = collect(File::allFiles(app_path('Livewire')))
            ->map(fn ($file): string => File::get($file->getPathname()))
            ->implode("\n");

        $this->assertStringNotContainsString('wire:stream', $markup);
        $this->assertStringNotContainsString('$this->stream(', $components);
    }

    public function test_architecture_distinguishes_dom_streaming_from_streamed_responses(): void
    {
        $architecture = File::get(base_path('docs/architecture.md'));

        $this->assertStringContainsString('`wire:stream` дописывает содержимое по умолчанию', $architecture);
        $this->assertStringContainsString('`.replace`', $architecture);
        $this->assertStringContainsString('несовместим с Laravel Octane', $architecture);
        $this->assertStringContainsString('`response()->stream()`', $architecture);
    }
}
