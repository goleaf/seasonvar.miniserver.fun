<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\CatalogTitle;
use App\Models\LicensedMedia;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class CatalogDiscoveryLayoutTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('discoveryTypes')]
    public function test_each_discovery_mode_has_one_heading_and_its_own_summary(string $type): void
    {
        $response = $this->get(route('discover.index', ['type' => $type]))
            ->assertOk()
            ->assertSeeText(__("recommendations.types.{$type}.title"))
            ->assertSeeText(__("recommendations.types.{$type}.description"))
            ->assertSee('data-discovery-section-navigation', false)
            ->assertSee('href="#collections"', false)
            ->assertSee('data-discovery-collection-results', false)
            ->assertSeeLivewire('collections.catalog-collection-explorer');

        $this->assertSame(1, substr_count($response->getContent(), '<h1'));

        if ($type === 'popular') {
            $response
                ->assertSee('href="#popular-titles"', false)
                ->assertSee('id="popular-titles"', false);
        } else {
            $response
                ->assertSee('href="#discovery-titles"', false)
                ->assertSee('id="discovery-titles"', false)
                ->assertDontSee('id="popular-titles"', false);
        }
    }

    public function test_navigation_exposes_only_the_nine_implemented_discovery_modes(): void
    {
        $html = $this->get(route('discover.index', ['type' => 'popular']))
            ->assertOk()
            ->getContent();

        $this->assertSame(9, substr_count($html, 'data-discovery-type-link'));

        foreach (array_keys(self::discoveryTypes()) as $type) {
            $this->assertStringContainsString(
                'href="'.route('discover.index', ['type' => $type]).'"',
                $html,
            );
        }

        $this->assertDoesNotMatchRegularExpression('/href="[^"]*\/discover"/', $html);
    }

    public function test_refresh_is_a_secondary_results_action_and_filters_open_only_when_active(): void
    {
        $plainHtml = $this->get(route('discover.index', ['type' => 'popular']))
            ->assertOk()
            ->assertSee('data-discovery-refresh-secondary', false)
            ->assertSee('data-discovery-filters', false)
            ->getContent();

        preg_match('/<header[^>]*data-discovery-heading.*?<\/header>/s', $plainHtml, $heading);

        $this->assertArrayHasKey(0, $heading);
        $this->assertStringNotContainsString('refreshRecommendations', $heading[0]);
        $this->assertDoesNotMatchRegularExpression('/<details[^>]*data-discovery-filters[^>]*\sopen(?:\s|>)/', $plainHtml);

        $activeHtml = $this->get(route('discover.index', [
            'type' => 'popular',
            'genre' => 'drama',
        ]))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/<details[^>]*data-discovery-filters[^>]*\sopen(?:\s|>)/', $activeHtml);
    }

    public function test_popular_mode_visually_separates_series_from_collection_explorer(): void
    {
        $response = $this->get(route('discover.index', ['type' => 'popular']))
            ->assertOk()
            ->assertSee('data-discovery-section-navigation', false)
            ->assertSee('href="#collections"', false)
            ->assertSee('href="#popular-titles"', false)
            ->assertSee('data-discovery-title-results', false)
            ->assertSee('id="popular-titles"', false)
            ->assertSee('data-discovery-collection-results', false)
            ->assertSeeText(__('recommendations.page.popular_series'))
            ->assertSeeText(__('recommendations.page.series_section_title'))
            ->assertSeeText(__('collections.directory.title'));

        $html = $response->getContent();

        $this->assertLessThan(
            strpos($html, 'data-discovery-title-results'),
            strpos($html, 'data-discovery-collection-results'),
        );
    }

    public function test_non_popular_modes_render_collection_explorer_before_their_series_results(): void
    {
        $response = $this->get(route('discover.index', ['type' => 'random']))
            ->assertOk()
            ->assertSee('data-discovery-section-navigation', false)
            ->assertSee('href="#collections"', false)
            ->assertSee('href="#discovery-titles"', false)
            ->assertSee('data-discovery-collection-results', false)
            ->assertSee('data-discovery-title-results', false)
            ->assertSee('id="discovery-titles"', false)
            ->assertDontSee('id="popular-titles"', false);

        $html = $response->getContent();

        $this->assertLessThan(
            strpos($html, 'data-discovery-title-results'),
            strpos($html, 'data-discovery-collection-results'),
        );
    }

    public function test_personalized_series_results_use_green_surface_and_white_cards_only_in_personalized_mode(): void
    {
        $title = CatalogTitle::factory()->create([
            'title' => 'Заметная персональная рекомендация',
            'indexed_at' => now(),
        ]);
        LicensedMedia::factory()->create([
            'catalog_title_id' => $title->id,
            'status' => 'published',
        ]);

        $personalizedHtml = $this->get(route('discover.index', ['type' => 'personalized']))
            ->assertOk()
            ->assertSee('data-personalized-series-surface', false)
            ->assertSee('data-discovery-series-card', false)
            ->getContent();

        preg_match(
            '/<section[^>]*data-discovery-title-results[^>]*class="([^"]*)"/',
            $personalizedHtml,
            $personalizedSection,
        );
        preg_match(
            '/<li[^>]*data-discovery-series-card[^>]*class="([^"]*)"/',
            $personalizedHtml,
            $personalizedCard,
        );

        $this->assertStringContainsString('bg-emerald-50', $personalizedSection[1] ?? '');
        $this->assertStringContainsString('border-emerald-200', $personalizedSection[1] ?? '');
        $this->assertStringContainsString('bg-white', $personalizedCard[1] ?? '');
        $this->assertStringContainsString('border-emerald-100', $personalizedCard[1] ?? '');

        $randomHtml = $this->get(route('discover.index', ['type' => 'random']))
            ->assertOk()
            ->assertDontSee('data-personalized-series-surface', false)
            ->getContent();

        preg_match(
            '/<section[^>]*data-discovery-title-results[^>]*class="([^"]*)"/',
            $randomHtml,
            $randomSection,
        );

        $this->assertStringNotContainsString('bg-emerald-50', $randomSection[1] ?? '');
    }

    /** @return array<string, array{string}> */
    public static function discoveryTypes(): array
    {
        return [
            'personalized' => ['personalized'],
            'trending' => ['trending'],
            'popular' => ['popular'],
            'top_rated' => ['top_rated'],
            'recently_added' => ['recently_added'],
            'recently_updated' => ['recently_updated'],
            'upcoming' => ['upcoming'],
            'editorial' => ['editorial'],
            'random' => ['random'],
        ];
    }
}
