<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Catalog\CatalogRecommendationThemeExtractor;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CatalogRecommendationThemeExtractorTest extends TestCase
{
    public function test_it_extracts_relationship_themes_for_aynen_aynen(): void
    {
        $themes = app(CatalogRecommendationThemeExtractor::class)->extract(
            'Именно так',
            'Aynen Aynen',
            'Двое молодых людей с юных лет являются близкими друзьями. Между ними появляются чувства и начинается большая любовь.',
        );

        $this->assertArrayHasKey('romance', $themes);
        $this->assertArrayHasKey('relationships', $themes);
        $this->assertArrayHasKey('friendship', $themes);
        $this->assertArrayHasKey('youth', $themes);
    }

    public function test_it_does_not_treat_a_detective_or_a_man_as_family_or_marriage(): void
    {
        $themes = app(CatalogRecommendationThemeExtractor::class)->extract(
            'Детектив',
            null,
            'Мужчина расследует преступление и ищет убийцу.',
        );

        $this->assertArrayNotHasKey('family', $themes);
        $this->assertArrayNotHasKey('relationships', $themes);
        $this->assertArrayHasKey('crime', $themes);
    }

    public function test_it_normalizes_yo_and_limits_the_profile_size(): void
    {
        $extractor = app(CatalogRecommendationThemeExtractor::class);
        $normalizedThemes = $extractor->extract('История актёров', null, null);
        $boundedThemes = $extractor->extract(
            'История актёров',
            null,
            'Молодые друзья влюбляются, создают семью, учатся, работают врачами и юристами, расследуют тайну преступления, встречают вампира, путешествуют, занимаются спортом и музыкой.',
        );

        $this->assertSame('Шоу-бизнес', $normalizedThemes['show_business']);
        $this->assertLessThanOrEqual(8, count($boundedThemes));
    }

    /**
     * @param  list<string>  $present
     * @param  list<string>  $missing
     */
    #[DataProvider('themeCorpus')]
    public function test_it_matches_whole_tokens_and_phrases_without_substring_collisions(
        string $text,
        array $present,
        array $missing,
    ): void {
        $themes = app(CatalogRecommendationThemeExtractor::class)->extract(null, null, $text);

        foreach ($present as $theme) {
            $this->assertArrayHasKey($theme, $themes);
        }

        foreach ($missing as $theme) {
            $this->assertArrayNotHasKey($theme, $themes);
        }
    }

    /**
     * @return iterable<string, array{text: string, present: list<string>, missing: list<string>}>
     */
    public static function themeCorpus(): iterable
    {
        $cases = require dirname(__DIR__).'/Fixtures/recommendations/theme-corpus.php';

        foreach ($cases as $name => $case) {
            yield $name => $case;
        }
    }
}
