<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\CatalogTitle;
use App\Models\Episode;
use App\Models\LicensedMedia;
use App\Models\PlaybackQualitySession;
use App\Models\Season;
use App\Models\User;
use App\Services\Catalog\PlaybackQualityContext;
use App\Services\Catalog\PlaybackQualityMetricsQuery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

final class PlaybackQualityTelemetryTest extends TestCase
{
    use RefreshDatabase;

    public function test_additive_schema_contains_private_session_and_ticket_snapshot_contracts(): void
    {
        $this->assertTrue(Schema::hasColumns('playback_quality_sessions', [
            'request_id',
            'catalog_title_id',
            'season_id',
            'episode_id',
            'initial_media_id',
            'current_media_id',
            'provider_code',
            'error_provider_code',
            'variant_code',
            'quality_code',
            'translation_name',
            'format_code',
            'browser_family',
            'browser_major',
            'operating_system',
            'hls_support',
            'error_type',
            'error_source',
            'startup_time_ms',
            'playback_time_ms',
            'buffering_time_ms',
            'buffering_count',
            'reached_playback',
            'playback_failed',
            'fallback_attempted',
            'fallback_succeeded',
            'primary_failed',
            'fallback_failed',
            'network_test_status',
            'network_latency_ms',
            'started_at',
            'last_event_at',
            'finalized_at',
        ]));
        $this->assertFalse(Schema::hasColumn('playback_quality_sessions', 'user_id'));
        $this->assertFalse(Schema::hasColumn('playback_quality_sessions', 'ip_address'));
        $this->assertFalse(Schema::hasColumn('playback_quality_sessions', 'user_agent'));
        $this->assertTrue(Schema::hasColumns('technical_issue_diagnostics', [
            'playback_request_id',
            'playback_error_type',
            'playback_error_source',
            'startup_time_ms',
            'playback_time_ms',
            'buffering_time_ms',
            'buffering_count',
            'fallback_attempted',
            'fallback_succeeded',
            'primary_failed',
            'fallback_failed',
            'network_test_status',
            'network_latency_ms',
            'video_variant_code',
            'video_quality_code',
            'video_translation_name',
            'video_format_code',
            'video_provider_code',
            'hls_support',
        ]));
        $this->assertContains('playback_quality_retention_idx', $this->indexNames('playback_quality_sessions'));
        $this->assertContains('playback_quality_browser_errors_idx', $this->indexNames('playback_quality_sessions'));
        $this->assertContains('playback_quality_provider_errors_idx', $this->indexNames('playback_quality_sessions'));
        $this->assertContains('playback_quality_quality_errors_idx', $this->indexNames('playback_quality_sessions'));
    }

    public function test_guest_can_record_bounded_telemetry_with_server_owned_media_metadata(): void
    {
        [$title, $season, $episode, $media] = $this->playbackFixture();
        $requestId = (string) Str::uuid();
        $token = app(PlaybackQualityContext::class)->captureToken($title);

        $this->postJson(route('playback.quality.store'), [
            'context' => $token,
            'request_id' => $requestId,
            'event' => 'ready',
            'media_id' => $media->id,
            'browser_family' => 'chromium',
            'browser_major' => 140,
            'operating_system' => 'linux',
            'hls_support' => 'mse',
            'startup_time_ms' => 1250,
            'playback_time_ms' => 4000,
            'buffering_time_ms' => 500,
            'buffering_count' => 2,
            'playback_position_seconds' => 4,
            'provider_code' => 'forged-provider',
            'source_url' => 'https://attacker.invalid/private.mp4',
            'user_id' => 999,
        ])->assertAccepted()
            ->assertHeader('Cache-Control', 'max-age=0, no-store, private')
            ->assertJsonPath('request_id', $requestId);

        $this->assertDatabaseHas('playback_quality_sessions', [
            'request_id' => $requestId,
            'catalog_title_id' => $title->id,
            'season_id' => $season->id,
            'episode_id' => $episode->id,
            'initial_media_id' => $media->id,
            'current_media_id' => $media->id,
            'provider_code' => 'seasonvar_parsed',
            'quality_code' => '1080p',
            'translation_name' => 'Профессиональная',
            'format_code' => 'mp4',
            'browser_family' => 'chromium',
            'browser_major' => 140,
            'operating_system' => 'linux',
            'hls_support' => 'mse',
            'startup_time_ms' => 1250,
            'playback_time_ms' => 4000,
            'buffering_time_ms' => 500,
            'buffering_count' => 2,
            'reached_playback' => true,
        ]);
    }

    public function test_updates_are_monotonic_and_fallback_outcome_is_derived_from_server_media(): void
    {
        [$title, $season, $episode, $primary] = $this->playbackFixture();
        $fallback = LicensedMedia::factory()->create([
            'catalog_title_id' => $title->id,
            'season_id' => $season->id,
            'episode_id' => $episode->id,
            'status' => 'published',
            'published_at' => now()->subMinute(),
            'storage_disk' => 'backup_provider',
            'path' => 'licensed/fallback.mp4',
            'quality' => '720p',
            'translation_name' => 'Субтитры',
            'variant_type' => 'subtitle',
            'variant_key' => 'subtitles',
            'format' => 'mp4',
            'has_subtitles' => true,
        ]);
        $requestId = (string) Str::uuid();
        $context = app(PlaybackQualityContext::class)->captureToken($title);

        $this->postSample($context, $requestId, $primary->id, [
            'event' => 'error',
            'error_type' => 'network',
            'startup_time_ms' => null,
            'playback_time_ms' => 5000,
            'buffering_time_ms' => 900,
            'buffering_count' => 3,
        ])->assertAccepted();
        $this->postSample($context, $requestId, $fallback->id, [
            'event' => 'ready',
            'startup_time_ms' => 1400,
            'playback_time_ms' => 4500,
            'buffering_time_ms' => 300,
            'buffering_count' => 1,
        ])->assertAccepted();

        $this->assertDatabaseHas('playback_quality_sessions', [
            'request_id' => $requestId,
            'initial_media_id' => $primary->id,
            'current_media_id' => $fallback->id,
            'error_provider_code' => 'seasonvar_parsed',
            'provider_code' => 'backup_provider',
            'error_type' => 'network',
            'error_source' => 'primary',
            'startup_time_ms' => 1400,
            'playback_time_ms' => 5000,
            'buffering_time_ms' => 900,
            'buffering_count' => 3,
            'primary_failed' => true,
            'fallback_attempted' => true,
            'fallback_succeeded' => true,
            'fallback_failed' => false,
            'playback_failed' => false,
        ]);
        $this->assertSame(1, DB::table('playback_quality_sessions')->where('request_id', $requestId)->count());
        $this->assertDatabaseHas('licensed_media', [
            'id' => $primary->id,
            'health_status' => 'active',
        ]);
    }

    public function test_invalid_context_cross_title_media_and_unbounded_values_are_rejected(): void
    {
        [$title, , , $media] = $this->playbackFixture();
        [, , , $foreignMedia] = $this->playbackFixture();
        $context = app(PlaybackQualityContext::class)->captureToken($title);

        $this->postSample('invalid-token', (string) Str::uuid(), $media->id)
            ->assertUnprocessable();
        $this->postSample($context, (string) Str::uuid(), $foreignMedia->id)
            ->assertUnprocessable();
        $this->postSample($context, (string) Str::uuid(), $media->id, [
            'startup_time_ms' => 300001,
            'buffering_count' => 65536,
            'error_type' => 'raw-provider-stack-trace',
        ])->assertUnprocessable();

        $this->assertDatabaseCount('playback_quality_sessions', 0);
    }

    public function test_network_probe_is_fixed_no_store_and_accepts_no_target_url(): void
    {
        $this->getJson(route('playback.quality.network', ['url' => 'https://attacker.invalid/']))
            ->assertNoContent()
            ->assertHeader('Cache-Control', 'max-age=0, no-store, private')
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive')
            ->assertHeader('Referrer-Policy', 'no-referrer');
    }

    public function test_authenticated_report_receives_only_a_same_portal_issue_url_with_an_expiring_snapshot_token(): void
    {
        [$title, , , $media] = $this->playbackFixture();
        $user = User::factory()->create();
        $requestId = (string) Str::uuid();

        $response = $this->actingAs($user)->postSample(
            app(PlaybackQualityContext::class)->captureToken($title),
            $requestId,
            $media->id,
            [
                'event' => 'report',
                'error_type' => 'network',
                'network_test_status' => 'ok',
                'network_latency_ms' => 75,
            ],
        )->assertAccepted()
            ->assertJsonPath('request_id', $requestId)
            ->assertJsonStructure(['issue_url']);

        $issueUrl = (string) $response->json('issue_url');

        $this->assertSame(config('app.url'), parse_url($issueUrl, PHP_URL_SCHEME).'://'.parse_url($issueUrl, PHP_URL_HOST));
        $this->assertStringContainsString('/issues/new?', $issueUrl);
        $this->assertStringContainsString('diagnostics=', $issueUrl);
        $this->assertStringContainsString('type=video_unavailable', $issueUrl);
        $this->assertStringNotContainsString($media->path, $issueUrl);
    }

    public function test_metrics_query_returns_exact_bounded_formulas_and_error_breakdowns(): void
    {
        [$title, $season, $episode, $media] = $this->playbackFixture();
        $now = now();
        $base = [
            'catalog_title_id' => $title->id,
            'season_id' => $season->id,
            'episode_id' => $episode->id,
            'initial_media_id' => $media->id,
            'current_media_id' => $media->id,
            'provider_code' => 'seasonvar_parsed',
            'variant_code' => 'dub',
            'quality_code' => '1080p',
            'translation_name' => 'Профессиональная',
            'format_code' => 'mp4',
            'browser_family' => 'chromium',
            'browser_major' => 140,
            'operating_system' => 'linux',
            'hls_support' => 'mse',
            'error_type' => null,
            'error_source' => null,
            'error_provider_code' => null,
            'started_at' => $now,
            'last_event_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ];

        DB::table('playback_quality_sessions')->insert([
            [...$base, 'request_id' => (string) Str::uuid(), 'startup_time_ms' => 1000, 'playback_time_ms' => 9000, 'buffering_time_ms' => 1000, 'buffering_count' => 1, 'reached_playback' => true, 'playback_failed' => false, 'fallback_attempted' => true, 'fallback_succeeded' => true, 'primary_failed' => true, 'fallback_failed' => false],
            [...$base, 'request_id' => (string) Str::uuid(), 'startup_time_ms' => 3000, 'playback_time_ms' => 8000, 'buffering_time_ms' => 2000, 'buffering_count' => 2, 'reached_playback' => true, 'playback_failed' => true, 'fallback_attempted' => true, 'fallback_succeeded' => false, 'primary_failed' => true, 'fallback_failed' => true, 'error_type' => 'network', 'error_source' => 'fallback', 'error_provider_code' => 'seasonvar_parsed'],
        ]);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $metrics = app(PlaybackQualityMetricsQuery::class)->summary(7);
        $queryCount = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertSame(2, $metrics['overview']['sessions']);
        $this->assertSame(2000, $metrics['overview']['average_startup_time_ms']);
        $this->assertSame(15.0, $metrics['overview']['rebuffer_ratio_percent']);
        $this->assertSame(50.0, $metrics['overview']['playback_error_rate_percent']);
        $this->assertSame(50.0, $metrics['overview']['fallback_success_rate_percent']);
        $this->assertSame('chromium 140', $metrics['errors_by_browser'][0]['label']);
        $this->assertSame('seasonvar_parsed', $metrics['errors_by_provider'][0]['label']);
        $this->assertSame('1080p', $metrics['errors_by_quality'][0]['label']);
        $this->assertSame(4, $queryCount);
    }

    public function test_sqlite_query_plans_use_the_telemetry_retention_and_error_indexes(): void
    {
        $cutoff = now()->subDays(7)->toDateTimeString();
        $plans = [
            'playback_quality_retention_idx' => DB::select(
                'EXPLAIN QUERY PLAN SELECT id FROM playback_quality_sessions WHERE started_at <= ? ORDER BY started_at, id LIMIT 200',
                [$cutoff],
            ),
            'playback_quality_browser_errors_idx' => DB::select(
                'EXPLAIN QUERY PLAN SELECT browser_family, browser_major, COUNT(*) FROM playback_quality_sessions WHERE playback_failed = 1 AND started_at >= ? AND browser_family IS NOT NULL GROUP BY browser_family, browser_major LIMIT 10',
                [$cutoff],
            ),
            'playback_quality_provider_errors_idx' => DB::select(
                'EXPLAIN QUERY PLAN SELECT error_provider_code, COUNT(*) FROM playback_quality_sessions WHERE playback_failed = 1 AND started_at >= ? AND error_provider_code IS NOT NULL GROUP BY error_provider_code LIMIT 10',
                [$cutoff],
            ),
            'playback_quality_quality_errors_idx' => DB::select(
                'EXPLAIN QUERY PLAN SELECT quality_code, COUNT(*) FROM playback_quality_sessions WHERE playback_failed = 1 AND started_at >= ? AND quality_code IS NOT NULL GROUP BY quality_code LIMIT 10',
                [$cutoff],
            ),
        ];

        foreach ($plans as $index => $rows) {
            $details = collect($rows)
                ->map(fn (object $row): string => (string) $row->detail)
                ->implode(' ');

            $this->assertStringContainsString($index, $details);
        }
    }

    public function test_existing_private_data_command_prunes_expired_playback_sessions_in_bounded_batches(): void
    {
        config(['playback.quality.retention_days' => 90]);
        $expired = PlaybackQualitySession::factory()->create([
            'started_at' => now()->subDays(91),
            'last_event_at' => now()->subDays(91),
        ]);
        $secondExpired = PlaybackQualitySession::factory()->create([
            'started_at' => now()->subDays(92),
            'last_event_at' => now()->subDays(92),
        ]);
        $recent = PlaybackQualitySession::factory()->create([
            'started_at' => now()->subDay(),
            'last_event_at' => now()->subDay(),
        ]);

        $this->artisan('technical-issues:prune-private-data', ['--limit' => 1])
            ->expectsOutputToContain('Playback telemetry deleted: 1.')
            ->assertSuccessful();

        $this->assertDatabaseMissing('playback_quality_sessions', ['id' => $secondExpired->id]);
        $this->assertDatabaseHas('playback_quality_sessions', ['id' => $expired->id]);
        $this->assertDatabaseHas('playback_quality_sessions', ['id' => $recent->id]);
    }

    /** @return array{CatalogTitle, Season, Episode, LicensedMedia} */
    private function playbackFixture(): array
    {
        $title = CatalogTitle::factory()->create();
        $season = Season::factory()->create([
            'catalog_title_id' => $title->id,
            'number' => 1,
        ]);
        $episode = Episode::factory()->create([
            'season_id' => $season->id,
            'number' => 1,
        ]);
        $media = LicensedMedia::factory()->create([
            'catalog_title_id' => $title->id,
            'season_id' => $season->id,
            'episode_id' => $episode->id,
            'status' => 'published',
            'published_at' => now()->subMinute(),
            'storage_disk' => 'seasonvar_parsed',
            'path' => 'licensed/source.mp4',
            'quality' => '1080p',
            'translation_name' => 'Профессиональная',
            'variant_type' => 'dub',
            'variant_key' => 'professional',
            'format' => 'mp4',
        ]);

        return [$title, $season, $episode, $media];
    }

    /** @return list<string> */
    private function indexNames(string $table): array
    {
        return collect(DB::select("PRAGMA index_list('{$table}')"))
            ->pluck('name')
            ->filter(fn (mixed $name): bool => is_string($name))
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return TestResponse<Response>
     */
    private function postSample(string $context, string $requestId, int $mediaId, array $overrides = []): TestResponse
    {
        return $this->postJson(route('playback.quality.store'), [
            'context' => $context,
            'request_id' => $requestId,
            'event' => 'ready',
            'media_id' => $mediaId,
            'browser_family' => 'chromium',
            'browser_major' => 140,
            'operating_system' => 'linux',
            'hls_support' => 'mse',
            'startup_time_ms' => 1200,
            'playback_time_ms' => 1000,
            'buffering_time_ms' => 0,
            'buffering_count' => 0,
            'playback_position_seconds' => 1,
            ...$overrides,
        ]);
    }
}
