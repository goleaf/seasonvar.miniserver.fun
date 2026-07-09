<?php

namespace Tests\Unit;

use App\Enums\CatalogFilterType;
use App\Http\Requests\CatalogTitlesRequest;
use App\Rules\CatalogFilterSlug;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

class CatalogTitlesRequestTest extends TestCase
{
    public function test_catalog_titles_request_authorizes_public_catalog_reads(): void
    {
        $request = CatalogTitlesRequest::create('/titles', 'GET');

        $this->assertTrue($request->authorize());
    }

    public function test_catalog_titles_request_normalizes_search_year_and_filter_slugs(): void
    {
        $request = CatalogTitlesRequest::create('/titles', 'GET', [
            'q' => '  Знахарь   описание  ',
            'year' => '1800',
            'genre' => 'detective-series',
        ]);

        $this->assertSame('Знахарь описание', $request->normalizedSearch());
        $this->assertNull($request->year());
        $this->assertTrue($request->invalidYear());
        $this->assertSame('detective-series', $request->filterSlug($request->query('genre')));
        $this->assertNull($request->filterSlug(['bad']));
    }

    public function test_catalog_titles_request_declares_filter_rules_for_every_supported_filter_type(): void
    {
        $request = CatalogTitlesRequest::create('/titles', 'GET');
        $rules = $request->rules();

        foreach (CatalogFilterType::values() as $filterType) {
            $this->assertArrayHasKey($filterType, $rules);
        }

        $this->assertArrayHasKey('q', $rules);
        $this->assertArrayHasKey('year', $rules);
        $this->assertArrayHasKey('type', $rules);
        $this->assertArrayHasKey('taxonomy', $rules);
    }

    public function test_catalog_filter_slug_rule_rejects_malformed_slugs(): void
    {
        $request = CatalogTitlesRequest::create('/titles', 'GET');
        $validator = Validator::make(
            ['genre' => 'Bad Slug'],
            $request->rules(),
            $request->messages(),
            $request->attributes(),
        );

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('genre', $validator->errors()->messages());
        $this->assertNull(CatalogFilterSlug::normalize('Bad Slug'));
        $this->assertSame('detective-series', CatalogFilterSlug::normalize('detective-series'));
    }
}
