<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\DTOs\MobilePlaybackSessionData;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin MobilePlaybackSessionData */
final class PlaybackSessionResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'status' => $this->status->value,
            'message' => $this->message,
            'title' => [
                'id' => (int) $this->title?->id,
                'slug' => (string) $this->title?->slug,
                'title' => $this->title?->display_title,
                'poster_url' => $this->title?->poster_url,
            ],
            'episode' => $this->episode === null ? null : [
                'id' => (int) $this->episode->id,
                'season_id' => (int) $this->episode->season_id,
                'number' => (int) $this->episode->number,
                'kind' => $this->episode->kind->value,
                'title' => $this->episode->title,
            ],
            'media' => new MediaProfileResource($this->media),
            'playback_url' => $this->playbackUrl,
            'mime_type' => $this->mimeType,
            'expires_at' => $this->expiresAt?->toJSON(),
            'navigation' => $this->navigation === null ? null : new EpisodeNavigationResource($this->navigation),
            'progress_session_token' => $this->when(
                $this->progressSessionToken !== null,
                $this->progressSessionToken,
            ),
        ];
    }
}
