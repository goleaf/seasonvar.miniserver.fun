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
        [$titleSlug, $episodeId] = $this->progressIdentity((string) $this->resource_type, $key);

        return [
            'type' => (string) $this->resource_type,
            'key' => $key,
            'operation' => (string) $this->operation,
            'changed_at' => $this->changed_at?->copy()->utc()->toISOString(),
            'title_slug' => $this->when($titleSlug !== null, $titleSlug),
            'episode_id' => $this->when($episodeId !== null, $episodeId),
            'links' => [
                'self' => $this->selfLink((string) $this->resource_type, $key, $titleSlug),
                'history' => $this->when(
                    in_array($this->resource_type, ['progress', 'history'], true),
                    route('api.v1.me.history.index'),
                ),
            ],
        ];
    }

    /** @return array{string|null, int|null} */
    private function progressIdentity(string $type, ?string $key): array
    {
        if ($type !== 'progress' || $key === null || ! str_contains($key, ':')) {
            return [null, null];
        }

        [$slug, $episode] = explode(':', $key, 2);

        return $slug !== '' && ctype_digit($episode) && (int) $episode > 0
            ? [$slug, (int) $episode]
            : [null, null];
    }

    private function selfLink(string $type, ?string $key, ?string $progressTitleSlug): ?string
    {
        if ($this->operation !== ApiSyncChange::OPERATION_UPSERT) {
            return null;
        }

        return match ($type) {
            'title' => $key === null ? null : route('api.v1.titles.show', ['titleSlug' => $key]),
            'title_state' => $key === null ? null : route('api.v1.me.titles.state.show', ['catalogTitle' => $key]),
            'progress' => $progressTitleSlug === null
                ? null
                : route('api.v1.me.titles.state.show', ['catalogTitle' => $progressTitleSlug]),
            default => null,
        };
    }
}
