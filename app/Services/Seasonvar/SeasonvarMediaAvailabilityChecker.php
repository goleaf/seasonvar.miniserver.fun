<?php

namespace App\Services\Seasonvar;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Throwable;

class SeasonvarMediaAvailabilityChecker
{
    public function __construct(private readonly SeasonvarUrl $seasonvarUrl) {}

    /**
     * @param  (callable(string, array<string, mixed>): void)|null  $progress
     * @return array{available: bool, status: string, http_status: int|null, checked_at: Carbon|null}
     */
    public function check(string $url, ?callable $progress = null): array
    {
        if (! (bool) config('seasonvar.media_check.enabled', true)) {
            return [
                'available' => true,
                'status' => 'not_checked',
                'http_status' => null,
                'checked_at' => null,
            ];
        }

        try {
            $response = Http::withHeaders([
                'Accept' => '*/*',
                'Range' => 'bytes=0-0',
                'Referer' => $this->seasonvarUrl->baseUrl().'/',
                'User-Agent' => 'SeasonvarCatalog/0.1 (+https://seasonvar.miniserver.fun)',
            ])
                ->timeout((int) config('seasonvar.media_check.timeout_seconds', 10))
                ->connectTimeout((int) config('seasonvar.media_check.connect_timeout_seconds', 5))
                ->retry((int) config('seasonvar.media_check.retries', 3), 500, throw: false)
                ->get($url);

            $status = $response->status();
            $available = ($status >= 200 && $status < 400) || $status === 206;

            $this->report($progress, 'seasonvar-media-url-checked', [
                'url' => $url,
                'http_status' => $status,
                'successful' => $available,
            ]);

            return [
                'available' => $available,
                'status' => $available ? 'available' : 'unavailable',
                'http_status' => $status,
                'checked_at' => now(),
            ];
        } catch (Throwable $exception) {
            $this->report($progress, 'seasonvar-media-url-check-failed', [
                'url' => $url,
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);

            return [
                'available' => false,
                'status' => 'check_failed',
                'http_status' => null,
                'checked_at' => now(),
            ];
        }
    }

    /**
     * @param  (callable(string, array<string, mixed>): void)|null  $progress
     * @param  array<string, mixed>  $context
     */
    private function report(?callable $progress, string $event, array $context = []): void
    {
        if ($progress === null) {
            return;
        }

        $progress($event, $context);
    }
}
