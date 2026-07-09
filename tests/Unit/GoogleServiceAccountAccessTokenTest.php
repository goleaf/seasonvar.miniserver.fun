<?php

namespace Tests\Unit;

use App\Services\Google\GoogleIntegrationException;
use App\Services\Google\GoogleServiceAccountAccessToken;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class GoogleServiceAccountAccessTokenTest extends TestCase
{
    public function test_it_exchanges_service_account_credentials_for_an_access_token(): void
    {
        Http::preventStrayRequests();
        Cache::flush();

        config([
            'services.google.application_credentials' => $this->credentialFile(),
        ]);

        Http::fake([
            'oauth2.googleapis.com/token' => Http::response([
                'access_token' => 'test-google-token',
                'expires_in' => 3600,
                'token_type' => 'Bearer',
            ]),
        ]);

        $token = app(GoogleServiceAccountAccessToken::class)
            ->forScopes(['https://www.googleapis.com/auth/webmasters.readonly']);

        $this->assertSame('test-google-token', $token);

        Http::assertSent(fn ($request): bool => $request->url() === 'https://oauth2.googleapis.com/token'
            && $request['grant_type'] === 'urn:ietf:params:oauth:grant-type:jwt-bearer'
            && is_string($request['assertion'])
            && substr_count($request['assertion'], '.') === 2);
    }

    public function test_it_requires_a_credentials_path(): void
    {
        config([
            'services.google.application_credentials' => null,
        ]);

        $this->expectException(GoogleIntegrationException::class);
        $this->expectExceptionMessage('GOOGLE_APPLICATION_CREDENTIALS не задан.');

        app(GoogleServiceAccountAccessToken::class)
            ->forScopes(['https://www.googleapis.com/auth/webmasters.readonly']);
    }

    private function credentialFile(): string
    {
        Storage::fake('local');
        $key = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);
        $privateKey = '';
        openssl_pkey_export($key, $privateKey);
        $path = storage_path('framework/testing/google-service-account.json');

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
