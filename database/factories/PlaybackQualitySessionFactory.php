<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\PlaybackQualitySession;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<PlaybackQualitySession> */
final class PlaybackQualitySessionFactory extends Factory
{
    protected $model = PlaybackQualitySession::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'request_id' => (string) Str::uuid(),
            'browser_family' => 'other',
            'operating_system' => 'other',
            'hls_support' => 'unsupported',
            'playback_time_ms' => 0,
            'buffering_time_ms' => 0,
            'buffering_count' => 0,
            'playback_position_seconds' => 0,
            'reached_playback' => false,
            'playback_failed' => false,
            'fallback_attempted' => false,
            'fallback_succeeded' => false,
            'primary_failed' => false,
            'fallback_failed' => false,
            'started_at' => now(),
            'last_event_at' => now(),
        ];
    }
}
