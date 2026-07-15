<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Models\UserTag;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin UserTag */
final class PersonalTagResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'public_id' => (string) $this->public_id,
            'name' => (string) $this->name,
            'description' => $this->description,
            'content_locale' => $this->content_locale,
            'visibility' => 'private',
            'content_version' => (int) $this->content_version,
            'assignments_count' => $this->whenCounted('catalogTitles'),
            'created_at' => $this->created_at?->toJSON(),
            'updated_at' => $this->updated_at?->toJSON(),
            'deleted_at' => $this->deleted_at?->toJSON(),
        ];
    }
}
