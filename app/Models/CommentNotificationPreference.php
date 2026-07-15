<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $user_id
 * @property bool $reply_notifications
 * @property bool $reaction_notifications
 * @property bool $moderation_notifications
 * @property bool $report_notifications
 */
#[Fillable([
    'user_id',
    'reply_notifications',
    'reaction_notifications',
    'moderation_notifications',
    'report_notifications',
])]
final class CommentNotificationPreference extends Model
{
    public $incrementing = false;

    protected $primaryKey = 'user_id';

    /** @var array<string, mixed> */
    protected $attributes = [
        'reply_notifications' => true,
        'reaction_notifications' => true,
        'moderation_notifications' => true,
        'report_notifications' => true,
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
            'reply_notifications' => 'boolean',
            'reaction_notifications' => 'boolean',
            'moderation_notifications' => 'boolean',
            'report_notifications' => 'boolean',
        ];
    }
}
