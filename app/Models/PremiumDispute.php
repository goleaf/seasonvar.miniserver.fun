<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PremiumDisputeStatus;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property string $public_id
 * @property int $premium_payment_id
 * @property string $provider_dispute_id
 * @property PremiumDisputeStatus $status
 * @property int|null $amount_minor
 * @property string|null $currency
 * @property CarbonImmutable $opened_at
 * @property CarbonImmutable|null $closed_at
 * @property CarbonImmutable|null $provider_updated_at
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 */
#[Fillable([
    'public_id', 'premium_payment_id', 'provider_dispute_id', 'status', 'amount_minor',
    'currency', 'opened_at', 'closed_at', 'provider_updated_at',
])]
class PremiumDispute extends Model
{
    /** @return BelongsTo<PremiumPayment, $this> */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(PremiumPayment::class, 'premium_payment_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => PremiumDisputeStatus::class,
            'amount_minor' => 'integer',
            'opened_at' => 'immutable_datetime',
            'closed_at' => 'immutable_datetime',
            'provider_updated_at' => 'immutable_datetime',
        ];
    }
}
