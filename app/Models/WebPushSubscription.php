<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * @property string $public_id
 * @property string $endpoint
 * @property string $endpoint_hash
 * @property string $installation_hash
 * @property string $locale
 * @property int $failure_count
 * @property CarbonInterface|null $last_success_at
 * @property CarbonInterface|null $last_failure_at
 * @property CarbonInterface|null $disabled_at
 */
#[Fillable([
    'public_id',
    'user_id',
    'endpoint',
    'endpoint_hash',
    'installation_hash',
    'locale',
    'failure_count',
    'last_success_at',
    'last_failure_at',
    'disabled_at',
])]
final class WebPushSubscription extends Model
{
    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    protected static function booted(): void
    {
        self::creating(static function (self $subscription): void {
            if (! is_string($subscription->public_id) || $subscription->public_id === '') {
                $subscription->public_id = (string) Str::uuid();
            }
        });
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'endpoint' => 'encrypted',
            'failure_count' => 'integer',
            'last_success_at' => 'immutable_datetime',
            'last_failure_at' => 'immutable_datetime',
            'disabled_at' => 'immutable_datetime',
        ];
    }
}
