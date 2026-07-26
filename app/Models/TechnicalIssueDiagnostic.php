<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int|null $browser_major
 * @property int|null $viewport_width
 * @property int|null $viewport_height
 * @property bool|null $network_online
 */
#[Fillable([
    'technical_issue_id', 'authenticated_category', 'browser_family', 'browser_major',
    'operating_system', 'device_category', 'viewport_width', 'viewport_height',
    'timezone', 'network_online', 'player_component', 'source_health_code',
    'playback_request_id', 'playback_error_type', 'playback_error_source',
    'startup_time_ms', 'playback_time_ms', 'buffering_time_ms', 'buffering_count',
    'fallback_attempted', 'fallback_succeeded', 'primary_failed', 'fallback_failed',
    'network_test_status', 'network_latency_ms', 'video_variant_code',
    'video_quality_code', 'video_translation_name', 'video_format_code',
    'video_provider_code', 'hls_support',
])]
final class TechnicalIssueDiagnostic extends Model
{
    /** @return BelongsTo<TechnicalIssue, $this> */
    public function technicalIssue(): BelongsTo
    {
        return $this->belongsTo(TechnicalIssue::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'browser_major' => 'integer',
            'viewport_width' => 'integer',
            'viewport_height' => 'integer',
            'network_online' => 'boolean',
            'startup_time_ms' => 'integer',
            'playback_time_ms' => 'integer',
            'buffering_time_ms' => 'integer',
            'buffering_count' => 'integer',
            'fallback_attempted' => 'boolean',
            'fallback_succeeded' => 'boolean',
            'primary_failed' => 'boolean',
            'fallback_failed' => 'boolean',
            'network_latency_ms' => 'integer',
        ];
    }
}
