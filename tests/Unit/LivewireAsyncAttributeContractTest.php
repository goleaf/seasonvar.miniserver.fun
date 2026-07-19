<?php

declare(strict_types=1);

namespace Tests\Unit;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

final class LivewireAsyncAttributeContractTest extends TestCase
{
    public function test_application_has_no_unjustified_async_livewire_action(): void
    {
        $components = collect(File::allFiles(app_path('Livewire')))
            ->map(fn ($file): string => File::get($file->getPathname()))
            ->implode("\n");
        $markup = collect(File::allFiles(resource_path('views')))
            ->map(fn ($file): string => File::get($file->getPathname()))
            ->implode("\n");

        $this->assertStringNotContainsString('Livewire\Attributes\Async', $components);
        $this->assertStringNotContainsString('#[Async]', $components);
        $this->assertDoesNotMatchRegularExpression('/wire:[a-z-]+\.async(?:=|\s)/', $markup);
    }

    public function test_architecture_records_the_async_race_boundary(): void
    {
        $architecture = File::get(base_path('docs/architecture.md'));

        $this->assertStringContainsString('`#[Async]` запускает action параллельно и немедленно, без queue', $architecture);
        $this->assertStringContainsString('fire-and-forget side effect', $architecture);
        $this->assertStringContainsString('не меняет отражённое в UI состояние компонента', $architecture);
        $this->assertStringContainsString('modifier `.async`', $architecture);
    }
}
