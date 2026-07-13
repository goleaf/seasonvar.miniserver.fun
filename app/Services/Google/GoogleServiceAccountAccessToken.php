<?php

namespace App\Services\Google;

use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Cache;
use JsonException;

class GoogleServiceAccountAccessToken
{
    private const DEFAULT_TOKEN_URI = 'https://oauth2.googleapis.com/token';

    public function __construct(private readonly HttpFactory $http) {}

    /**
     * @param  array<int, string>  $scopes
     */
    public function forScopes(array $scopes): string
    {
        $credentials = $this->credentials();
        $clientEmail = $this->requiredCredential($credentials, 'client_email');
        $privateKey = $this->requiredCredential($credentials, 'private_key');
        $tokenUri = (string) ($credentials['token_uri'] ?? self::DEFAULT_TOKEN_URI);

        if (! hash_equals(self::DEFAULT_TOKEN_URI, $tokenUri)) {
            throw new GoogleIntegrationException('GOOGLE_APPLICATION_CREDENTIALS содержит неподдерживаемый token_uri.');
        }

        $scopes = array_values(array_unique(array_filter($scopes)));

        if ($scopes === []) {
            throw new GoogleIntegrationException('Не заданы OAuth scopes для Google API.');
        }

        $cacheKey = 'google:service-account-token:'.hash('sha256', $clientEmail.'|'.implode(' ', $scopes));
        $cached = Cache::get($cacheKey);

        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $response = $this->http
            ->asForm()
            ->acceptJson()
            ->timeout(10)
            ->connectTimeout(3)
            ->retry([100, 300], throw: false)
            ->post($tokenUri, [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $this->jwt($clientEmail, $privateKey, $tokenUri, $scopes),
            ])
            ->throw()
            ->json();

        if (! is_array($response) || ! isset($response['access_token']) || ! is_string($response['access_token'])) {
            throw new GoogleIntegrationException('Google token response не содержит access_token.');
        }

        $ttl = max(60, ((int) ($response['expires_in'] ?? 3600)) - 60);
        Cache::put($cacheKey, $response['access_token'], $ttl);

        return $response['access_token'];
    }

    /**
     * @return array<string, mixed>
     */
    private function credentials(): array
    {
        $path = (string) config('services.google.application_credentials', '');

        if ($path === '') {
            throw new GoogleIntegrationException('GOOGLE_APPLICATION_CREDENTIALS не задан.');
        }

        if (! is_file($path) || ! is_readable($path)) {
            throw new GoogleIntegrationException('Файл GOOGLE_APPLICATION_CREDENTIALS недоступен.');
        }

        try {
            $credentials = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new GoogleIntegrationException('Файл GOOGLE_APPLICATION_CREDENTIALS содержит некорректный JSON.', previous: $exception);
        }

        if (! is_array($credentials)) {
            throw new GoogleIntegrationException('Файл GOOGLE_APPLICATION_CREDENTIALS должен содержать JSON object.');
        }

        return $credentials;
    }

    /**
     * @param  array<string, mixed>  $credentials
     */
    private function requiredCredential(array $credentials, string $key): string
    {
        $value = $credentials[$key] ?? null;

        if (! is_string($value) || $value === '') {
            throw new GoogleIntegrationException('В GOOGLE_APPLICATION_CREDENTIALS отсутствует '.$key.'.');
        }

        return $value;
    }

    /**
     * @param  array<int, string>  $scopes
     */
    private function jwt(string $clientEmail, string $privateKey, string $tokenUri, array $scopes): string
    {
        $now = time();
        $header = $this->base64UrlJson([
            'alg' => 'RS256',
            'typ' => 'JWT',
        ]);
        $payload = $this->base64UrlJson([
            'iss' => $clientEmail,
            'scope' => implode(' ', $scopes),
            'aud' => $tokenUri,
            'iat' => $now,
            'exp' => $now + 3600,
        ]);
        $unsigned = $header.'.'.$payload;
        $key = @openssl_pkey_get_private($privateKey);

        if ($key === false) {
            throw new GoogleIntegrationException('Не удалось прочитать private_key из GOOGLE_APPLICATION_CREDENTIALS.');
        }

        $signature = '';

        if (! openssl_sign($unsigned, $signature, $key, OPENSSL_ALGO_SHA256)) {
            throw new GoogleIntegrationException('Не удалось подписать Google service-account JWT.');
        }

        return $unsigned.'.'.$this->base64Url($signature);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function base64UrlJson(array $payload): string
    {
        try {
            return $this->base64Url(json_encode($payload, JSON_THROW_ON_ERROR));
        } catch (JsonException $exception) {
            throw new GoogleIntegrationException('Не удалось подготовить Google service-account JWT.', previous: $exception);
        }
    }

    private function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
