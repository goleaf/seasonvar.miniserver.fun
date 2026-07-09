<?php

namespace Tests\Unit;

use App\Http\Requests\CatalogTitlesRequest;
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
}
