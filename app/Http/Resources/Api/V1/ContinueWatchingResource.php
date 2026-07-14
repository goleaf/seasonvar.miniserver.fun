<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\DTOs\CatalogContinueWatchingItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin CatalogContinueWatchingItem */
final class ContinueWatchingResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'action' => $this->actionType,
            'label' => $this->actionLabel,
            'position_seconds' => $this->positionSeconds,
            'progress_percent' => $this->progressPercent,
            'title' => new TitleCardResource($this->title),
            'episode' => new EpisodeResource($this->episode),
        ];
    }
}
