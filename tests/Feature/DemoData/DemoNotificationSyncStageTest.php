<?php

declare(strict_types=1);

namespace Tests\Feature\DemoData;

use App\DTOs\DemoData\DemoDataOptions;
use App\Models\CatalogTitle;
use App\Models\Episode;
use App\Models\LicensedMedia;
use App\Models\Season;
use App\Models\Translation;
use App\Services\DemoData\Stages\DemoAccountStage;
use App\Services\DemoData\Stages\DemoCatalogActivityStage;
use App\Services\DemoData\Stages\DemoCommunityStage;
use App\Services\DemoData\Stages\DemoContentRequestStage;
use App\Services\DemoData\Stages\DemoNotificationSyncStage;
use App\Services\DemoData\Stages\DemoOrganizationStage;
use App\Services\DemoData\Stages\DemoTechnicalIssueStage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class DemoNotificationSyncStageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('uploads');
        config([
            'demo-data.user_count' => 6,
            'demo-data.coverage_numerator' => 1,
            'demo-data.coverage_denominator' => 2,
            'demo-data.chunk_size' => 100,
            'demo-data.asset_disk' => 'uploads',
            'demo-data.asset_prefix' => 'demo-tests',
            'demo-data.personal_tags.minimum' => 12,
            'demo-data.personal_tags.maximum' => 12,
            'demo-data.collections.minimum' => 8,
            'demo-data.collections.maximum' => 8,
            'demo-data.public_tag_target' => 12,
            'demo-data.requests.minimum' => 3,
            'demo-data.requests.maximum' => 3,
            'demo-data.issues.minimum' => 2,
            'demo-data.issues.maximum' => 2,
            'demo-data.notifications.minimum' => 20,
            'demo-data.notifications.maximum' => 20,
            'session.driver' => 'array',
        ]);
    }

    public function test_notification_sync_stage_creates_varied_inbox_and_sync_receipts_idempotently(): void
    {
        $translation = Translation::query()->create(['name' => 'Русская озвучка', 'slug' => 'russian-voice']);
        CatalogTitle::factory()->count(24)->create()->each(function (CatalogTitle $title) use ($translation): void {
            $title->translations()->attach($translation->id);
            $season = Season::factory()->create(['catalog_title_id' => $title->id, 'number' => 1]);
            $episode = Episode::factory()->create(['season_id' => $season->id, 'number' => 1]);
            LicensedMedia::factory()->create([
                'catalog_title_id' => $title->id,
                'season_id' => $season->id,
                'episode_id' => $episode->id,
                'status' => 'published',
                'published_at' => now()->subDay(),
                'duration_seconds' => 2_400,
            ]);
        });
        $options = DemoDataOptions::fromConfig();
        app(DemoAccountStage::class)->run($options);
        app(DemoOrganizationStage::class)->run($options);
        app(DemoCatalogActivityStage::class)->run($options);
        app(DemoCommunityStage::class)->run($options);
        app(DemoContentRequestStage::class)->run($options);
        app(DemoTechnicalIssueStage::class)->run($options);
        $stage = app(DemoNotificationSyncStage::class);
        $first = $stage->run($options);
        $counts = $this->notificationSyncCounts();
        $second = $stage->run($options);

        $this->assertSame('notifications_sync', $stage->key());
        $this->assertSame(120, $first->counters['notifications']);
        $this->assertSame(18, $first->counters['sync_applied']);
        $this->assertSame(6, $first->counters['sync_duplicate']);
        $this->assertSame(6, $first->counters['sync_conflict']);
        $this->assertSame(6, $first->counters['sync_rejected']);
        $this->assertSame(6, $first->counters['sync_not_found']);
        $this->assertSame($first->counters, $second->counters);
        $this->assertSame($counts, $this->notificationSyncCounts());

        $this->assertEqualsCanonicalizing([
            'comment.activity',
            'review.activity',
            'content-request.activity',
            'technical-issue.activity',
        ], DB::table('notifications')->distinct()->pluck('type')->all());
        $this->assertSame(120, DB::table('notifications')->count());
        $this->assertGreaterThan(0, DB::table('notifications')->whereNull('read_at')->count());
        $this->assertGreaterThan(0, DB::table('notifications')->whereNotNull('read_at')->count());
        $this->assertSame(30, DB::table('api_sync_mutations')->count());
        $this->assertEqualsCanonicalizing(
            ['applied', 'not_found', 'rejected'],
            DB::table('api_sync_mutations')->distinct()->pluck('status')->all(),
        );
        $this->assertSame(18, DB::table('api_sync_mutations')->where('status', 'applied')->count());
        $this->assertGreaterThanOrEqual(6, DB::table('api_sync_changes')->count());
    }

    /** @return array<string, int> */
    private function notificationSyncCounts(): array
    {
        return [
            'notifications' => DB::table('notifications')->count(),
            'sync_mutations' => DB::table('api_sync_mutations')->count(),
            'sync_changes' => DB::table('api_sync_changes')->count(),
        ];
    }
}
