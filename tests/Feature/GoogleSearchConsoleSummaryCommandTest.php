<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GoogleSearchConsoleSummaryCommandTest extends TestCase
{
    public function test_it_exits_successfully_when_search_console_is_disabled(): void
    {
        Http::preventStrayRequests();
        config([
            'services.google.search_console.enabled' => false,
        ]);

        $this->artisan('google:search-console:summary')
            ->expectsOutputToContain('Google Search Console выключен')
            ->assertExitCode(0);
    }

    public function test_it_prints_top_pages_from_search_console(): void
    {
        Http::preventStrayRequests();
        Cache::flush();

        config([
            'services.google.application_credentials' => $this->credentialFile(),
            'services.google.search_console.enabled' => true,
            'services.google.search_console.site_url' => 'https://seasonvar.miniserver.fun/',
            'services.google.search_console.readonly' => true,
        ]);

        Http::fake([
            'oauth2.googleapis.com/token' => Http::response([
                'access_token' => 'search-console-token',
                'expires_in' => 3600,
            ]),
            'www.googleapis.com/webmasters/v3/sites/*/searchAnalytics/query' => Http::response([
                'rows' => [[
                    'keys' => ['https://seasonvar.miniserver.fun/titles/test'],
                    'clicks' => 12,
                    'impressions' => 120,
                    'ctr' => 0.1,
                    'position' => 4.2,
                ]],
            ]),
        ]);

        $this->artisan('google:search-console:summary --days=3 --limit=5')
            ->expectsOutputToContain('https://seasonvar.miniserver.fun/titles/test')
            ->assertExitCode(0);

        Http::assertSent(fn ($request): bool => $request->url() === 'https://oauth2.googleapis.com/token');
        Http::assertSent(fn ($request): bool => str_contains($request->url(), '/searchAnalytics/query')
            && $request['rowLimit'] === 5);
    }

    private function credentialFile(): string
    {
        $key = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);
        $privateKey = '';
        openssl_pkey_export($key, $privateKey);
        $path = storage_path('framework/testing/google-search-console.json');

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
