<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\UserProfileReportCategory;
use App\Enums\UserProfileReportStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'public_id',
    'target_user_id',
    'target_public_id',
    'reporter_id',
    'category',
    'details',
    'status',
    'deduplication_key',
])]
final class UserProfileReport extends Model
{
    /** @return BelongsTo<User, $this> */
    public function target(): BelongsTo
    {
        return $this->belongsTo(User::class, 'target_user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reporter_id');
    }

    /** @return BelongsTo<User, $this> */
    public function moderator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'moderator_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'category' => UserProfileReportCategory::class,
            'status' => UserProfileReportStatus::class,
            'resolved_at' => 'datetime',
        ];
    }
}
