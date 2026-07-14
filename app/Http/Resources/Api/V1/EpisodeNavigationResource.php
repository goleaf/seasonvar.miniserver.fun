<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\DTOs\CatalogEpisodeNavigation;
use App\Models\Episode;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin CatalogEpisodeNavigation */
final class EpisodeNavigationResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'previous' => $this->episode($this->previous),
            'next' => $this->episode($this->next),
        ];
    }

    /** @return array<string, mixed>|null */
    private function episode(?Episode $episode): ?array
    {
        if ($episode === null) {
            return null;
        }

        return [
            'id' => (int) $episode->id,
            'season_id' => (int) $episode->season_id,
            'number' => $episode->number === null ? null : (int) $episode->number,
            'kind' => $episode->kind->value,
            'title' => $episode->title,
        ];
    }
}
