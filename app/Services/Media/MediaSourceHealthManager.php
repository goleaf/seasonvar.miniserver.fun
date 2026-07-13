<?php

declare(strict_types=1);

namespace App\Services\Media;

use App\DTOs\MediaHealthCheckResultData;
use App\Enums\MediaHealthStatus;
use App\Models\LicensedMedia;
use Illuminate\Support\Facades\DB;

final class MediaSourceHealthManager
{
    public function record(LicensedMedia $media, MediaHealthCheckResultData $result): LicensedMedia
    {
        return DB::transaction(function () use ($media, $result): LicensedMedia {
            $locked = LicensedMedia::query()->lockForUpdate()->findOrFail($media->getKey());

            if ($locked->health_status === MediaHealthStatus::Disabled) {
                return $locked;
            }

            if ($result->checkStatus === 'not_checked') {
                $locked->fill([
                    'status' => 'published',
                    'published_at' => $locked->published_at ?? $result->checkedAt,
                    'check_status' => 'not_checked',
                    'health_status' => MediaHealthStatus::Active,
                ])->save();

                return $locked;
            }

            $failures = $result->available ? 0 : ((int) $locked->consecutive_failures + 1);
            $health = $this->healthStatus($result, $failures);
            $nextCheckAt = $result->available
                ? $result->checkedAt->copy()->addHours($this->refreshHours())
                : $result->checkedAt->copy()->addMinutes($this->retryMinutes($failures, $result->permanentFailure));

            $locked->fill([
                'status' => $health->isPlayable() ? 'published' : 'unavailable',
                'published_at' => $health->isPlayable() ? ($locked->published_at ?? $result->checkedAt) : $locked->published_at,
                'check_status' => $result->checkStatus,
                'health_status' => $health,
                'last_http_status' => $result->httpStatus,
                'checked_at' => $result->checkedAt,
                'last_successful_check_at' => $result->available ? $result->checkedAt : $locked->last_successful_check_at,
                'last_error_category' => $result->errorCategory,
                'consecutive_failures' => $failures,
                'check_latency_ms' => $result->latencyMs,
                'next_check_at' => $nextCheckAt,
            ])->save();

            return $locked;
        }, attempts: 3);
    }

    private function healthStatus(MediaHealthCheckResultData $result, int $failures): MediaHealthStatus
    {
        if ($result->available) {
            return MediaHealthStatus::Active;
        }

        if ($result->permanentFailure || $failures >= $this->failureThreshold()) {
            return MediaHealthStatus::Unavailable;
        }

        return MediaHealthStatus::Degraded;
    }

    private function failureThreshold(): int
    {
        return max(1, (int) config('seasonvar.media_check.unavailable_after_failures', 3));
    }

    private function refreshHours(): int
    {
        return max(1, (int) config('seasonvar.media_check.refresh_after_hours', 168));
    }

    private function retryMinutes(int $failures, bool $permanent): int
    {
        if ($permanent) {
            return max(1, (int) config('seasonvar.media_check.permanent_retry_minutes', 360));
        }

        $base = max(1, (int) config('seasonvar.media_check.retry_base_minutes', 15));
        $maximum = max($base, (int) config('seasonvar.media_check.retry_max_minutes', 1440));
        $exponent = min(10, max(0, $failures - 1));

        return min($maximum, $base * (2 ** $exponent));
    }
}
