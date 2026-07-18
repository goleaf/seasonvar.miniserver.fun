<?php

declare(strict_types=1);

namespace App\Services\Seasonvar;

use App\DTOs\MediaHealthCheckResultData;
use App\Enums\MediaHealthErrorCategory;
use App\Services\Media\PlaybackSourceUrlGuard;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

final class SeasonvarMediaAvailabilityChecker
{
    public function __construct(
        private readonly SeasonvarUrl $seasonvarUrl,
        private readonly PlaybackSourceUrlGuard $urls,
    ) {}

    /** @param (callable(string, array<string, mixed>): void)|null $progress */
    public function check(string $url, ?callable $progress = null): MediaHealthCheckResultData
    {
        $checkedAt = now();

        if (! (bool) config('seasonvar.media_check.enabled', true)) {
            return new MediaHealthCheckResultData(true, 'not_checked', null, $checkedAt, null);
        }

        $target = $this->urls->verifiedExternalUrl($url);

        if ($target === null) {
            $this->report($progress, 'seasonvar-media-url-check-failed', [
                'url' => '[redacted-url]',
                'reason' => MediaHealthErrorCategory::InvalidUrl->value,
            ]);

            return new MediaHealthCheckResultData(
                false,
                'invalid_url',
                null,
                $checkedAt,
                null,
                MediaHealthErrorCategory::InvalidUrl,
                true,
            );
        }

        $startedAt = hrtime(true);

        try {
            $response = Http::withHeaders([
                'Accept' => $this->isManifest($target->url) ? 'application/vnd.apple.mpegurl, application/x-mpegURL' : '*/*',
                'Range' => 'bytes=0-'.$this->rangeEnd(),
                'Referer' => $this->seasonvarUrl->baseUrl().'/',
                'User-Agent' => (string) config('seasonvar.http_user_agent'),
            ])
                ->timeout($this->timeoutSeconds())
                ->connectTimeout($this->connectTimeoutSeconds())
                ->withoutRedirecting()
                ->withOptions($target->httpOptions())
                ->retry($this->attempts(), 500, throw: false)
                ->get($target->url);

            return $this->responseResult($response, $target->url, $startedAt, $progress);
        } catch (Throwable $exception) {
            $category = $this->exceptionCategory($exception);
            $this->report($progress, 'seasonvar-media-url-check-failed', [
                'url' => '[redacted-url]',
                'reason' => $category->value,
                'exception' => $exception::class,
            ]);

            return new MediaHealthCheckResultData(
                false,
                'check_failed',
                null,
                now(),
                $this->latencyMilliseconds($startedAt),
                $category,
            );
        }
    }

    /** @param (callable(string, array<string, mixed>): void)|null $progress */
    private function responseResult(Response $response, string $url, int $startedAt, ?callable $progress): MediaHealthCheckResultData
    {
        $status = $response->status();
        $latency = $this->latencyMilliseconds($startedAt);
        $checkedAt = now();
        $failure = $this->httpFailure($status);

        if ($failure !== null) {
            $response->toPsrResponse()->getBody()->close();

            return $this->failedResult($status, $latency, $checkedAt, $failure['category'], $failure['permanent'], $progress);
        }

        $maxBytes = $this->maxResponseBytes();
        $contentLength = filter_var($response->header('Content-Length'), FILTER_VALIDATE_INT);

        if ($contentLength !== false && $contentLength > $maxBytes) {
            $response->toPsrResponse()->getBody()->close();

            return $this->failedResult(
                $status,
                $latency,
                $checkedAt,
                $status === 200 && ! $this->isManifest($url)
                    ? MediaHealthErrorCategory::RangeUnsupported
                    : MediaHealthErrorCategory::ResponseTooLarge,
                true,
                $progress,
            );
        }

        $body = $response->toPsrResponse()->getBody();
        $sample = $body->read($maxBytes + 1);
        $body->close();

        if (mb_strlen($sample, '8bit') > $maxBytes) {
            return $this->failedResult(
                $status,
                $latency,
                $checkedAt,
                MediaHealthErrorCategory::ResponseTooLarge,
                true,
                $progress,
            );
        }

        if ($this->isManifest($url) && ! Str::startsWith(ltrim($sample), '#EXTM3U')) {
            return $this->failedResult(
                $status,
                $latency,
                $checkedAt,
                MediaHealthErrorCategory::InvalidManifest,
                true,
                $progress,
            );
        }

        $this->report($progress, 'seasonvar-media-url-checked', [
            'url' => '[redacted-url]',
            'http_status' => $status,
            'successful' => true,
            'latency_ms' => $latency,
        ]);

        return new MediaHealthCheckResultData(true, 'available', $status, $checkedAt, $latency);
    }

    /**
     * @param  (callable(string, array<string, mixed>): void)|null  $progress
     */
    private function failedResult(
        int $status,
        int $latency,
        Carbon $checkedAt,
        MediaHealthErrorCategory $category,
        bool $permanent,
        ?callable $progress,
    ): MediaHealthCheckResultData {
        $this->report($progress, 'seasonvar-media-url-checked', [
            'url' => '[redacted-url]',
            'http_status' => $status,
            'successful' => false,
            'error_category' => $category->value,
            'latency_ms' => $latency,
        ]);

        return new MediaHealthCheckResultData(
            false,
            'unavailable',
            $status,
            $checkedAt,
            $latency,
            $category,
            $permanent,
        );
    }

    /** @return array{category: MediaHealthErrorCategory, permanent: bool}|null */
    private function httpFailure(int $status): ?array
    {
        return match (true) {
            $status >= 200 && $status < 300 => null,
            $status >= 300 && $status < 400 => ['category' => MediaHealthErrorCategory::UnsafeRedirect, 'permanent' => true],
            in_array($status, [401, 403], true) => ['category' => MediaHealthErrorCategory::Authentication, 'permanent' => true],
            in_array($status, [404, 410], true) => ['category' => MediaHealthErrorCategory::NotFound, 'permanent' => true],
            $status === 429 => ['category' => MediaHealthErrorCategory::RateLimited, 'permanent' => false],
            in_array($status, [408, 425], true) || $status >= 500 => ['category' => MediaHealthErrorCategory::ProviderTemporary, 'permanent' => false],
            default => ['category' => MediaHealthErrorCategory::Unknown, 'permanent' => true],
        };
    }

    private function exceptionCategory(Throwable $exception): MediaHealthErrorCategory
    {
        if ($exception instanceof ConnectionException && str_contains(Str::lower($exception->getMessage()), 'timed out')) {
            return MediaHealthErrorCategory::Timeout;
        }

        return $exception instanceof ConnectionException
            ? MediaHealthErrorCategory::Connection
            : MediaHealthErrorCategory::Unknown;
    }

    private function isManifest(string $url): bool
    {
        return Str::lower(pathinfo((string) parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION)) === 'm3u8';
    }

    private function rangeEnd(): int
    {
        return $this->maxResponseBytes() - 1;
    }

    private function maxResponseBytes(): int
    {
        return max(1, min(1_048_576, (int) config('seasonvar.media_check.max_response_bytes', 65536)));
    }

    private function attempts(): int
    {
        return max(1, min(5, (int) config('seasonvar.media_check.retries', 3)));
    }

    private function timeoutSeconds(): int
    {
        return max(1, min(30, (int) config('seasonvar.media_check.timeout_seconds', 10)));
    }

    private function connectTimeoutSeconds(): int
    {
        return max(1, min($this->timeoutSeconds(), (int) config('seasonvar.media_check.connect_timeout_seconds', 5)));
    }

    private function latencyMilliseconds(int $startedAt): int
    {
        return max(0, min(4_294_967_295, (int) round((hrtime(true) - $startedAt) / 1_000_000)));
    }

    /**
     * @param  (callable(string, array<string, mixed>): void)|null  $progress
     * @param  array<string, mixed>  $context
     */
    private function report(?callable $progress, string $event, array $context = []): void
    {
        if ($progress !== null) {
            $progress($event, $context);
        }
    }
}
