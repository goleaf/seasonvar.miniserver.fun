<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Models\LicensedMedia;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin LicensedMedia */
final class MediaProfileResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id,
            'translation' => $this->translation_name,
            'variant' => $this->variant_name,
            'variant_key' => $this->variant_key,
            'quality' => $this->quality,
            'format' => $this->format,
            'duration_seconds' => $this->duration_seconds === null ? null : (int) $this->duration_seconds,
        ];
    }
}
