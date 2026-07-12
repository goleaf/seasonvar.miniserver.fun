<?php

declare(strict_types=1);

namespace App\Services\Catalog;

final class CatalogPlaybackCompletionRule
{
    public function isComplete(int $positionSeconds, int $durationSeconds, bool $ended): bool
    {
        if ($ended) {
            return true;
        }

        if ($durationSeconds < 1) {
            return false;
        }

        $completionPercent = max(1, min(100, (int) config('playback.progress.completion_percent', 95)));
        $remainingSeconds = max(0, min(600, (int) config('playback.progress.completion_remaining_seconds', 15)));

        return $this->percentage($positionSeconds, $durationSeconds) >= $completionPercent
            || $durationSeconds - min($positionSeconds, $durationSeconds) <= $remainingSeconds;
    }

    public function percentage(int $positionSeconds, int $durationSeconds): ?int
    {
        if ($durationSeconds < 1) {
            return null;
        }

        return max(0, min(100, (int) floor(
            min($positionSeconds, $durationSeconds) / $durationSeconds * 100,
        )));
    }

    public function isInProgress(int $positionSeconds, int $durationSeconds): bool
    {
        return $positionSeconds > 0
            && $durationSeconds > 0
            && ! $this->isComplete($positionSeconds, $durationSeconds, false);
    }
}
