<?php

declare(strict_types=1);

namespace Tests\Feature;

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
            ->assertSeeText(__("recommendations.types.{$type}.description"));

        $this->assertSame(1, substr_count($response->getContent(), '<h1'));
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

    public function test_non_popular_modes_do_not_render_collection_section_navigation(): void
    {
        $this->get(route('discover.index', ['type' => 'random']))
            ->assertOk()
            ->assertDontSee('data-discovery-section-navigation', false)
            ->assertDontSee('data-discovery-collection-results', false)
            ->assertDontSee('id="popular-titles"', false);
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
