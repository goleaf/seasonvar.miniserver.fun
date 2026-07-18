<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PremiumPlanType;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $code
 * @property PremiumPlanType $type
 * @property int|null $duration_days
 * @property string|null $billing_interval
 * @property int|null $amount_minor
 * @property string|null $currency
 * @property array<int, mixed> $entitlement_codes
 * @property string|null $provider_code
 * @property string|null $provider_product_id
 * @property string|null $provider_price_id
 * @property array<int, mixed>|null $region_codes
 * @property bool $is_active
 * @property bool $is_public
 * @property bool $is_legacy
 * @property int $display_order
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 */
#[Fillable([
    'code', 'type', 'duration_days', 'billing_interval', 'amount_minor', 'currency',
    'entitlement_codes', 'provider_code', 'provider_product_id', 'provider_price_id',
    'region_codes', 'is_active', 'is_public', 'is_legacy', 'display_order',
])]
class PremiumPlan extends Model
{
    /** @param Builder<PremiumPlan> $query */
    public function scopePurchasable(Builder $query): void
    {
        $query->where('is_active', true)->where('is_public', true)->where('is_legacy', false);
    }

    /** @return HasMany<PremiumEntitlement, $this> */
    public function entitlements(): HasMany
    {
        return $this->hasMany(PremiumEntitlement::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'type' => PremiumPlanType::class,
            'duration_days' => 'integer',
            'amount_minor' => 'integer',
            'entitlement_codes' => 'array',
            'region_codes' => 'array',
            'is_active' => 'boolean',
            'is_public' => 'boolean',
            'is_legacy' => 'boolean',
            'display_order' => 'integer',
        ];
    }
}
