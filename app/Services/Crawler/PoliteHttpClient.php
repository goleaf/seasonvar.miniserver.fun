<?php

declare(strict_types=1);

namespace App\Services\Crawler;

use App\DTOs\VerifiedExternalUrlData;
use App\Exceptions\Crawler\RemoteResponseTooLargeException;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Psr\Http\Message\StreamInterface;
use Throwable;

final class PoliteHttpClient
{
    private const DEFAULT_MAX_RESPONSE_BYTES = 8_388_608;

    private const MAX_CONFIGURABLE_RESPONSE_BYTES = 67_108_864;

    private float $lastRequestAt = 0.0;

    /**
     * @param  (callable(string, array<string, mixed>): void)|null  $progress
     * @param  array<string, string>  $headers
     */
    public function get(
        string $url,
        int $delaySeconds = 3,
        ?callable $progress = null,
        array $headers = [],
        ?int $maxResponseBytes = null,
    ): Response {
        return $this->request(
            $url,
            $delaySeconds,
            $progress,
            $headers,
            $maxResponseBytes,
        );
    }

    /**
     * @param  (callable(string, array<string, mixed>): void)|null  $progress
     * @param  array<string, string>  $headers
     */
    public function getVerified(
        VerifiedExternalUrlData $target,
        int $delaySeconds = 3,
        ?callable $progress = null,
        array $headers = [],
        ?int $maxResponseBytes = null,
    ): Response {
        return $this->request(
            $target->url,
            $delaySeconds,
            $progress,
            $headers,
            $maxResponseBytes,
            $target->httpOptions(),
        );
    }

    /**
     * Open a validated upstream response without reading its body.
     *
     * The caller owns the returned stream and must close the response.
     *
     * @param  array<string, string>  $headers
     */
    public function requestVerifiedStream(
        VerifiedExternalUrlData $target,
        string $method,
        array $headers = [],
        int $timeoutSeconds = 10,
        int $connectTimeoutSeconds = 5,
        int $attempts = 1,
        int $retrySleepMilliseconds = 250,
    ): Response {
        $safeHeaders = collect($headers)
            ->only(['Accept', 'Accept-Encoding', 'Range', 'Referer'])
            ->filter(fn (mixed $value): bool => is_string($value) && trim($value) !== '')
            ->map(fn (string $value): string => trim($value))
            ->all();
        $attempts = max(1, min(3, $attempts));
        $timeoutSeconds = max(1, min(21_600, $timeoutSeconds));
        $connectTimeoutSeconds = max(1, min($timeoutSeconds, $connectTimeoutSeconds));

        return Http::withHeaders([
            'Accept' => '*/*',
            'User-Agent' => 'SeasonvarCatalog/0.1 (+https://seasonvar.miniserver.fun)',
            ...$safeHeaders,
        ])
            ->withOptions($target->httpOptions())
            ->withoutRedirecting()
            ->withOptions(['stream' => true])
            ->timeout($timeoutSeconds)
            ->connectTimeout($connectTimeoutSeconds)
            ->retry(
                $attempts,
                max(0, min(5000, $retrySleepMilliseconds)),
                $this->shouldRetry(...),
                throw: false,
            )
            ->send(strtoupper($method), $target->url);
    }

    /**
     * @param  (callable(string, array<string, mixed>): void)|null  $progress
     * @param  array<string, string>  $headers
     * @param  array<string, mixed>  $transportOptions
     */
    private function request(
        string $url,
        int $delaySeconds,
        ?callable $progress,
        array $headers,
        ?int $maxResponseBytes,
        array $transportOptions = [],
    ): Response {
        $this->waitForDelay($delaySeconds, $url, $progress);
        $maxResponseBytes = $this->responseLimit($maxResponseBytes);

        $startedAt = microtime(true);
        $this->report($progress, 'http-request-started', [
            'url' => $url,
            'timeout_seconds' => 20,
            'connect_timeout_seconds' => 10,
            'max_response_bytes' => $maxResponseBytes,
        ]);

        $safeHeaders = collect($headers)
            ->only(['If-None-Match', 'If-Modified-Since', 'Accept'])
            ->filter(fn (string $value): bool => trim($value) !== '')
            ->map(fn (string $value): string => trim($value))
            ->all();
        $response = Http::withHeaders([
            'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
            'User-Agent' => 'SeasonvarCatalog/0.1 (+https://seasonvar.miniserver.fun)',
            ...$safeHeaders,
        ])
            ->withOptions($transportOptions)
            ->withoutRedirecting()
            ->withOptions(['stream' => true])
            ->timeout(20)
            ->connectTimeout(10)
            ->retry(2, 1000, $this->shouldRetry(...), throw: false)
            ->get($url);

        try {
            $response = $this->readBoundedResponse($response, $maxResponseBytes);
        } catch (RemoteResponseTooLargeException $exception) {
            $this->lastRequestAt = microtime(true);
            $this->report($progress, 'http-response-rejected', [
                'url' => $url,
                'reason' => 'response_too_large',
                'max_response_bytes' => $exception->maximumBytes,
                'duration_seconds' => $this->lastRequestAt - $startedAt,
            ]);

            throw $exception;
        } finally {
            $this->lastRequestAt = microtime(true);
        }

        $this->report($progress, 'http-request-complete', [
            'url' => $url,
            'http_status' => $response->status(),
            'successful' => $response->successful(),
            'body_bytes' => mb_strlen($response->body(), '8bit'),
            'duration_seconds' => $this->lastRequestAt - $startedAt,
        ]);

        return $response;
    }

    private function readBoundedResponse(Response $response, int $maximumBytes): Response
    {
        $psrResponse = $response->toPsrResponse();
        $stream = $psrResponse->getBody();

        if ($stream->isSeekable()) {
            $stream->rewind();
        }

        $contentLength = trim($response->header('Content-Length'));

        if (ctype_digit($contentLength) && (int) $contentLength > $maximumBytes) {
            $this->releaseSourceStream($response, $stream);

            throw new RemoteResponseTooLargeException($maximumBytes);
        }

        $body = '';

        while (! $this->streamEnded($stream)) {
            $remainingBytes = $maximumBytes - strlen($body);
            $chunk = $stream->read(min(16_384, $remainingBytes + 1));

            if ($chunk === '') {
                if ($this->streamEnded($stream)) {
                    break;
                }

                $this->releaseSourceStream($response, $stream);
                throw new ConnectionException('Чтение ответа внешнего источника остановилось до завершения передачи.');
            }

            $body .= $chunk;

            if (strlen($body) > $maximumBytes) {
                $this->releaseSourceStream($response, $stream);

                throw new RemoteResponseTooLargeException($maximumBytes);
            }
        }

        $boundedResponse = new Response($psrResponse->withBody(Utils::streamFor($body)));
        $boundedResponse->cookies = $response->cookies;
        $boundedResponse->transferStats = $response->transferStats;
        $this->releaseSourceStream($response, $stream);

        return $boundedResponse;
    }

    private function releaseSourceStream(Response $response, StreamInterface $stream): void
    {
        if ($stream->isSeekable()) {
            $stream->rewind();

            return;
        }

        $response->close();
    }

    /** @phpstan-impure */
    private function streamEnded(StreamInterface $stream): bool
    {
        return $stream->eof();
    }

    private function responseLimit(?int $maximumBytes): int
    {
        $configured = $maximumBytes
            ?? (int) config('seasonvar.http.max_response_bytes', self::DEFAULT_MAX_RESPONSE_BYTES);

        return max(1, min(self::MAX_CONFIGURABLE_RESPONSE_BYTES, $configured));
    }

    private function shouldRetry(?Throwable $exception): bool
    {
        if ($exception instanceof ConnectionException) {
            return true;
        }

        if (! $exception instanceof RequestException) {
            return false;
        }

        $status = $exception->response->status();

        return in_array($status, [408, 425, 429], true) || $status >= 500;
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
