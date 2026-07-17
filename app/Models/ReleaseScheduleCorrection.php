<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ReleaseDatePrecision;
use App\Enums\ReleaseScheduleSource;
use App\Enums\ReleaseScheduleStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'release_schedule_entry_id', 'actor_id', 'revision', 'previous_starts_at', 'new_starts_at',
    'previous_date_value', 'new_date_value', 'previous_date_end', 'new_date_end',
    'previous_release_year', 'new_release_year', 'previous_release_month', 'new_release_month',
    'previous_release_quarter', 'new_release_quarter', 'previous_timezone', 'new_timezone',
    'previous_precision', 'new_precision', 'previous_status', 'new_status', 'source', 'reason_code',
    'public_note', 'private_note',
])]
final class ReleaseScheduleCorrection extends Model
{
    public const UPDATED_AT = null;

    /** @return BelongsTo<ReleaseScheduleEntry, $this> */
    public function entry(): BelongsTo
    {
        return $this->belongsTo(ReleaseScheduleEntry::class, 'release_schedule_entry_id');
    }

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'revision' => 'integer',
            'previous_starts_at' => 'immutable_datetime',
            'new_starts_at' => 'immutable_datetime',
            'previous_date_value' => 'immutable_date',
            'new_date_value' => 'immutable_date',
            'previous_date_end' => 'immutable_date',
            'new_date_end' => 'immutable_date',
            'previous_release_year' => 'integer',
            'new_release_year' => 'integer',
            'previous_release_month' => 'integer',
            'new_release_month' => 'integer',
            'previous_release_quarter' => 'integer',
            'new_release_quarter' => 'integer',
            'previous_precision' => ReleaseDatePrecision::class,
            'new_precision' => ReleaseDatePrecision::class,
            'previous_status' => ReleaseScheduleStatus::class,
            'new_status' => ReleaseScheduleStatus::class,
            'source' => ReleaseScheduleSource::class,
        ];
    }
}
