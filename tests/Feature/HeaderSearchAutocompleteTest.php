<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

final class HeaderSearchAutocompleteTest extends TestCase
{
    use RefreshDatabase;

    public function test_header_search_uses_progressive_api_autocomplete_and_keeps_get_fallback(): void
    {
        $html = $this->renderSearch('Север');

        $this->assertStringContainsString('data-header-search-autocomplete', $html);
        $this->assertStringContainsString('data-suggestions-endpoint="'.route('api.v1.search.suggestions').'"', $html);
        $this->assertStringContainsString('method="GET"', $html);
        $this->assertStringContainsString('name="q"', $html);
        $this->assertStringContainsString('value="Север"', $html);
        $this->assertStringNotContainsString('wire:model', $html);
    }

    public function test_markup_supports_keyboard_combobox_rich_results_and_no_internal_scroll_region(): void
    {
        $html = $this->renderSearch();

        $this->assertStringContainsString('role="combobox"', $html);
        $this->assertStringContainsString('role="listbox"', $html);
        $this->assertStringContainsString('aria-activedescendant', $html);
        $this->assertStringContainsString('data-header-search-title-results', $html);
        $this->assertStringContainsString('data-header-search-portal-results', $html);
        $this->assertStringContainsString('data-header-search-status', $html);
        $this->assertStringContainsString('class="absolute left-0 top-[calc(100%+0.5rem)] z-[70] hidden w-full max-w-none rounded-control border border-slate-200 bg-white p-2 shadow-xl shadow-slate-900/15"', $html);
        $this->assertLessThan(
            strpos($html, 'data-header-search-dropdown'),
            strpos($html, 'data-header-search-input-frame'),
        );
        $this->assertStringNotContainsString('overflow-y-', $html);
    }

    public function test_header_search_input_never_uses_a_colored_focus_frame(): void
    {
        $html = $this->renderSearch();
        $css = file_get_contents(resource_path('css/app.css'));

        $this->assertMatchesRegularExpression(
            '/<div[^>]*data-header-search-input-frame[^>]*>/',
            $html,
        );
        preg_match('/<div[^>]*data-header-search-input-frame[^>]*>/', $html, $matches);
        $frame = $matches[0] ?? '';

        $this->assertStringContainsString('border-slate-300', $frame);
        $this->assertStringNotContainsString('focus-within:border-', $frame);
        $this->assertStringNotContainsString('focus-within:ring-', $frame);
        $this->assertIsString($css);
        $this->assertStringContainsString('[data-header-search-input]:focus-visible', $css);
        $this->assertMatchesRegularExpression(
            '/\[data-header-search-input\]:focus-visible\s*\{[^}]*outline:\s*none[^}]*box-shadow:\s*none/s',
            $css,
        );
    }

    public function test_interface_language_control_is_hidden_from_public_layout_and_kept_in_profile_settings(): void
    {
        $this->assertFalse(Route::has('locale.switch'));

        $this->get(route('home'))
            ->assertOk()
            ->assertDontSee('/interface-locale', false);

        $this->actingAs(User::factory()->create())
            ->get(route('settings.index', ['section' => 'appearance']))
            ->assertOk()
            ->assertSee('id="settings-locale"', false);
    }

    private function renderSearch(string $query = ''): string
    {
        return Blade::render(
            '<x-layout.header-search :initial-query="$query" :search-url="$searchUrl" />',
            [
                'query' => $query,
                'searchUrl' => route('titles.index'),
            ],
        );
    }
}
