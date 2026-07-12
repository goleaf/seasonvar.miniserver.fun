<?php

namespace Tests\Unit;

use App\Enums\CatalogFilterType;
use App\Enums\CatalogSort;
use App\Http\Requests\CatalogTitlesRequest;
use App\Rules\CatalogFilterSlug;
use App\Services\Catalog\CatalogTitlesCriteria;
use App\Services\Catalog\Search\CatalogSearchQueryParser;
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
            'subtitles' => ['available', '', 'missing', 'available'],
            'quality' => ['1080p', '', '720p', '1080p'],
            'publication_type' => ['serial', '', 'anime', 'serial'],
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
        $this->assertSame(['serial', 'anime'], $request->publicationTypes());
        $this->assertSame('available', $request->videoAvailability());
        $this->assertSame(['available', 'missing'], $request->subtitleAvailability());
        $this->assertSame('month', $request->updatedPeriod());
        $this->assertSame('Ж', $request->letter());
        $this->assertSame('list', $request->view());
        $this->assertSame(48, $request->perPage());
        $this->assertSame(CatalogSort::ImdbRating, $request->sort());
    }

    public function test_catalog_titles_request_rejects_unsupported_fixed_group_values(): void
    {
        $request = CatalogTitlesRequest::create('/titles', 'GET');
        $validator = Validator::make(
            [
                'publication_type' => ['serial', 'movie'],
                'subtitles' => ['available', 'sometimes'],
            ],
            $request->rules(),
            $request->messages(),
            $request->attributes(),
        );

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('publication_type.1', $validator->errors()->messages());
        $this->assertArrayHasKey('subtitles.1', $validator->errors()->messages());
    }

    public function test_catalog_titles_criteria_captures_and_copies_normalized_state(): void
    {
        $request = CatalogTitlesRequest::create('/titles', 'GET', [
            'q' => 'Знахарь 2019',
            'year' => ['2019', '2020'],
            'genre' => ['drama'],
            'exclude_country' => ['ssha'],
            'publication_type' => ['serial', 'anime'],
            'subtitles' => ['available'],
            'updated' => 'week',
            'view' => 'list',
            'per_page' => '48',
            'sort' => 'year_desc',
        ]);
        $request->setContainer(app())->setRedirector(app('redirect'));
        $request->validateResolved();
        $search = app(CatalogSearchQueryParser::class)->parse($request->normalizedSearch());

        $criteria = CatalogTitlesCriteria::fromRequest($request, $search, 42, false);

        $this->assertSame([2019, 2020], $criteria->years);
        $this->assertSame(['drama'], $criteria->filterSlugs['genre']);
        $this->assertSame(['ssha'], $criteria->excludedFilterSlugs['country']);
        $this->assertSame(['serial', 'anime'], $criteria->publicationTypes);
        $this->assertSame(['available'], $criteria->subtitleAvailability);
        $this->assertSame(CatalogSort::YearDesc, $criteria->sort);
        $this->assertSame('list', $criteria->view);
        $this->assertSame(48, $criteria->perPage);
        $this->assertSame(42, $criteria->titleContextId);
        $this->assertTrue($criteria->hasContentFilters());
        $this->assertSame(9, $criteria->activeFilterCount());
        $this->assertNotNull($criteria->updatedAfter());
        $this->assertSame([], $criteria->withoutYears()->years);
        $this->assertSame([], $criteria->withoutPublicationTypes()->publicationTypes);
        $this->assertSame([], $criteria->withoutRelation('genre')->filterSlugs['genre']);
        $this->assertSame(['drama'], $criteria->filterSlugs['genre']);
    }

    public function test_catalog_titles_criteria_normalizes_resolved_ids_and_invalid_state(): void
    {
        $request = CatalogTitlesRequest::create('/titles', 'GET', [
            'genre' => ['drama'],
            'exclude_country' => ['ssha'],
        ]);
        $request->setContainer(app())->setRedirector(app('redirect'));
        $request->validateResolved();
        $search = app(CatalogSearchQueryParser::class)->parse('');

        $criteria = CatalogTitlesCriteria::fromRequest($request, $search, null, false)
            ->withResolvedTaxonomies(
                selected: [
                    'genre' => [3, 3, 0, -1, ...range(4, 30)],
                    'unsupported' => [99],
                ],
                excluded: [
                    'country' => [8, 8, 0],
                    'unsupported' => [100],
                ],
                invalidYear: true,
            );

        $this->assertSame(range(3, 22), $criteria->selectedTaxonomyIds['genre']);
        $this->assertArrayNotHasKey('unsupported', $criteria->selectedTaxonomyIds);
        $this->assertSame([8], $criteria->excludedTaxonomyIds['country']);
        $this->assertTrue($criteria->invalidYear);
        $this->assertSame([], $criteria->withoutRelation('genre')->selectedTaxonomyIds['genre']);
    }
}
