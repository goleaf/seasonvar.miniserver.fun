<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PremiumRefundStatus;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property string $public_id
 * @property int $premium_payment_id
 * @property string $provider_refund_id
 * @property string $idempotency_key
 * @property PremiumRefundStatus $status
 * @property int $amount_minor
 * @property string $currency
 * @property string|null $reason_code
 * @property CarbonImmutable|null $confirmed_at
 * @property CarbonImmutable|null $provider_updated_at
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 */
#[Fillable([
    'public_id', 'premium_payment_id', 'provider_refund_id', 'idempotency_key',
    'status', 'amount_minor', 'currency', 'reason_code', 'confirmed_at', 'provider_updated_at',
])]
class PremiumRefund extends Model
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
            'status' => PremiumRefundStatus::class,
            'amount_minor' => 'integer',
            'confirmed_at' => 'immutable_datetime',
            'provider_updated_at' => 'immutable_datetime',
        ];
    }
}
