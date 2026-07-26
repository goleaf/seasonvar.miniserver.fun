<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\PlaybackQualitySessionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
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
    'playback_position_seconds',
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
])]
final class PlaybackQualitySession extends Model
{
    /** @use HasFactory<PlaybackQualitySessionFactory> */
    use HasFactory;

    /** @return BelongsTo<CatalogTitle, $this> */
    public function catalogTitle(): BelongsTo
    {
        return $this->belongsTo(CatalogTitle::class);
    }

    /** @return BelongsTo<LicensedMedia, $this> */
    public function initialMedia(): BelongsTo
    {
        return $this->belongsTo(LicensedMedia::class, 'initial_media_id');
    }

    /** @return BelongsTo<LicensedMedia, $this> */
    public function currentMedia(): BelongsTo
    {
        return $this->belongsTo(LicensedMedia::class, 'current_media_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'browser_major' => 'integer',
            'startup_time_ms' => 'integer',
            'playback_time_ms' => 'integer',
            'buffering_time_ms' => 'integer',
            'buffering_count' => 'integer',
            'playback_position_seconds' => 'integer',
            'reached_playback' => 'boolean',
            'playback_failed' => 'boolean',
            'fallback_attempted' => 'boolean',
            'fallback_succeeded' => 'boolean',
            'primary_failed' => 'boolean',
            'fallback_failed' => 'boolean',
            'network_latency_ms' => 'integer',
            'started_at' => 'datetime',
            'last_event_at' => 'datetime',
            'finalized_at' => 'datetime',
        ];
    }
}
