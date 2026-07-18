<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PremiumProviderEventStatus;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $provider_code
 * @property string $provider_event_id
 * @property string $event_type
 * @property string $environment
 * @property PremiumProviderEventStatus $status
 * @property string|null $object_type
 * @property string|null $object_id
 * @property string $payload_hash
 * @property int $attempts
 * @property string|null $error_category
 * @property CarbonImmutable|null $occurred_at
 * @property CarbonImmutable|null $processed_at
 * @property CarbonImmutable|null $failed_at
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 */
#[Fillable([
    'provider_code', 'provider_event_id', 'event_type', 'environment', 'status', 'object_type', 'object_id',
    'payload_hash', 'attempts', 'error_category', 'occurred_at', 'processed_at', 'failed_at',
])]
class PremiumProviderEvent extends Model
{
    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => PremiumProviderEventStatus::class,
            'attempts' => 'integer',
            'occurred_at' => 'immutable_datetime',
            'processed_at' => 'immutable_datetime',
            'failed_at' => 'immutable_datetime',
        ];
    }
}
