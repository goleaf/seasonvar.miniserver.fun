<?php

namespace App\Services\Crawler;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class PoliteHttpClient
{
    private float $lastRequestAt = 0.0;

    public function get(string $url, int $delaySeconds = 3): Response
    {
        $this->waitForDelay($delaySeconds);

        $response = Http::withHeaders([
            'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
            'User-Agent' => 'SeasonvarMetadataIndexer/0.1 (+https://seasonvar.miniserver.fun; metadata only)',
        ])
            ->timeout(20)
            ->retry(2, 1000)
            ->get($url);

        $this->lastRequestAt = microtime(true);

        return $response;
    }

    private function waitForDelay(int $delaySeconds): void
    {
        if ($delaySeconds <= 0 || $this->lastRequestAt === 0.0) {
            return;
        }

        $remaining = $delaySeconds - (microtime(true) - $this->lastRequestAt);

        if ($remaining > 0) {
            usleep((int) ($remaining * 1_000_000));
        }
    }
}
