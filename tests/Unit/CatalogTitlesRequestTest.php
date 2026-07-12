<?php

namespace Tests\Unit;

use App\Enums\CatalogFilterType;
use App\Enums\CatalogSort;
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

    public function test_catalog_search_requires_two_and_allows_eighty_characters(): void
    {
        $request = CatalogTitlesRequest::create('/titles', 'GET');
        $rules = $request->rules();

        $this->assertContains('min:2', $rules['q']);
        $this->assertContains('max:80', $rules['q']);
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

    public function test_catalog_titles_request_normalizes_bounded_multi_value_state(): void
    {
        $request = CatalogTitlesRequest::create('/titles', 'GET', [
            'year' => ['2024', '2023', '2024'],
            'country' => ['rossiya', 'kanada', 'rossiya'],
            'actor' => 'ivan-ivanov',
            'exclude_country' => ['ssha'],
            'year_from' => '2010',
            'year_to' => '2024',
            'seasons_min' => '2',
            'episodes_max' => '100',
            'rating_source' => 'imdb',
            'rating_min' => '7.5',
            'votes_min' => '1000',
            'video' => 'available',
            'subtitles' => 'available',
            'quality' => ['1080p', '720p'],
            'updated' => 'month',
            'letter' => 'Ж',
            'view' => 'list',
            'per_page' => '48',
            'sort' => 'imdb_desc',
        ]);
        $request->setContainer(app())->setRedirector(app('redirect'));
        $request->validateResolved();

        $this->assertSame([2024, 2023], $request->years());
        $this->assertSame(['rossiya', 'kanada'], $request->filterSlugs()['country']);
        $this->assertSame(['ivan-ivanov'], $request->filterSlugs()['actor']);
        $this->assertSame(['ssha'], $request->excludedFilterSlugs()['country']);
        $this->assertSame([2010, 2024], [$request->yearFrom(), $request->yearTo()]);
        $this->assertSame([2, null], [$request->seasonsMin(), $request->seasonsMax()]);
        $this->assertSame([null, 100], [$request->episodesMin(), $request->episodesMax()]);
        $this->assertSame('imdb', $request->ratingSource());
        $this->assertSame(7.5, $request->ratingMin());
        $this->assertSame(1000, $request->votesMin());
        $this->assertSame(['1080p', '720p'], $request->qualities());
        $this->assertSame('available', $request->videoAvailability());
        $this->assertSame('available', $request->subtitleAvailability());
        $this->assertSame('month', $request->updatedPeriod());
        $this->assertSame('Ж', $request->letter());
        $this->assertSame('list', $request->view());
        $this->assertSame(48, $request->perPage());
        $this->assertSame(CatalogSort::ImdbRating, $request->sort());
    }
}
