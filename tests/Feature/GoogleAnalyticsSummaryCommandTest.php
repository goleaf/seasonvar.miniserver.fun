<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GoogleAnalyticsSummaryCommandTest extends TestCase
{
    public function test_it_exits_successfully_when_analytics_is_disabled(): void
    {
        Http::preventStrayRequests();
        config([
            'services.google.analytics.enabled' => false,
        ]);

        $this->artisan('google:analytics:summary')
            ->expectsOutputToContain('Google Analytics 4 выключен')
            ->assertExitCode(0);
    }

    public function test_it_prints_top_pages_from_ga4(): void
    {
        Http::preventStrayRequests();

        config([
            'services.google.application_credentials' => $this->credentialFile(),
            'services.google.analytics.enabled' => true,
            'services.google.analytics.property_id' => '123456789',
        ]);

        Http::fake([
            'oauth2.googleapis.com/token' => Http::response([
                'access_token' => 'ga4-token',
                'expires_in' => 3600,
            ]),
            'analyticsdata.googleapis.com/v1beta/properties/123456789:runReport' => Http::response([
                'rows' => [[
                    'dimensionValues' => [[
                        'value' => '/titles/test',
                    ]],
                    'metricValues' => [
                        ['value' => '321'],
                        ['value' => '123'],
                    ],
                ]],
            ]),
        ]);

        $this->artisan('google:analytics:summary --days=3 --limit=5')
            ->expectsOutputToContain('/titles/test')
            ->assertExitCode(0);

        Http::assertSent(fn ($request): bool => $request->url() === 'https://oauth2.googleapis.com/token');
        Http::assertSent(fn ($request): bool => $request->url() === 'https://analyticsdata.googleapis.com/v1beta/properties/123456789:runReport'
            && $request['limit'] === '5');
    }

    private function credentialFile(): string
    {
        $key = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);
        $privateKey = '';
        openssl_pkey_export($key, $privateKey);
        $path = storage_path('framework/testing/google-analytics.json');

        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0777, true);
        }

        file_put_contents($path, json_encode([
            'type' => 'service_account',
            'client_email' => 'seasonvar@example.iam.gserviceaccount.com',
            'private_key' => $privateKey,
            'token_uri' => 'https://oauth2.googleapis.com/token',
        ], JSON_THROW_ON_ERROR));

        return $path;
    }
}
