<?php

declare(strict_types=1);

namespace Tests\Feature\DemoData;

use App\DTOs\DemoData\DemoDataOptions;
use App\Enums\ContentRequestExternalProvider;
use App\Enums\ContentRequestPriority;
use App\Enums\ContentRequestStatus;
use App\Enums\ContentRequestType;
use App\Models\CatalogTitle;
use App\Models\ContentRequest;
use App\Models\Episode;
use App\Models\LicensedMedia;
use App\Models\Season;
use App\Services\DemoData\Stages\DemoAccountStage;
use App\Services\DemoData\Stages\DemoContentRequestStage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class DemoContentRequestStageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('uploads');
        config([
            'demo-data.user_count' => 4,
            'demo-data.coverage_numerator' => 1,
            'demo-data.coverage_denominator' => 2,
            'demo-data.chunk_size' => 100,
            'demo-data.asset_disk' => 'uploads',
            'demo-data.asset_prefix' => 'demo-tests',
            'demo-data.requests.minimum' => 10,
            'demo-data.requests.maximum' => 10,
            'session.driver' => 'array',
        ]);
    }

    public function test_content_request_stage_fills_every_workflow_surface_without_duplicates(): void
    {
        CatalogTitle::factory()->count(24)->create()->each(function (CatalogTitle $title): void {
            $season = Season::factory()->create(['catalog_title_id' => $title->id, 'number' => 1]);
            $episode = Episode::factory()->create(['season_id' => $season->id, 'number' => 1]);
            LicensedMedia::factory()->create([
                'catalog_title_id' => $title->id,
                'season_id' => $season->id,
                'episode_id' => $episode->id,
                'status' => 'published',
                'published_at' => now()->subDay(),
            ]);
        });
        $options = DemoDataOptions::fromConfig();
        app(DemoAccountStage::class)->run($options);
        $stage = app(DemoContentRequestStage::class);
        $first = $stage->run($options);
        $counts = $this->workflowCounts();
        $second = $stage->run($options);

        $this->assertSame('content_requests', $stage->key());
        $this->assertSame(40, $first->counters['requests']);
        $this->assertSame($first->counters, $second->counters);
        $this->assertSame($counts, $this->workflowCounts());

        $requests = ContentRequest::query()->get();

        $this->assertCount(40, $requests);
        $this->assertSame(40, $requests->pluck('public_id')->unique()->count());
        $this->assertSame(40, $requests->pluck('submission_key')->unique()->count());
        $this->assertSame(40, $requests->pluck('title')->unique()->count());
        $this->assertEqualsCanonicalizing(
            array_column(ContentRequestType::cases(), 'value'),
            $requests->pluck('type')->map->value->unique()->values()->all(),
        );
        $this->assertEqualsCanonicalizing(
            array_column(ContentRequestStatus::cases(), 'value'),
            $requests->pluck('status')->map->value->unique()->values()->all(),
        );
        $this->assertEqualsCanonicalizing(
            array_column(ContentRequestPriority::cases(), 'value'),
            $requests->pluck('priority')->map->value->unique()->values()->all(),
        );
        $this->assertEqualsCanonicalizing(
            array_column(ContentRequestExternalProvider::cases(), 'value'),
            DB::table('content_request_external_identifiers')->distinct()->pluck('provider')->all(),
        );

        foreach ($requests->groupBy('requester_id') as $userRequests) {
            $this->assertCount(10, $userRequests);
        }

        foreach ($requests as $request) {
            $this->assertGreaterThanOrEqual(1, $request->sourceLinks()->count());
            $this->assertLessThanOrEqual(3, $request->sourceLinks()->count());
            $this->assertGreaterThanOrEqual(1, $request->externalIdentifiers()->count());
            $this->assertGreaterThanOrEqual(1, $request->statusHistory()->count());
            $this->assertGreaterThanOrEqual(2, $request->clarifications()->count());
            $this->assertGreaterThanOrEqual(1, $request->votes()->count());
            $this->assertGreaterThanOrEqual(1, $request->followers()->count());
        }

        $this->assertFalse(DB::table('content_request_votes')
            ->select(['content_request_id', 'user_id'])
            ->groupBy(['content_request_id', 'user_id'])
            ->havingRaw('count(*) > 1')
            ->exists());
        $this->assertFalse(DB::table('content_request_followers')
            ->select(['content_request_id', 'user_id'])
            ->groupBy(['content_request_id', 'user_id'])
            ->havingRaw('count(*) > 1')
            ->exists());
    }

    /** @return array<string, int> */
    private function workflowCounts(): array
    {
        return [
            'requests' => DB::table('content_requests')->count(),
            'votes' => DB::table('content_request_votes')->count(),
            'followers' => DB::table('content_request_followers')->count(),
            'histories' => DB::table('content_request_status_histories')->count(),
            'links' => DB::table('content_request_source_links')->count(),
            'identifiers' => DB::table('content_request_external_identifiers')->count(),
            'clarifications' => DB::table('content_request_clarifications')->count(),
        ];
    }
}
