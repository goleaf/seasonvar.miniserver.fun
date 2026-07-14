<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Laravel\Sanctum\PersonalAccessToken;

/** @mixin PersonalAccessToken */
final class DeviceTokenResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id,
            'name' => (string) $this->name,
            'last_used_at' => $this->last_used_at?->toJSON(),
            'expires_at' => $this->expires_at?->toJSON(),
            'current' => (int) $request->user()->currentAccessToken()->getKey() === (int) $this->id,
        ];
    }
}
