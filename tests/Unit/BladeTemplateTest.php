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

    public function test_blade_templates_do_not_contain_php_tags_or_infrastructure_calls(): void
    {
        $offendingFiles = collect(File::allFiles(resource_path('views')))
            ->filter(fn (SplFileInfo $file): bool => str_ends_with($file->getFilename(), '.blade.php'))
            ->filter(function (SplFileInfo $file): bool {
                $contents = (string) file_get_contents($file->getPathname());

                return preg_match('/<\?(?:php|=)/i', $contents) === 1
                    || preg_match('/\b(?:Auth|Cache|Gate|Redis|Storage|DB)::|\b(?:cache|config|request|resolve|app)\s*\(|::query\s*\(/', $contents) === 1
                    || preg_match('/\bnew\s+\\\\?App\\\\|\\\\?App\\\\[A-Za-z0-9_\\\\]+::/', $contents) === 1
                    || preg_match('/@(?:auth|guest|can|cannot|canany|cannotany|inject|use)\b/i', $contents) === 1;
            })
            ->map(fn (SplFileInfo $file): string => str_replace(base_path().'/', '', $file->getPathname()))
            ->values()
            ->all();

        $this->assertSame([], $offendingFiles, 'Blade templates must receive prepared request, configuration, authentication, authorization, storage, database, Redis, cache, and service state.');
    }

    public function test_catalog_artwork_is_emitted_only_by_the_shared_poster_frame(): void
    {
        $posterFrame = resource_path('views/components/ui/poster-frame.blade.php');
        $offendingFiles = collect(File::allFiles(resource_path('views')))
            ->filter(fn (SplFileInfo $file): bool => str_ends_with($file->getFilename(), '.blade.php'))
            ->reject(fn (SplFileInfo $file): bool => $file->getPathname() === $posterFrame)
            ->filter(function (SplFileInfo $file): bool {
                $contents = (string) file_get_contents($file->getPathname());

                return preg_match('/<img\b[^>]*(?:poster_url|poster_src|Постер)/iu', $contents) === 1;
            })
            ->map(fn (SplFileInfo $file): string => str_replace(base_path().'/', '', $file->getPathname()))
            ->values()
            ->all();

        $this->assertSame([], $offendingFiles, 'Catalog artwork images must be emitted only by x-ui.poster-frame.');
    }

    public function test_blade_templates_do_not_reference_legacy_title_poster_components(): void
    {
        $offendingFiles = collect(File::allFiles(resource_path('views')))
            ->filter(fn (SplFileInfo $file): bool => str_ends_with($file->getFilename(), '.blade.php'))
            ->filter(fn (SplFileInfo $file): bool => preg_match('/<x-title-(?:poster|card|list-row)\b/', (string) file_get_contents($file->getPathname())) === 1)
            ->map(fn (SplFileInfo $file): string => str_replace(base_path().'/', '', $file->getPathname()))
            ->values()
            ->all();

        $this->assertSame([], $offendingFiles, 'Public title artwork must use x-ui.poster-frame, x-ui.poster-card, or x-catalog.title-card.');
    }

    public function test_volt_is_not_installed_or_used(): void
    {
        $composer = strtolower((string) file_get_contents(base_path('composer.json')).(string) file_get_contents(base_path('composer.lock')));
        $viewFiles = collect(File::allFiles(resource_path('views')))
            ->filter(fn (SplFileInfo $file): bool => str_ends_with($file->getFilename(), '.blade.php'))
            ->map(fn (SplFileInfo $file): string => strtolower((string) file_get_contents($file->getPathname())))
            ->implode("\n");

        $this->assertStringNotContainsString('livewire/volt', $composer);
        $this->assertStringNotContainsString('@volt', $viewFiles);
        $this->assertDirectoryDoesNotExist(resource_path('views/livewire/volt'));
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

    public function test_account_form_components_render_visible_labels_and_accessible_controls(): void
    {
        $this->blade(
            '<x-form.field label="Электронная почта" for="account-email" type="email" wire:model="form.email" autocomplete="email" required />',
        )
            ->assertSeeText('Электронная почта')
            ->assertSee('for="account-email"', false)
            ->assertSee('id="account-email"', false)
            ->assertSee('wire:model="form.email"', false)
            ->assertSee('autocomplete="email"', false)
            ->assertSee('min-h-11', false);

        $this->blade(
            '<x-form.password-field label="Пароль" for="account-password" wire:model="form.password" autocomplete="current-password" required />',
        )
            ->assertSeeText('Пароль')
            ->assertSee('type="password"', false)
            ->assertSee('autocomplete="current-password"', false);

        $this->blade(
            '<x-form.checkbox label="Запомнить меня" for="remember-account" wire:model="form.remember" />',
        )
            ->assertSeeText('Запомнить меня')
            ->assertSee('type="checkbox"', false)
            ->assertSee('wire:model="form.remember"', false);
    }

    public function test_poster_frame_covers_and_overscans_without_an_inner_outline(): void
    {
        $view = $this->blade('<x-ui.poster-frame src="https://media.example.com/poster.jpg" alt="Постер сериала" class="aspect-[2/3]" />');

        $view
            ->assertSee('data-ui-poster-frame', false)
            ->assertSee('data-ui-poster-image', false)
            ->assertSee('absolute inset-0 h-full w-full scale-[1.02] object-cover object-center', false)
            ->assertDontSee('object-contain', false);

        $html = (string) $view;
        preg_match('/<img\b[^>]*data-ui-poster-image[^>]*>/i', $html, $image);

        $this->assertNotEmpty($image);
        $this->assertDoesNotMatchRegularExpression('/\b(?:border|ring|shadow|rounded|p[trblxy]?)-/', $image[0]);
    }

    public function test_poster_frame_supports_uncropped_contain_without_overscan(): void
    {
        $this->blade('<x-ui.poster-frame src="https://media.example.com/poster.jpg" alt="Постер сериала" fit="contain" :overscan="false" class="aspect-[2/3]" />')
            ->assertSee('data-ui-poster-frame', false)
            ->assertSee('absolute inset-0 h-full w-full object-contain object-center', false)
            ->assertDontSee('object-cover', false)
            ->assertDontSee('scale-[1.02]', false);
    }

    public function test_poster_frame_keeps_the_same_boundary_for_missing_artwork(): void
    {
        $this->blade('<x-ui.poster-frame alt="Постер сериала" empty-label="Постер пока не добавлен" class="aspect-[2/3]" />')
            ->assertSee('data-ui-poster-frame', false)
            ->assertSee('aspect-[2/3]', false)
            ->assertSeeText('Постер пока не добавлен')
            ->assertDontSee('data-ui-poster-image', false);
    }

    public function test_poster_card_exposes_list_layouts_without_a_public_grid_layout(): void
    {
        foreach (['list', 'compact', 'recommendation', 'stats'] as $layout) {
            $this->blade(
                '<x-ui.poster-card :layout="$layout" alt="Постер"><p>Описание</p></x-ui.poster-card>',
                ['layout' => $layout],
            )
                ->assertSee('data-ui-poster-card', false)
                ->assertSee('data-ui-poster-card-media', false)
                ->assertSee('data-ui-poster-card-body', false)
                ->assertSee('data-ui-poster-layout="'.$layout.'"', false)
                ->assertSeeText('Описание');
        }

        $this->blade('<x-ui.poster-card layout="unsupported" alt="Постер">Описание</x-ui.poster-card>')
            ->assertSee('data-ui-poster-layout="list"', false);

        $this->blade('<x-ui.poster-card layout="grid" alt="Постер">Описание</x-ui.poster-card>')
            ->assertSee('data-ui-poster-layout="list"', false);

        $this->assertFileExists(resource_path('views/components/catalog/title-card-list.blade.php'));
        $this->assertFileDoesNotExist(resource_path('views/components/catalog/title-card-grid.blade.php'));
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
