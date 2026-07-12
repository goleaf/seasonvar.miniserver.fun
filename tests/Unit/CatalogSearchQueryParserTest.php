<?php

namespace Tests\Unit;

use App\Services\Catalog\Search\CatalogSearchQueryParser;
use App\Services\Catalog\Search\CatalogSearchState;
use Tests\TestCase;

class CatalogSearchQueryParserTest extends TestCase
{
    public function test_it_exposes_the_stopword_decision_for_legacy_search(): void
    {
        $parser = app(CatalogSearchQueryParser::class);

        $this->assertTrue($parser->isStopWord('смотреть'));
        $this->assertFalse($parser->isStopWord('знахарь'));
    }

    public function test_it_extracts_one_year_and_keeps_only_meaningful_terms(): void
    {
        $query = app(CatalogSearchQueryParser::class)->parse('сериал Знахарь 2019 смотреть онлайн');

        $this->assertSame(CatalogSearchState::Ready, $query->state);
        $this->assertSame('сериал Знахарь 2019 смотреть онлайн', $query->raw);
        $this->assertSame(['знахарь'], $query->terms);
        $this->assertSame(2019, $query->year);
        $this->assertSame('"знахарь"*', $query->ftsExpression);
        $this->assertContains(hash('sha256', 'знахарь'), $query->exactNameHashes);
    }

    public function test_it_preserves_short_and_punctuation_queries(): void
    {
        $parser = app(CatalogSearchQueryParser::class);

        $this->assertSame(['oa'], $parser->parse('OA')->terms);
        $this->assertSame('"oa"', $parser->parse('OA')->ftsExpression);
        $this->assertSame(['11', '22', '63'], $parser->parse('11.22.63')->terms);
        $this->assertNull($parser->parse('11.22.63')->year);
    }

    public function test_it_distinguishes_empty_and_stopword_only_queries(): void
    {
        $parser = app(CatalogSearchQueryParser::class);

        $this->assertSame(CatalogSearchState::Empty, $parser->parse('')->state);
        $this->assertSame(CatalogSearchState::Insufficient, $parser->parse('смотреть онлайн')->state);
    }

    public function test_it_treats_a_year_only_query_as_ready(): void
    {
        $query = app(CatalogSearchQueryParser::class)->parse('2019');

        $this->assertSame(CatalogSearchState::Ready, $query->state);
        $this->assertSame(2019, $query->year);
        $this->assertSame([], $query->terms);
    }

    public function test_it_keeps_multiple_distinct_years_as_terms(): void
    {
        $query = app(CatalogSearchQueryParser::class)->parse('2019 2020');

        $this->assertSame(CatalogSearchState::Ready, $query->state);
        $this->assertNull($query->year);
        $this->assertSame(['2019', '2020'], $query->terms);
    }

    public function test_it_preserves_first_seen_term_order_and_caps_distinct_terms_at_eight(): void
    {
        $query = app(CatalogSearchQueryParser::class)->parse(
            'gamma alpha gamma beta delta epsilon zeta eta theta iota kappa alpha'
        );

        $this->assertSame(
            ['gamma', 'alpha', 'beta', 'delta', 'epsilon', 'zeta', 'eta', 'theta'],
            $query->terms,
        );
    }

    public function test_it_builds_an_explicit_conjunction_from_an_operator_and_quote_payload(): void
    {
        $query = app(CatalogSearchQueryParser::class)->parse('"OA" OR fm*');

        $this->assertSame(['oa', 'fm'], $query->terms);
        $this->assertSame('"oa" AND "fm"', $query->ftsExpression);
    }
}
