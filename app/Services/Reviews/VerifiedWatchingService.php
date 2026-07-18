<?php

declare(strict_types=1);

namespace App\Services\Reviews;

use App\Enums\PlaybackCompletionSource;
use App\Models\CatalogTitle;
use App\Models\EpisodeViewProgress;
use App\Models\User;

final class VerifiedWatchingService
{
    public function verified(User $user, CatalogTitle $title): bool
    {
        $minimumPercent = max(1, min(100, (int) config('reviews.verification.minimum_progress_percent', 10)));
        $minimumSeconds = max(1, (int) config('reviews.verification.minimum_position_seconds', 300));

        return EpisodeViewProgress::query()
            ->where('user_id', $user->id)
            ->where('catalog_title_id', $title->id)
            ->where(function ($query): void {
                $query
                    ->where('completion_source', PlaybackCompletionSource::Playback->value)
                    ->orWhere(function ($query): void {
                        $query
                            ->whereNull('completion_source')
                            ->whereNotNull('playback_session_id');
                    });
            })
            ->where(function ($query) use ($minimumPercent, $minimumSeconds): void {
                $query
                    ->whereNotNull('completed_at')
                    ->orWhere('progress_percent', '>=', $minimumPercent)
                    ->orWhere('position_seconds', '>=', $minimumSeconds);
            })
            ->exists();
    }
}
