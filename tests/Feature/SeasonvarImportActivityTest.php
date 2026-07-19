<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\SeasonvarImportStatus;
use App\Models\SeasonvarImportRun;
use App\Services\Seasonvar\SeasonvarImportActivity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class SeasonvarImportActivityTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_pipeline_statuses_are_considered_active(): void
    {
        $activity = app(SeasonvarImportActivity::class);

        $this->assertFalse($activity->active());

        foreach ([SeasonvarImportStatus::Queued, SeasonvarImportStatus::Running] as $status) {
            SeasonvarImportRun::query()->create(['mode' => 'all', 'status' => $status->value]);
            $this->assertTrue($activity->active(), $status->value);
            SeasonvarImportRun::query()->delete();
        }

        SeasonvarImportRun::query()->create([
            'mode' => 'all',
            'status' => SeasonvarImportStatus::Completed->value,
        ]);
        $this->assertFalse($activity->active());
    }
}
