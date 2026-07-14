<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Models\ApiSyncChange;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ApiSyncChange */
final class SyncChangeResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $key = $this->resource_key === null ? null : (string) $this->resource_key;

        return [
            'type' => (string) $this->resource_type,
            'key' => $key,
            'operation' => (string) $this->operation,
            'changed_at' => $this->changed_at?->copy()->utc()->toISOString(),
            'links' => [
                'self' => $this->operation === ApiSyncChange::OPERATION_UPSERT && $key !== null
                    ? route('api.v1.titles.show', ['titleSlug' => $key])
                    : null,
            ],
        ];
    }
}
