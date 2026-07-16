<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property bool $requester_updates
 * @property bool $confirmer_updates
 * @property bool $follower_updates
 * @property bool $support_replies
 */
#[Fillable(['user_id', 'requester_updates', 'confirmer_updates', 'follower_updates', 'support_replies'])]
final class TechnicalIssueNotificationPreference extends Model
{
    protected $primaryKey = 'user_id';

    public $incrementing = false;

    protected $attributes = [
        'requester_updates' => true,
        'confirmer_updates' => true,
        'follower_updates' => true,
        'support_replies' => true,
    ];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'requester_updates' => 'boolean',
            'confirmer_updates' => 'boolean',
            'follower_updates' => 'boolean',
            'support_replies' => 'boolean',
        ];
    }
}
