<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\CatalogTitle;
use App\Models\CatalogTitleUserState;
use App\Models\User;
use App\Services\Catalog\CatalogUserStateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class CatalogUserStateSummaryIndexTest extends TestCase
{
    use RefreshDatabase;

    public function test_title_summary_uses_the_covering_title_rating_index(): void
    {
        $title = CatalogTitle::factory()->create();
        $otherTitle = CatalogTitle::factory()->create();
        $users = User::factory()->count(3)->create();
        CatalogTitleUserState::query()->create([
            'user_id' => $users[0]->id,
            'catalog_title_id' => $title->id,
            'in_watchlist' => true,
            'rating' => 8,
        ]);
        CatalogTitleUserState::query()->create([
            'user_id' => $users[1]->id,
            'catalog_title_id' => $title->id,
            'in_watchlist' => false,
            'rating' => 6,
        ]);
        CatalogTitleUserState::query()->create([
            'user_id' => $users[2]->id,
            'catalog_title_id' => $otherTitle->id,
            'in_watchlist' => true,
            'rating' => 10,
        ]);

        $summary = app(CatalogUserStateService::class)->summary($title);
        $indexes = collect(Schema::getIndexes('catalog_title_user_states'))->keyBy('name');
        $plan = collect(DB::select(
            'EXPLAIN QUERY PLAN SELECT COUNT(CASE WHEN in_watchlist = 1 THEN 1 END), COUNT(rating), AVG(rating) FROM catalog_title_user_states WHERE catalog_title_id = ?',
            [$title->id],
        ))->pluck('detail')->implode(' ');

        $this->assertSame(1, $summary->watchlistCount);
        $this->assertSame(2, $summary->ratingCount);
        $this->assertSame(7.0, $summary->ratingAverage);
        $this->assertTrue($indexes->has('catalog_user_state_title_summary_idx'));
        $this->assertStringContainsString('catalog_user_state_title_summary_idx', $plan);
        $this->assertStringNotContainsString('SCAN catalog_title_user_states', $plan);
    }
}
