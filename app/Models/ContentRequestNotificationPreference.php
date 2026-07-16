<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['user_id', 'requester_updates', 'voted_updates', 'followed_updates'])]
final class ContentRequestNotificationPreference extends Model
{
    protected $primaryKey = 'user_id';

    public $incrementing = false;

    protected $attributes = ['requester_updates' => true, 'voted_updates' => true, 'followed_updates' => true];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    protected function casts(): array
    {
        return ['requester_updates' => 'boolean', 'voted_updates' => 'boolean', 'followed_updates' => 'boolean'];
    }
}
