<?php

namespace App\Services\Google;

use Illuminate\Http\Client\Factory as HttpFactory;

class GoogleAnalyticsDataClient
{
    private const READONLY_SCOPE = 'https://www.googleapis.com/auth/analytics.readonly';

    public function __construct(
        private readonly HttpFactory $http,
        private readonly GoogleServiceAccountAccessToken $tokens,
    ) {}

    public function enabled(): bool
    {
        return (bool) config('services.google.analytics.enabled', false);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function topPages(int $days = 7, int $limit = 10): array
    {
        $propertyId = (string) config('services.google.analytics.property_id', '');

        if ($propertyId === '') {
            throw new GoogleIntegrationException('GOOGLE_ANALYTICS_PROPERTY_ID не задан.');
        }

        $response = $this->http
            ->withToken($this->tokens->forScopes([self::READONLY_SCOPE]))
            ->acceptJson()
            ->asJson()
            ->timeout(10)
            ->connectTimeout(3)
            ->retry([100, 300], throw: false)
            ->post('https://analyticsdata.googleapis.com/v1beta/properties/'.$propertyId.':runReport', [
                'dateRanges' => [[
                    'startDate' => max(1, $days).'daysAgo',
                    'endDate' => 'yesterday',
                ]],
                'dimensions' => [[
                    'name' => 'pagePath',
                ]],
                'metrics' => [
                    ['name' => 'screenPageViews'],
                    ['name' => 'totalUsers'],
                ],
                'limit' => (string) max(1, min(250, $limit)),
            ])
            ->throw()
            ->json();

        return is_array($response) && isset($response['rows']) && is_array($response['rows'])
            ? $response['rows']
            : [];
    }
}
