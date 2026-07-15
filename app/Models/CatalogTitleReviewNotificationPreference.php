<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'user_id',
    'helpful_notifications',
    'moderation_notifications',
    'report_notifications',
])]
final class CatalogTitleReviewNotificationPreference extends Model
{
    public $incrementing = false;

    protected $primaryKey = 'user_id';

    /** @var array<string, mixed> */
    protected $attributes = [
        'helpful_notifications' => true,
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
            'helpful_notifications' => 'boolean',
            'moderation_notifications' => 'boolean',
            'report_notifications' => 'boolean',
        ];
    }
}
