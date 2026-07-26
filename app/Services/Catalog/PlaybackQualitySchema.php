<?php

declare(strict_types=1);

namespace App\Services\Catalog;

use Illuminate\Support\Facades\Schema;
use Throwable;

final class PlaybackQualitySchema
{
    private ?bool $ready = null;

    public function ready(): bool
    {
        return $this->ready ??= $this->check();
    }

    private function check(): bool
    {
        try {
            return Schema::hasColumns('playback_quality_sessions', [
                'request_id',
                'catalog_title_id',
                'current_media_id',
                'browser_family',
                'startup_time_ms',
                'playback_time_ms',
                'buffering_time_ms',
                'playback_failed',
                'fallback_attempted',
                'fallback_succeeded',
                'started_at',
                'last_event_at',
            ]) && Schema::hasColumns('technical_issue_diagnostics', [
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
            ]);
        } catch (Throwable) {
            return false;
        }
    }
}
