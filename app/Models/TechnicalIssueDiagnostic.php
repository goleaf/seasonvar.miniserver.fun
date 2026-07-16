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
        ];
    }
}
