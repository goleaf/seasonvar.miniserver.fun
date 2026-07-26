<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\PlaybackQualitySession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class PlaybackQualityAdministrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_authorized_support_sees_bounded_quality_metrics_without_request_identifiers(): void
    {
        config(['seasonvar.admin_emails' => ['support@example.com']]);
        $admin = User::factory()->create(['email' => 'support@example.com']);
        $failed = PlaybackQualitySession::factory()->create([
            'browser_family' => 'firefox',
            'browser_major' => 141,
            'provider_code' => 'seasonvar_parsed',
            'error_provider_code' => 'seasonvar_parsed',
            'quality_code' => '1080p',
            'startup_time_ms' => 2000,
            'playback_time_ms' => 8000,
            'buffering_time_ms' => 2000,
            'playback_failed' => true,
            'fallback_attempted' => true,
            'fallback_succeeded' => false,
            'primary_failed' => true,
            'fallback_failed' => true,
            'error_type' => 'network',
            'error_source' => 'fallback',
        ]);
        PlaybackQualitySession::factory()->create([
            'startup_time_ms' => 1000,
            'playback_time_ms' => 9000,
            'buffering_time_ms' => 1000,
            'playback_failed' => false,
            'fallback_attempted' => true,
            'fallback_succeeded' => true,
            'primary_failed' => true,
        ]);

        $this->actingAs($admin)
            ->get(route('admin.issues', ['qualityPeriod' => 7]))
            ->assertOk()
            ->assertSeeText('Качество просмотра')
            ->assertSeeText('Среднее время запуска')
            ->assertSeeText('Доля буферизации')
            ->assertSeeText('Ошибки воспроизведения')
            ->assertSeeText('Успешный резервный источник')
            ->assertSeeText('firefox 141')
            ->assertSeeText('seasonvar_parsed')
            ->assertSeeText('1080p')
            ->assertDontSee($failed->request_id);
    }

    public function test_quality_period_is_normalized_to_the_allowlist(): void
    {
        config(['seasonvar.admin_emails' => ['support@example.com']]);
        $admin = User::factory()->create(['email' => 'support@example.com']);

        $this->actingAs($admin)
            ->get(route('admin.issues', ['qualityPeriod' => 365]))
            ->assertOk()
            ->assertSee('value="7"', false)
            ->assertDontSee('value="365"', false);
    }
}
