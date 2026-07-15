<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $muter_id
 * @property int $muted_id
 */
#[Fillable(['muter_id', 'muted_id'])]
final class UserMute extends Model
{
    /** @return BelongsTo<User, $this> */
    public function muter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'muter_id');
    }

    /** @return BelongsTo<User, $this> */
    public function muted(): BelongsTo
    {
        return $this->belongsTo(User::class, 'muted_id');
    }
}
