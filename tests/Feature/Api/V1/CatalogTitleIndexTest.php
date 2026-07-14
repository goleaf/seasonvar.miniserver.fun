<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1;

use App\Enums\ContentAudience;
use App\Models\CatalogTitle;
use App\Models\Country;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class CatalogTitleIndexTest extends TestCase
{
    use RefreshDatabase;

    public function test_v1_titles_support_indexed_and_unindexed_filter_arrays(): void
    {
        $turkey = Country::query()->create(['name' => 'Турция', 'slug' => 'turciia']);
        $matching = CatalogTitle::factory()->create(['slug' => 'turkish-title']);
        $other = CatalogTitle::factory()->create(['slug' => 'other-title']);
        $matching->countries()->attach($turkey);

        foreach (['country[]=turciia', 'country[0]=turciia'] as $query) {
            $this->getJson('/api/v1/titles?'.$query)
                ->assertOk()
                ->assertJsonCount(1, 'data')
                ->assertJsonPath('data.0.slug', $matching->slug)
                ->assertJsonMissing(['slug' => $other->slug]);
        }
    }

    public function test_v1_titles_apply_the_complete_validated_filter_contract(): void
    {
        $this->getJson('/api/v1/titles?'.http_build_query([
            'q' => 'API сериал',
            'year' => [2024],
            'year_from' => 2020,
            'year_to' => 2025,
            'seasons_min' => 1,
            'episodes_max' => 100,
            'rating_source' => 'imdb',
            'rating_min' => 7.5,
            'votes_min' => 100,
            'video' => 'available',
            'subtitles' => ['available'],
            'quality' => ['1080p'],
            'publication_type' => ['serial'],
            'updated' => 'month',
            'letter' => 'А',
            'sort' => 'year_desc',
            'per_page' => 20,
        ]))->assertOk()->assertJsonStructure(['data', 'links', 'meta']);
    }

    public function test_v1_title_search_excludes_description_only_matches(): void
    {
        $matching = CatalogTitle::factory()->create([
            'title' => 'Художник 2: Возвращение',
            'slug' => 'xudoznik-2-vozvrashhenie',
        ]);
        $noise = CatalogTitle::factory()->create([
            'title' => 'Посторонний сериал',
            'slug' => 'postoronnii-serial',
            'description' => 'Художник вернулся во втором сезоне 2.',
        ]);

        $this->getJson('/api/v1/titles?'.http_build_query(['q' => 'Художник 2']))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.slug', $matching->slug)
            ->assertJsonMissing(['slug' => $noise->slug]);
    }

    public function test_v1_title_list_rejects_invalid_bearer_and_personalizes_authenticated_audience(): void
    {
        $user = User::factory()->create();
        $authenticatedTitle = CatalogTitle::factory()->create([
            'slug' => 'authenticated-mobile-title',
            'audience' => ContentAudience::Authenticated,
        ]);

        $this->getJson('/api/v1/titles')
            ->assertOk()
            ->assertJsonMissing(['slug' => $authenticatedTitle->slug]);

        $this->withToken('invalid-token')
            ->getJson('/api/v1/titles')
            ->assertUnauthorized();

        $readToken = $user->createToken('iPhone', ['mobile:read'], now()->addDay());
        $this->withToken($readToken->plainTextToken)
            ->getJson('/api/v1/titles')
            ->assertOk()
            ->assertJsonFragment(['slug' => $authenticatedTitle->slug]);

        $writeOnlyToken = $user->createToken('Old device', ['mobile:write'], now()->addDay());
        $this->withToken($writeOnlyToken->plainTextToken)
            ->getJson('/api/v1/titles')
            ->assertForbidden();
    }

    public function test_v1_title_list_rejects_invalid_filter_contract_values(): void
    {
        $queries = [
            'page=0',
            'per_page=51',
            'year_from=2025&year_to=2020',
            'country[]=turciia&exclude_country[]=turciia',
            'sort=unknown',
            'letter=AB',
            'quality[]=ultra',
            http_build_query(['country' => array_map(
                static fn (int $index): string => 'country-'.$index,
                range(1, 21),
            )]),
        ];

        foreach ($queries as $query) {
            $this->getJson('/api/v1/titles?'.$query)
                ->assertUnprocessable()
                ->assertJsonPath('code', 'validation_failed');
        }
    }

    public function test_v1_title_list_query_count_is_constant_as_the_page_grows(): void
    {
        CatalogTitle::factory()->create(['slug' => 'budget-title-1']);
        $oneItemQueries = $this->captureQueries(
            fn () => $this->getJson('/api/v1/titles?per_page=20')->assertOk(),
        );

        foreach (range(2, 20) as $index) {
            CatalogTitle::factory()->create(['slug' => "budget-title-{$index}"]);
        }

        $twentyItemQueries = $this->captureQueries(
            fn () => $this->getJson('/api/v1/titles?per_page=20')->assertOk()->assertJsonCount(20, 'data'),
        );

        $this->assertLessThanOrEqual($oneItemQueries + 2, $twentyItemQueries);
    }

    private function captureQueries(callable $callback): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        try {
            $callback();

            return count(DB::getQueryLog());
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }
    }
}
