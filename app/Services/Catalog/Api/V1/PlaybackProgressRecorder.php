<?php

declare(strict_types=1);

namespace App\Services\Catalog\Api\V1;

use App\DTOs\PlaybackProgressInput;
use App\Models\EpisodeViewProgress;
use App\Models\User;
use App\Services\Catalog\CatalogTitleQuery;
use App\Services\Catalog\CatalogUserStateService;

final readonly class PlaybackProgressRecorder
{
    public function __construct(
        private CatalogTitleQuery $titles,
        private CatalogUserStateService $states,
    ) {}

    public function record(
        User $user,
        string $titleSlug,
        int $episodeId,
        PlaybackProgressInput $input,
    ): ?EpisodeViewProgress {
        $title = $this->titles->visibleTo($user)
            ->where('slug', $titleSlug)
            ->firstOrFail();

        return $this->states->recordProgress(
            $user,
            $title,
            $episodeId,
            $input->playbackSessionToken,
            $input->eventSequence,
            $input->positionSeconds,
            $input->reportedDurationSeconds,
            $input->ended,
        );
    }
}
