<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\DTOs\ExternalMediaFileSizeResultData;
use App\Enums\MediaFileSizeCheckStatus;
use App\Models\LicensedMedia;
use App\Services\Media\LicensedMediaFileSizeBacklog;
use App\Services\Media\LicensedMediaFileSizeMetadataWriter;
use App\Services\Media\LicensedMediaFileSizeScheduleProjection;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class LicensedMediaFileSizeBacklogTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow('2026-07-26 12:00:00');
        config([
            'seasonvar.media_file_size.projection_enabled' => true,
            'seasonvar.media_file_size.known_ttl_seconds' => 3_600,
            'seasonvar.media_file_size.unknown_retry_seconds' => 1_800,
            'seasonvar.media_file_size.failed_retry_seconds' => 900,
            'seasonvar.media_file_size.status_stale_seconds' => 3_600,
            'playback.downloads.allowed_formats' => ['mp4', 'webm'],
        ]);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_projection_derives_eligibility_and_next_check_without_network_access(): void
    {
        $known = LicensedMedia::factory()->create([
            'path' => 'https://media.example.com/known.mp4',
            'playback_url' => null,
            'format' => 'mp4',
            'file_size_check_status' => MediaFileSizeCheckStatus::Known,
            'file_size_checked_at' => now()->subMinutes(10),
        ]);
        $hls = LicensedMedia::factory()->create([
            'path' => 'https://media.example.com/master.m3u8',
            'format' => 'hls',
            'file_size_check_status' => MediaFileSizeCheckStatus::Pending,
        ]);

        $known->refresh();
        $hls->refresh();

        $this->assertTrue($known->file_size_eligible);
        $this->assertSame(
            '2026-07-26 12:50:00',
            $known->file_size_next_check_at?->format('Y-m-d H:i:s'),
        );
        $this->assertFalse($hls->file_size_eligible);
        $this->assertNull($hls->file_size_next_check_at);
    }

    public function test_projection_reconciles_in_bounded_chunks_and_due_query_uses_composite_index(): void
    {
        config(['seasonvar.media_file_size.projection_enabled' => false]);

        LicensedMedia::factory()->count(3)->create([
            'path' => 'https://media.example.com/video.mp4',
            'format' => 'mp4',
            'file_size_check_status' => MediaFileSizeCheckStatus::Pending,
        ]);

        config(['seasonvar.media_file_size.projection_enabled' => true]);
        $projection = app(LicensedMediaFileSizeScheduleProjection::class);

        $this->assertFalse($projection->isReady());
        $this->assertSame(2, $projection->reconcileChunk(2));
        $this->assertFalse($projection->isReady());
        $this->assertSame(1, $projection->reconcileChunk(2));
        $this->assertTrue($projection->isReady());
        $this->assertSame(3, LicensedMedia::query()->where('file_size_eligible', true)->count());

        $query = app(LicensedMediaFileSizeBacklog::class)->query();
        $plan = collect(DB::select('EXPLAIN QUERY PLAN '.$query->toSql(), $query->getBindings()))
            ->map(static fn (object $row): string => implode(' ', array_map('strval', (array) $row)))
            ->implode(' ');

        $this->assertStringContainsString('licensed_media_file_size_schedule_idx', $plan);
    }

    public function test_status_prefers_durable_snapshot_and_reports_when_it_is_stale(): void
    {
        LicensedMedia::factory()->create([
            'path' => 'https://media.example.com/video.mp4',
            'format' => 'mp4',
            'file_size_bytes' => 1024,
            'file_size_check_status' => MediaFileSizeCheckStatus::Known,
            'file_size_checked_at' => now(),
        ]);

        $projection = app(LicensedMediaFileSizeScheduleProjection::class);
        $this->assertSame(1, $projection->reconcileChunk(10));
        $captured = $projection->captureStatus();
        $this->assertNotNull($captured);

        DB::table('licensed_media_file_size_state')->where('id', 1)->update([
            'snapshot_captured_at' => now()->subHours(2),
        ]);
        DB::table('licensed_media')->delete();

        DB::flushQueryLog();
        DB::enableQueryLog();
        $status = app(LicensedMediaFileSizeBacklog::class)->status();
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertSame(1, $status->eligible);
        $this->assertSame(1, $status->known);
        $this->assertSame(1024, $status->knownBytes);
        $this->assertTrue($status->isStale());
        $this->assertCount(1, $queries);
        $this->assertStringContainsString('licensed_media_file_size_state', $queries[0]['query']);
    }

    public function test_status_falls_back_to_legacy_aggregate_until_projection_is_ready(): void
    {
        LicensedMedia::factory()->create([
            'path' => 'https://media.example.com/video.mp4',
            'format' => 'mp4',
            'file_size_check_status' => MediaFileSizeCheckStatus::Pending,
        ]);

        $status = app(LicensedMediaFileSizeBacklog::class)->status();

        $this->assertSame(1, $status->eligible);
        $this->assertSame(1, $status->pending);
        $this->assertSame(1, $status->due);
    }

    public function test_compare_and_swap_metadata_write_updates_the_schedule_projection(): void
    {
        $media = LicensedMedia::factory()->create([
            'path' => 'https://media.example.com/video.mp4',
            'format' => 'mp4',
            'file_size_check_status' => MediaFileSizeCheckStatus::Pending,
        ]);
        $writer = app(LicensedMediaFileSizeMetadataWriter::class);
        $source = $writer->snapshot($media);

        $writer->writeIfSourceMatches(
            $media,
            $source,
            ExternalMediaFileSizeResultData::known(
                bytes: 2048,
                source: 'head',
                httpStatus: 200,
                checkedAt: now(),
            ),
        );

        $media->refresh();

        $this->assertSame(2048, $media->file_size_bytes);
        $this->assertSame(
            '2026-07-26 13:00:00',
            $media->file_size_next_check_at?->format('Y-m-d H:i:s'),
        );
    }
}
