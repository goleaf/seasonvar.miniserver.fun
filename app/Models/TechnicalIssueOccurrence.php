<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int|null $browser_major
 * @property int|null $viewport_width
 * @property int|null $viewport_height
 * @property bool|null $network_online
 * @property int|null $playback_position_seconds
 * @property CarbonImmutable $occurred_at
 * @property CarbonImmutable|null $diagnostics_pruned_at
 */
#[Fillable([
    'technical_issue_id', 'user_id', 'browser_family', 'browser_major', 'operating_system',
    'device_category', 'viewport_width', 'viewport_height', 'timezone', 'network_online',
    'playback_position_seconds', 'public_error_code', 'source_health_code', 'occurred_at',
    'diagnostics_pruned_at',
])]
final class TechnicalIssueOccurrence extends Model
{
    /** @return BelongsTo<TechnicalIssue, $this> */
    public function technicalIssue(): BelongsTo
    {
        return $this->belongsTo(TechnicalIssue::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'browser_major' => 'integer',
            'viewport_width' => 'integer',
            'viewport_height' => 'integer',
            'network_online' => 'boolean',
            'playback_position_seconds' => 'integer',
            'occurred_at' => 'immutable_datetime',
            'diagnostics_pruned_at' => 'immutable_datetime',
        ];
    }
}
