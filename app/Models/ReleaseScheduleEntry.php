<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ReleaseDatePrecision;
use App\Enums\ReleaseScheduleEntryType;
use App\Enums\ReleaseScheduleSource;
use App\Enums\ReleaseScheduleStatus;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property string $public_id
 * @property string $logical_key
 * @property ReleaseScheduleEntryType $entry_type
 * @property ReleaseScheduleStatus $status
 * @property ReleaseDatePrecision $precision
 * @property ReleaseScheduleSource $source
 * @property CarbonInterface|null $starts_at
 * @property CarbonInterface|null $date_value
 * @property CarbonInterface|null $date_end
 * @property CarbonInterface|null $released_at
 */
#[Fillable([
    'public_id', 'logical_key', 'entry_type', 'status', 'precision', 'source', 'source_reference',
    'catalog_title_id', 'season_id', 'episode_id', 'licensed_media_id', 'season_number', 'episode_number',
    'language_code', 'translation_name', 'starts_at', 'date_value', 'date_end', 'release_year',
    'release_month', 'release_quarter', 'original_timezone',
    'is_estimated', 'is_locked', 'is_public', 'notifications_enabled', 'revision', 'released_at',
])]
final class ReleaseScheduleEntry extends Model
{
    protected $attributes = [
        'status' => 'scheduled',
        'precision' => 'unknown',
        'source' => 'unknown',
        'original_timezone' => 'UTC',
        'is_estimated' => false,
        'is_locked' => false,
        'is_public' => true,
        'notifications_enabled' => true,
        'revision' => 1,
    ];

    protected static function booted(): void
    {
        self::creating(function (self $entry): void {
            $entry->public_id = $entry->public_id ?: (string) Str::uuid();
        });
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    /** @return BelongsTo<CatalogTitle, $this> */
    public function catalogTitle(): BelongsTo
    {
        return $this->belongsTo(CatalogTitle::class);
    }

    /** @return BelongsTo<Season, $this> */
    public function season(): BelongsTo
    {
        return $this->belongsTo(Season::class);
    }

    /** @return BelongsTo<Episode, $this> */
    public function episode(): BelongsTo
    {
        return $this->belongsTo(Episode::class);
    }

    /** @return BelongsTo<LicensedMedia, $this> */
    public function licensedMedia(): BelongsTo
    {
        return $this->belongsTo(LicensedMedia::class);
    }

    /** @return HasMany<ReleaseScheduleCorrection, $this> */
    public function corrections(): HasMany
    {
        return $this->hasMany(ReleaseScheduleCorrection::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'entry_type' => ReleaseScheduleEntryType::class,
            'status' => ReleaseScheduleStatus::class,
            'precision' => ReleaseDatePrecision::class,
            'source' => ReleaseScheduleSource::class,
            'starts_at' => 'immutable_datetime',
            'date_value' => 'immutable_date',
            'date_end' => 'immutable_date',
            'release_year' => 'integer',
            'release_month' => 'integer',
            'release_quarter' => 'integer',
            'is_estimated' => 'boolean',
            'is_locked' => 'boolean',
            'is_public' => 'boolean',
            'notifications_enabled' => 'boolean',
            'revision' => 'integer',
            'released_at' => 'immutable_datetime',
        ];
    }
}
