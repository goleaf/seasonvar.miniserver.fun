<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Actions\TechnicalIssues\CreateTechnicalIssue;
use App\DTOs\TechnicalIssues\TechnicalIssueInput;
use App\Enums\TechnicalIssueType;
use App\Models\CatalogTitle;
use App\Models\Episode;
use App\Models\LicensedMedia;
use App\Models\Season;
use App\Models\User;
use App\Services\Catalog\PlaybackQualityContext;
use App\Services\Catalog\PlaybackQualityRecorder;
use App\Services\TechnicalIssues\TechnicalIssueContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class PlaybackQualityTechnicalIssueTest extends TestCase
{
    use RefreshDatabase;

    public function test_consented_report_token_copies_a_server_verified_snapshot_into_the_ticket(): void
    {
        [$title, $season, $episode, $media] = $this->playbackFixture();
        $user = User::factory()->create();
        $capture = app(PlaybackQualityContext::class)->captureToken($title);
        $session = app(PlaybackQualityRecorder::class)->record([
            'context' => $capture,
            'request_id' => (string) Str::uuid(),
            'event' => 'report',
            'media_id' => $media->id,
            'browser_family' => 'chromium',
            'browser_major' => 140,
            'operating_system' => 'linux',
            'hls_support' => 'mse',
            'error_type' => 'network',
            'startup_time_ms' => 1800,
            'playback_time_ms' => 12000,
            'buffering_time_ms' => 3000,
            'buffering_count' => 4,
            'playback_position_seconds' => 12,
            'network_test_status' => 'ok',
            'network_latency_ms' => 85,
        ], $user);
        $reportToken = app(PlaybackQualityContext::class)->reportToken($session);
        $issueContext = $this->issueContext($title, $season, $episode, $media);

        $result = app(CreateTechnicalIssue::class)->handle($user, $this->input(
            context: $issueContext,
            reportToken: $reportToken,
            consent: true,
        ));

        $this->assertDatabaseHas('technical_issue_diagnostics', [
            'technical_issue_id' => $result->issue->id,
            'playback_request_id' => $session->request_id,
            'browser_family' => 'chromium',
            'browser_major' => 140,
            'operating_system' => 'linux',
            'hls_support' => 'mse',
            'playback_error_type' => 'network',
            'playback_error_source' => 'primary',
            'startup_time_ms' => 1800,
            'playback_time_ms' => 12000,
            'buffering_time_ms' => 3000,
            'buffering_count' => 4,
            'network_test_status' => 'ok',
            'network_latency_ms' => 85,
            'video_provider_code' => 'seasonvar_parsed',
            'video_variant_code' => 'professional',
            'video_quality_code' => '1080p',
            'video_translation_name' => 'Профессиональная',
            'video_format_code' => 'mp4',
        ]);

        $this->actingAs($user)
            ->get(route('localized.issues.show', [
                'locale' => 'ru',
                'technicalIssue' => $result->issue->public_id,
            ]))
            ->assertOk()
            ->assertSeeText('Request ID')
            ->assertSeeText($session->request_id)
            ->assertSeeText('Тип ошибки')
            ->assertSeeText('Сетевая ошибка')
            ->assertDontSee($media->path);
    }

    public function test_revoked_consent_and_cross_media_token_do_not_attach_playback_snapshot(): void
    {
        [$title, $season, $episode, $media] = $this->playbackFixture();
        [, , , $foreignMedia] = $this->playbackFixture();
        $user = User::factory()->create();
        $session = app(PlaybackQualityRecorder::class)->record([
            'context' => app(PlaybackQualityContext::class)->captureToken($title),
            'request_id' => (string) Str::uuid(),
            'event' => 'report',
            'media_id' => $media->id,
            'browser_family' => 'firefox',
            'browser_major' => 141,
            'operating_system' => 'windows',
            'hls_support' => 'mse',
            'error_type' => 'media',
            'startup_time_ms' => 2000,
            'playback_time_ms' => 1000,
            'buffering_time_ms' => 500,
            'buffering_count' => 1,
            'playback_position_seconds' => 1,
            'network_test_status' => 'timeout',
            'network_latency_ms' => 30000,
        ], $user);
        $reportToken = app(PlaybackQualityContext::class)->reportToken($session);

        $withoutConsent = app(CreateTechnicalIssue::class)->handle($user, $this->input(
            context: $this->issueContext($title, $season, $episode, $media),
            reportToken: $reportToken,
            consent: false,
        ));
        $crossMedia = app(CreateTechnicalIssue::class)->handle($user, $this->input(
            context: $this->issueContext($foreignMedia->catalogTitle, $foreignMedia->season, $foreignMedia->episode, $foreignMedia),
            reportToken: $reportToken,
            consent: true,
        ));

        $this->assertDatabaseMissing('technical_issue_diagnostics', [
            'technical_issue_id' => $withoutConsent->issue->id,
        ]);
        $this->assertDatabaseHas('technical_issue_diagnostics', [
            'technical_issue_id' => $crossMedia->issue->id,
            'playback_request_id' => null,
        ]);
    }

    public function test_report_form_previews_the_exact_safe_playback_snapshot_before_consent(): void
    {
        [$title, $season, $episode, $media] = $this->playbackFixture();
        $user = User::factory()->create();
        $session = app(PlaybackQualityRecorder::class)->record([
            'context' => app(PlaybackQualityContext::class)->captureToken($title),
            'request_id' => (string) Str::uuid(),
            'event' => 'report',
            'media_id' => $media->id,
            'browser_family' => 'firefox',
            'browser_major' => 141,
            'operating_system' => 'linux',
            'hls_support' => 'mse',
            'error_type' => 'network',
            'startup_time_ms' => 1800,
            'playback_time_ms' => 12000,
            'buffering_time_ms' => 3000,
            'buffering_count' => 4,
            'playback_position_seconds' => 12,
            'network_test_status' => 'ok',
            'network_latency_ms' => 85,
        ], $user);

        $this->actingAs($user)
            ->get(route('localized.issues.create', [
                'locale' => 'ru',
                'context' => $this->issueContext($title, $season, $episode, $media),
                'diagnostics' => app(PlaybackQualityContext::class)->reportToken($session),
                'type' => 'video_unavailable',
            ]))
            ->assertOk()
            ->assertSeeText('Диагностика воспроизведения')
            ->assertSeeText($session->request_id)
            ->assertSeeText('1080p')
            ->assertSeeText('Профессиональная')
            ->assertSeeText('1 800 мс')
            ->assertSeeText('firefox 141')
            ->assertSeeText('linux')
            ->assertSeeText('seasonvar_parsed')
            ->assertSeeText('Первичный источник')
            ->assertSeeText('12 000 мс')
            ->assertSeeText('3 000 мс')
            ->assertSeeText('Первичный источник не ответил')
            ->assertSee('&quot;diagnosticsConsent&quot;:true', false)
            ->assertDontSee($media->path);
    }

    private function input(string $context, string $reportToken, bool $consent): TechnicalIssueInput
    {
        return new TechnicalIssueInput(
            type: TechnicalIssueType::VideoUnavailable,
            contextToken: $context,
            featureCode: 'player',
            summary: null,
            expectedBehavior: null,
            actualBehavior: null,
            reproductionSteps: null,
            playbackPositionSeconds: null,
            audioLanguage: null,
            subtitleLanguage: null,
            qualityCode: null,
            publicErrorCode: null,
            diagnosticsConsent: $consent,
            browserFamily: null,
            browserMajor: null,
            operatingSystem: null,
            deviceCategory: null,
            viewportWidth: null,
            viewportHeight: null,
            timezone: null,
            networkOnline: null,
            playbackDiagnosticsToken: $reportToken,
            submissionToken: (string) Str::uuid(),
        );
    }

    private function issueContext(CatalogTitle $title, Season $season, Episode $episode, LicensedMedia $media): string
    {
        $url = app(TechnicalIssueContext::class)->playerUrl($title, $season, $episode, $media);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        return (string) ($query['context'] ?? '');
    }

    /** @return array{CatalogTitle, Season, Episode, LicensedMedia} */
    private function playbackFixture(): array
    {
        $title = CatalogTitle::factory()->create();
        $season = Season::factory()->for($title)->create(['number' => 1]);
        $episode = Episode::factory()->for($season)->create(['number' => 1]);
        $media = LicensedMedia::factory()->create([
            'catalog_title_id' => $title->id,
            'season_id' => $season->id,
            'episode_id' => $episode->id,
            'status' => 'published',
            'published_at' => now()->subMinute(),
            'storage_disk' => 'seasonvar_parsed',
            'path' => 'licensed/'.Str::uuid().'.mp4',
            'quality' => '1080p',
            'translation_name' => 'Профессиональная',
            'variant_type' => 'dub',
            'variant_key' => 'professional',
            'format' => 'mp4',
        ]);
        $media->setRelation('catalogTitle', $title);
        $media->setRelation('season', $season);
        $media->setRelation('episode', $episode);

        return [$title, $season, $episode, $media];
    }
}
