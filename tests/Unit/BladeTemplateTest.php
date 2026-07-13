<?php

namespace Tests\Unit;

use Illuminate\Support\Facades\File;
use SplFileInfo;
use Tests\TestCase;

class BladeTemplateTest extends TestCase
{
    public function test_shared_icon_component_owns_fontawesome_markup_and_first_line_alignment(): void
    {
        $this->assertFileExists(resource_path('views/components/ui/icon.blade.php'));

        $view = $this->blade('<x-ui.icon name="fa-solid fa-circle-info" align="start" data-test-icon />');

        $view
            ->assertSee('data-ui-icon="true"', false)
            ->assertSee('data-test-icon', false)
            ->assertSee('aria-hidden="true"', false)
            ->assertSee('ui-icon', false)
            ->assertSee('ui-icon--start', false)
            ->assertSee('fa-solid fa-circle-info', false);
    }

    public function test_blade_templates_render_fontawesome_only_through_the_shared_icon_component(): void
    {
        $iconComponent = resource_path('views/components/ui/icon.blade.php');
        $offendingFiles = collect(File::allFiles(resource_path('views')))
            ->filter(fn (SplFileInfo $file): bool => str_ends_with($file->getFilename(), '.blade.php'))
            ->reject(fn (SplFileInfo $file): bool => $file->getPathname() === $iconComponent)
            ->filter(fn (SplFileInfo $file): bool => preg_match('/<i\b/', (string) file_get_contents($file->getPathname())) === 1)
            ->map(fn (SplFileInfo $file): string => str_replace(base_path().'/', '', $file->getPathname()))
            ->values()
            ->all();

        $this->assertSame([], $offendingFiles, 'Blade templates must render decorative FontAwesome icons through x-ui.icon.');
    }

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

    public function test_blade_templates_do_not_truncate_visible_interface_text(): void
    {
        $offendingFiles = collect(File::allFiles(resource_path('views')))
            ->filter(fn (SplFileInfo $file): bool => str_ends_with($file->getFilename(), '.blade.php'))
            ->filter(fn (SplFileInfo $file): bool => preg_match('/\b(line-clamp-\d+|truncate|text-ellipsis|overflow-ellipsis)\b/', (string) file_get_contents($file->getPathname())) === 1)
            ->map(fn (SplFileInfo $file): string => str_replace(base_path().'/', '', $file->getPathname()))
            ->values()
            ->all();

        $this->assertSame([], $offendingFiles, 'Blade templates must show interface text without truncation utilities.');
    }

    public function test_status_pill_component_renders_slot_icon_and_variant_classes(): void
    {
        $view = $this->blade('<x-ui.status-pill icon="fa-solid fa-circle-check" variant="success">Готово</x-ui.status-pill>');

        $view
            ->assertSeeText('Готово')
            ->assertSee('fa-solid fa-circle-check', false)
            ->assertSee('bg-emerald-50', false);
    }

    public function test_catalog_count_translations_follow_russian_and_english_plural_rules(): void
    {
        app()->setLocale('ru');

        $this->assertSame('1 сериал', trans_choice('catalog.counts.results', 1));
        $this->assertSame('2 сериала', trans_choice('catalog.counts.results', 2));
        $this->assertSame('5 сериалов', trans_choice('catalog.counts.results', 5));
        $this->assertSame('11 сериалов', trans_choice('catalog.counts.results', 11));
        $this->assertSame('21 сериал', trans_choice('catalog.counts.results', 21));
        $this->assertSame('22 серии', trans_choice('catalog.counts.episodes', 22));
        $this->assertSame('25 оценок', trans_choice('catalog.counts.ratings', 25));
        $this->assertSame('1 запись импорта', trans_choice('catalog.counts.import_records', 1));
        $this->assertSame('2 записи истории', trans_choice('catalog.counts.history_items', 2));

        app()->setLocale('en');

        $this->assertSame('1 series', trans_choice('catalog.counts.results', 1));
        $this->assertSame('2 series', trans_choice('catalog.counts.results', 2));
        $this->assertSame('1 season', trans_choice('catalog.counts.seasons', 1));
        $this->assertSame('2 seasons', trans_choice('catalog.counts.seasons', 2));
        $this->assertSame('1 rating', trans_choice('catalog.counts.ratings', 1));
        $this->assertSame('2 history items', trans_choice('catalog.counts.history_items', 2));
    }
}
