<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\DTOs\CatalogTopListFilters;
use App\Http\Requests\CatalogTopListRequest;
use App\Models\Country;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

final class CatalogTopListFiltersTest extends TestCase
{
    use RefreshDatabase;

    public function test_filters_expose_only_active_normalized_values(): void
    {
        $filters = new CatalogTopListFilters(2010, 2019, 'litva', 'dramy');

        $this->assertTrue($filters->active());
        $this->assertSame([
            'year_from' => 2010,
            'year_to' => 2019,
            'country' => 'litva',
            'genre' => 'dramy',
        ], $filters->query());
        $this->assertSame($filters->query(), $filters->contextFilters());
        $this->assertFalse(CatalogTopListFilters::empty()->active());
    }

    public function test_request_rejects_unknown_country(): void
    {
        Country::query()->create(['name' => 'Литва', 'slug' => 'litva']);
        $request = CatalogTopListRequest::create('/top/movies', 'GET', [
            'country' => 'unknown',
        ]);
        $validator = $this->validator($request);

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('country', $validator->errors()->toArray());
    }

    public function test_request_rejects_unknown_genre(): void
    {
        $request = CatalogTopListRequest::create('/top/movies', 'GET', [
            'genre' => 'unknown',
        ]);
        $validator = $this->validator($request);

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('genre', $validator->errors()->toArray());
    }

    public function test_request_rejects_inverted_years(): void
    {
        $request = CatalogTopListRequest::create('/top/movies', 'GET', [
            'year_from' => '2020',
            'year_to' => '2010',
        ]);
        $validator = $this->validator($request);

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('year_from', $validator->errors()->toArray());
    }

    private function validator(CatalogTopListRequest $request): \Illuminate\Validation\Validator
    {
        $validator = Validator::make(
            $request->all(),
            $request->rules(),
            $request->messages(),
            $request->attributes(),
        );

        foreach ($request->after() as $after) {
            $validator->after($after);
        }

        return $validator;
    }
}
