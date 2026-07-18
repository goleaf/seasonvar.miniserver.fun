<?php

declare(strict_types=1);

namespace App\Services\Catalog;

final class PlaybackTimeFormatter
{
    public function compact(int $seconds): string
    {
        $seconds = max(0, $seconds);
        $hours = intdiv($seconds, 3600);
        $minutes = intdiv($seconds % 3600, 60);
        $remaining = $seconds % 60;

        return $hours > 0
            ? sprintf('%d:%02d:%02d', $hours, $minutes, $remaining)
            : sprintf('%02d:%02d', $minutes, $remaining);
    }
}
