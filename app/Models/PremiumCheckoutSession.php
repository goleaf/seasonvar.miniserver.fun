<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PremiumCheckoutStatus;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property string $public_id
 * @property int $user_id
 * @property int $premium_plan_id
 * @property string $provider_code
 * @property string|null $provider_session_id
 * @property string $idempotency_key
 * @property PremiumCheckoutStatus $status
 * @property int $amount_minor
 * @property string $currency
 * @property string $locale
 * @property CarbonImmutable|null $expires_at
 * @property CarbonImmutable|null $completed_at
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 * @property-read User $user
 * @property-read PremiumPlan $plan
 */
#[Fillable([
    'public_id', 'user_id', 'premium_plan_id', 'provider_code', 'provider_session_id',
    'idempotency_key', 'status', 'amount_minor', 'currency', 'locale', 'expires_at', 'completed_at',
])]
class PremiumCheckoutSession extends Model
{
    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<PremiumPlan, $this> */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(PremiumPlan::class, 'premium_plan_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => PremiumCheckoutStatus::class,
            'amount_minor' => 'integer',
            'expires_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
        ];
    }
}
