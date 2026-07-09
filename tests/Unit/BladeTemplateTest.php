<?php

namespace Tests\Unit;

use Illuminate\Support\Facades\File;
use SplFileInfo;
use Tests\TestCase;

class BladeTemplateTest extends TestCase
{
    public function test_blade_templates_do_not_use_inline_php_directives(): void
    {
        $offendingFiles = collect(File::allFiles(resource_path('views')))
            ->filter(fn (SplFileInfo $file): bool => str_ends_with($file->getFilename(), '.blade.php'))
            ->filter(fn (SplFileInfo $file): bool => preg_match('/@(php|endphp)\b/', (string) file_get_contents($file->getPathname())) === 1)
            ->map(fn (SplFileInfo $file): string => str_replace(base_path().'/', '', $file->getPathname()))
            ->values()
            ->all();

        $this->assertSame([], $offendingFiles, 'Blade templates must move PHP logic into controllers, view-models, or component classes.');
    }

    public function test_status_pill_component_renders_slot_icon_and_variant_classes(): void
    {
        $view = $this->blade('<x-ui.status-pill icon="fa-solid fa-circle-check" variant="success">Готово</x-ui.status-pill>');

        $view
            ->assertSeeText('Готово')
            ->assertSee('fa-solid fa-circle-check', false)
            ->assertSee('bg-emerald-50', false);
    }
}
