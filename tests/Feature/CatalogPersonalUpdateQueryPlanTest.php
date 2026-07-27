<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ReleaseScheduleEntryType;
use App\Enums\ReleaseScheduleStatus;
use App\Models\CatalogTitle;
use App\Models\ReleaseScheduleEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class CatalogPersonalUpdateQueryPlanTest extends TestCase
{
    use RefreshDatabase;

    public function test_release_lookup_index_matches_the_personal_home_update_predicate(): void
    {
        $title = CatalogTitle::factory()->create();
        ReleaseScheduleEntry::query()->create([
            'logical_key' => 'personal-home-update-index',
            'entry_type' => ReleaseScheduleEntryType::EpisodeRelease,
            'status' => ReleaseScheduleStatus::Released,
            'catalog_title_id' => $title->id,
            'released_at' => now(),
        ]);

        $index = collect(Schema::getIndexes('release_schedule_entries'))
            ->firstWhere('name', 'release_schedule_title_released_idx');

        $this->assertIsArray($index);
        $this->assertSame(
            ['catalog_title_id', 'status', 'released_at', 'id'],
            $index['columns'],
        );

        $plan = collect(DB::select(
            'EXPLAIN QUERY PLAN SELECT id FROM release_schedule_entries
             WHERE catalog_title_id = ? AND status = ? AND released_at IS NOT NULL
             AND released_at > ? ORDER BY id',
            [$title->id, ReleaseScheduleStatus::Released->value, now()->subDay()->toDateTimeString()],
        ))->pluck('detail')->implode("\n");

        $this->assertStringContainsString('release_schedule_title_released_idx', $plan);
    }
}
