<?php

declare(strict_types=1);

namespace Tests\Feature;

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

        foreach (['queued', 'discovering', 'running', 'finalizing'] as $status) {
            SeasonvarImportRun::query()->create(['mode' => 'all', 'status' => $status]);
            $this->assertTrue($activity->active(), $status);
            SeasonvarImportRun::query()->delete();
        }

        SeasonvarImportRun::query()->create(['mode' => 'all', 'status' => 'completed']);
        $this->assertFalse($activity->active());
    }
}
