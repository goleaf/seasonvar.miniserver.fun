<?php

declare(strict_types=1);

namespace App\Services\Catalog;

use Illuminate\Support\Facades\DB;

final class PlaybackQualityMetricsQuery
{
    /** @return array{overview: array<string, int|float|null>, errors_by_browser: list<array{label: string, count: int}>, errors_by_provider: list<array{label: string, count: int}>, errors_by_quality: list<array{label: string, count: int}>} */
    public function summary(int $days): array
    {
        $days = in_array($days, [1, 7, 30], true) ? $days : 7;
        $cutoff = now()->subDays($days);
        $overview = DB::table('playback_quality_sessions')
            ->where('started_at', '>=', $cutoff)
            ->selectRaw('COUNT(*) AS sessions')
            ->selectRaw('AVG(startup_time_ms) AS average_startup_time_ms')
            ->selectRaw('SUM(playback_time_ms) AS playback_time_ms')
            ->selectRaw('SUM(buffering_time_ms) AS buffering_time_ms')
            ->selectRaw('SUM(CASE WHEN playback_failed = 1 THEN 1 ELSE 0 END) AS failed_sessions')
            ->selectRaw('SUM(CASE WHEN fallback_attempted = 1 THEN 1 ELSE 0 END) AS fallback_attempts')
            ->selectRaw('SUM(CASE WHEN fallback_succeeded = 1 THEN 1 ELSE 0 END) AS fallback_successes')
            ->first();

        $sessions = (int) ($overview->sessions ?? 0);
        $playbackTime = (int) ($overview->playback_time_ms ?? 0);
        $bufferingTime = (int) ($overview->buffering_time_ms ?? 0);
        $fallbackAttempts = (int) ($overview->fallback_attempts ?? 0);

        return [
            'overview' => [
                'sessions' => $sessions,
                'average_startup_time_ms' => $overview?->average_startup_time_ms !== null
                    ? (int) round((float) $overview->average_startup_time_ms)
                    : null,
                'rebuffer_ratio_percent' => $this->percent($bufferingTime, $playbackTime + $bufferingTime),
                'playback_error_rate_percent' => $this->percent((int) ($overview->failed_sessions ?? 0), $sessions),
                'fallback_success_rate_percent' => $this->percent((int) ($overview->fallback_successes ?? 0), $fallbackAttempts),
            ],
            'errors_by_browser' => $this->browserErrors($cutoff),
            'errors_by_provider' => $this->dimensionErrors($cutoff, 'error_provider_code'),
            'errors_by_quality' => $this->dimensionErrors($cutoff, 'quality_code'),
        ];
    }

    /** @return list<array{label: string, count: int}> */
    private function browserErrors(\DateTimeInterface $cutoff): array
    {
        return DB::table('playback_quality_sessions')
            ->where('started_at', '>=', $cutoff)
            ->where('playback_failed', true)
            ->whereNotNull('browser_family')
            ->select(['browser_family', 'browser_major'])
            ->selectRaw('COUNT(*) AS aggregate')
            ->groupBy(['browser_family', 'browser_major'])
            ->orderByDesc('aggregate')
            ->orderBy('browser_family')
            ->limit(10)
            ->get()
            ->map(fn (object $row): array => [
                'label' => trim($row->browser_family.' '.($row->browser_major ?? '')),
                'count' => (int) $row->aggregate,
            ])
            ->all();
    }

    /** @return list<array{label: string, count: int}> */
    private function dimensionErrors(\DateTimeInterface $cutoff, string $column): array
    {
        return DB::table('playback_quality_sessions')
            ->where('started_at', '>=', $cutoff)
            ->where('playback_failed', true)
            ->whereNotNull($column)
            ->select($column)
            ->selectRaw('COUNT(*) AS aggregate')
            ->groupBy($column)
            ->orderByDesc('aggregate')
            ->orderBy($column)
            ->limit(10)
            ->get()
            ->map(fn (object $row): array => [
                'label' => (string) $row->{$column},
                'count' => (int) $row->aggregate,
            ])
            ->all();
    }

    private function percent(int $part, int $total): float
    {
        return $total > 0 ? round(($part / $total) * 100, 1) : 0.0;
    }
}
