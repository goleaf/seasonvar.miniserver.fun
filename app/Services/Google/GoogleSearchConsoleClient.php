<?php

namespace App\Services\Google;

use Illuminate\Http\Client\Factory as HttpFactory;

class GoogleSearchConsoleClient
{
    private const READONLY_SCOPE = 'https://www.googleapis.com/auth/webmasters.readonly';

    private const WRITE_SCOPE = 'https://www.googleapis.com/auth/webmasters';

    public function __construct(
        private readonly HttpFactory $http,
        private readonly GoogleServiceAccountAccessToken $tokens,
    ) {}

    public function enabled(): bool
    {
        return (bool) config('services.google.search_console.enabled', false);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function topPages(int $days = 7, int $limit = 10): array
    {
        $siteUrl = (string) config('services.google.search_console.site_url', '');

        if ($siteUrl === '') {
            throw new GoogleIntegrationException('GOOGLE_SEARCH_CONSOLE_SITE_URL не задан.');
        }

        $response = $this->http
            ->withToken($this->tokens->forScopes([$this->scope()]))
            ->acceptJson()
            ->asJson()
            ->timeout(10)
            ->connectTimeout(3)
            ->retry([100, 300], throw: false)
            ->post('https://www.googleapis.com/webmasters/v3/sites/'.rawurlencode($siteUrl).'/searchAnalytics/query', [
                'startDate' => now()->subDays(max(1, $days))->toDateString(),
                'endDate' => now()->subDay()->toDateString(),
                'dimensions' => ['page'],
                'rowLimit' => max(1, min(250, $limit)),
            ])
            ->throw()
            ->json();

        return is_array($response) && isset($response['rows']) && is_array($response['rows'])
            ? $response['rows']
            : [];
    }

    private function scope(): string
    {
        return (bool) config('services.google.search_console.readonly', true)
            ? self::READONLY_SCOPE
            : self::WRITE_SCOPE;
    }
}
