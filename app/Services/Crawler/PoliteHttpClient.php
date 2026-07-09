<?php

namespace App\Services\Crawler;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class PoliteHttpClient
{
    private float $lastRequestAt = 0.0;

    /**
     * @param  (callable(string, array<string, mixed>): void)|null  $progress
     */
    public function get(string $url, int $delaySeconds = 3, ?callable $progress = null): Response
    {
        $this->waitForDelay($delaySeconds, $url, $progress);

        $startedAt = microtime(true);
        $this->report($progress, 'http-request-started', [
            'url' => $url,
            'timeout_seconds' => 20,
            'connect_timeout_seconds' => 10,
        ]);

        $response = Http::withHeaders([
            'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
            'User-Agent' => 'SeasonvarCatalog/0.1 (+https://seasonvar.miniserver.fun)',
        ])
            ->timeout(20)
            ->connectTimeout(10)
            ->retry(2, 1000, throw: false)
            ->get($url);

        $this->lastRequestAt = microtime(true);

        $this->report($progress, 'http-request-complete', [
            'url' => $url,
            'http_status' => $response->status(),
            'successful' => $response->successful(),
            'body_bytes' => mb_strlen($response->body(), '8bit'),
            'duration_seconds' => $this->lastRequestAt - $startedAt,
        ]);

        return $response;
    }

    /**
     * @param  (callable(string, array<string, mixed>): void)|null  $progress
     */
    private function waitForDelay(int $delaySeconds, string $url, ?callable $progress = null): void
    {
        if ($delaySeconds <= 0 || $this->lastRequestAt === 0.0) {
            $this->report($progress, 'crawl-delay-skipped', [
                'url' => $url,
                'delay_seconds' => $delaySeconds,
                'reason' => $delaySeconds <= 0 ? 'пауза выключена' : 'первый запрос',
            ]);

            return;
        }

        $elapsedSinceLastRequest = microtime(true) - $this->lastRequestAt;
        $remaining = $delaySeconds - $elapsedSinceLastRequest;

        if ($remaining > 0) {
            $this->report($progress, 'crawl-delay-wait-started', [
                'url' => $url,
                'delay_seconds' => $delaySeconds,
                'elapsed_since_last_request_seconds' => $elapsedSinceLastRequest,
                'remaining_seconds' => $remaining,
            ]);

            usleep((int) ($remaining * 1_000_000));

            $this->report($progress, 'crawl-delay-wait-complete', [
                'url' => $url,
                'waited_seconds' => $remaining,
            ]);

            return;
        }

        $this->report($progress, 'crawl-delay-not-needed', [
            'url' => $url,
            'delay_seconds' => $delaySeconds,
            'elapsed_since_last_request_seconds' => $elapsedSinceLastRequest,
        ]);
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
