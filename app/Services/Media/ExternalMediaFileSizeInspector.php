<?php

declare(strict_types=1);

namespace App\Services\Media;

use App\DTOs\ExternalMediaFileSizeResultData;
use App\DTOs\VerifiedExternalUrlData;
use App\Models\LicensedMedia;
use App\Services\Crawler\PoliteHttpClient;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;
use Throwable;

final class ExternalMediaFileSizeInspector
{
    public function __construct(
        private readonly ExternalMediaUrlGuard $urls,
        private readonly ExternalMediaFileType $fileTypes,
        private readonly PoliteHttpClient $http,
    ) {}

    public function inspect(LicensedMedia $media): ExternalMediaFileSizeResultData
    {
        $checkedAt = now();

        if ($this->fileTypes->isPlaylist($media)) {
            return ExternalMediaFileSizeResultData::unsupported(
                'format',
                $checkedAt,
                'playlist_manifest',
                'playlist_manifest_is_not_complete_video',
            );
        }

        if (! $this->fileTypes->isDirect($media)) {
            return ExternalMediaFileSizeResultData::unsupported(
                'format',
                $checkedAt,
                'unsupported_format',
                'media_is_not_a_supported_direct_file',
            );
        }

        $url = $this->fileTypes->effectiveUrl($media);
        $target = $this->urls->verifiedExternalUrl($url, ['http', 'https'], enforcePublicDns: true);

        if ($target === null) {
            return ExternalMediaFileSizeResultData::failed(
                'url-validation',
                null,
                $checkedAt,
                'invalid_url',
                'external_media_url_is_not_allowed',
            );
        }

        try {
            $head = $this->request($target, 'HEAD');

            try {
                $headResult = $this->headResult($media, $head, $checkedAt, $target->url);
            } finally {
                $head->close();
            }

            if ($headResult !== null) {
                return $headResult;
            }

            $range = $this->request($target, 'GET', ['Range' => 'bytes=0-0']);

            try {
                return $this->rangeResult($media, $range, $checkedAt, $target->url);
            } finally {
                $range->close();
            }
        } catch (Throwable $exception) {
            return ExternalMediaFileSizeResultData::failed(
                'http',
                null,
                $checkedAt,
                $this->exceptionCategory($exception),
                'external_media_metadata_request_failed',
                resolvedUrl: $target->url,
            );
        }
    }

    /** @param array<string, string> $headers */
    private function request(VerifiedExternalUrlData $target, string $method, array $headers = []): Response
    {
        return $this->http->requestVerifiedStream(
            $target,
            $method,
            [
                'Accept' => '*/*',
                'Accept-Encoding' => 'identity',
                ...$headers,
            ],
            $this->timeoutSeconds(),
            $this->connectTimeoutSeconds(),
            $this->attempts(),
            $this->retrySleepMilliseconds(),
        );
    }

    private function headResult(
        LicensedMedia $media,
        Response $response,
        Carbon $checkedAt,
        string $resolvedUrl,
    ): ?ExternalMediaFileSizeResultData {
        $status = $response->status();
        $contentType = $this->fileTypes->normalizedContentType($response->header('Content-Type'));
        $acceptRanges = $this->acceptRanges($response);

        if (in_array($status, [405, 501], true)) {
            return null;
        }

        if ($status >= 200 && $status < 300 && $status !== 200) {
            return null;
        }

        if ($status !== 200) {
            return $this->httpFailure('head', $status, $checkedAt, $contentType, $acceptRanges, $resolvedUrl);
        }

        if ($this->hasUnsafeContentEncoding($response)) {
            return null;
        }

        $unsafeContent = $this->unsafeContentResult(
            $media,
            'head',
            $status,
            $checkedAt,
            $contentType,
            $resolvedUrl,
        );

        if ($unsafeContent !== null) {
            return $unsafeContent;
        }

        $bytes = $this->nonNegativeInteger($response->header('Content-Length'));

        if ($bytes === null) {
            return null;
        }

        return ExternalMediaFileSizeResultData::known(
            $bytes,
            'head-content-length',
            $status,
            $checkedAt,
            $contentType,
            $acceptRanges,
            $resolvedUrl,
        );
    }

    private function rangeResult(
        LicensedMedia $media,
        Response $response,
        Carbon $checkedAt,
        string $resolvedUrl,
    ): ExternalMediaFileSizeResultData {
        $status = $response->status();
        $contentType = $this->fileTypes->normalizedContentType($response->header('Content-Type'));
        $acceptRanges = $this->acceptRanges($response);
        $unsafeContent = $this->unsafeContentResult(
            $media,
            'range',
            $status,
            $checkedAt,
            $contentType,
            $resolvedUrl,
        );

        if ($unsafeContent !== null) {
            return $unsafeContent;
        }

        if ($this->hasUnsafeContentEncoding($response)) {
            return ExternalMediaFileSizeResultData::unknown(
                'range-content-encoding',
                $status,
                $checkedAt,
                'encoded_representation',
                'upstream_ignored_identity_content_encoding',
                $contentType,
                $acceptRanges,
                $resolvedUrl,
            );
        }

        if ($status === 206) {
            $bytes = $this->totalFromContentRange($response->header('Content-Range'));

            if ($bytes !== null) {
                return ExternalMediaFileSizeResultData::known(
                    $bytes,
                    'ranged-content-range',
                    $status,
                    $checkedAt,
                    $contentType,
                    $acceptRanges,
                    $resolvedUrl,
                );
            }

            return ExternalMediaFileSizeResultData::failed(
                'ranged-content-range',
                $status,
                $checkedAt,
                'malformed_content_range',
                'upstream_content_range_is_invalid',
                $contentType,
                $acceptRanges,
                $resolvedUrl,
            );
        }

        if ($status === 416 && preg_match('/^bytes\s+\*\/0$/iD', trim($response->header('Content-Range'))) === 1) {
            return ExternalMediaFileSizeResultData::known(
                0,
                'ranged-content-range',
                $status,
                $checkedAt,
                $contentType,
                $acceptRanges,
                $resolvedUrl,
            );
        }

        if ($status === 200) {
            return ExternalMediaFileSizeResultData::unknown(
                'range-ignored',
                $status,
                $checkedAt,
                'range_unsupported',
                'upstream_ignored_single_byte_range',
                $contentType,
                $acceptRanges,
                $resolvedUrl,
            );
        }

        return $this->httpFailure('range', $status, $checkedAt, $contentType, $acceptRanges, $resolvedUrl);
    }

    private function unsafeContentResult(
        LicensedMedia $media,
        string $source,
        int $status,
        Carbon $checkedAt,
        string $contentType,
        string $resolvedUrl,
    ): ?ExternalMediaFileSizeResultData {
        if ($this->fileTypes->isPlaylist($media, $contentType)) {
            return ExternalMediaFileSizeResultData::unsupported(
                $source.'-content-type',
                $checkedAt,
                'playlist_manifest',
                'playlist_manifest_is_not_complete_video',
                $status,
                $contentType,
                $resolvedUrl,
            );
        }

        if ($this->fileTypes->isHtmlContentType($contentType)) {
            return ExternalMediaFileSizeResultData::failed(
                $source.'-content-type',
                $status,
                $checkedAt,
                'html_response',
                'upstream_returned_html_instead_of_media',
                $contentType,
                resolvedUrl: $resolvedUrl,
            );
        }

        return null;
    }

    private function httpFailure(
        string $source,
        int $status,
        Carbon $checkedAt,
        string $contentType,
        ?string $acceptRanges,
        string $resolvedUrl,
    ): ExternalMediaFileSizeResultData {
        $category = match (true) {
            $status >= 300 && $status < 400 => 'unsafe_redirect',
            in_array($status, [401, 403], true) => 'authentication',
            in_array($status, [404, 410], true) => 'not_found',
            $status === 416 => 'range_not_satisfiable',
            $status === 429 => 'rate_limited',
            in_array($status, [408, 425], true) || $status >= 500 => 'provider_temporary',
            default => 'unexpected_http_status',
        };

        return ExternalMediaFileSizeResultData::failed(
            $source.'-http',
            $status,
            $checkedAt,
            $category,
            'external_media_metadata_http_failure',
            $contentType,
            $acceptRanges,
            $resolvedUrl,
        );
    }

    private function nonNegativeInteger(string $value): ?int
    {
        $value = trim($value);

        if (preg_match('/^(?:0|[1-9][0-9]*)$/D', $value) !== 1) {
            return null;
        }

        $maximum = (string) PHP_INT_MAX;

        if (strlen($value) > strlen($maximum)
            || (strlen($value) === strlen($maximum) && strcmp($value, $maximum) > 0)) {
            return null;
        }

        return (int) $value;
    }

    private function totalFromContentRange(string $value): ?int
    {
        if (preg_match('/^bytes\s+0-0\/([0-9]+)$/iD', trim($value), $matches) !== 1) {
            return null;
        }

        $total = $this->nonNegativeInteger($matches[1]);

        return $total !== null && $total >= 1 ? $total : null;
    }

    private function acceptRanges(Response $response): ?string
    {
        $value = strtolower(trim($response->header('Accept-Ranges')));

        return $value === 'bytes' ? 'bytes' : null;
    }

    private function hasUnsafeContentEncoding(Response $response): bool
    {
        $value = strtolower(trim($response->header('Content-Encoding')));

        return $value !== '' && $value !== 'identity';
    }

    private function exceptionCategory(Throwable $exception): string
    {
        $name = strtolower($exception::class);

        return str_contains($name, 'connection') ? 'connection' : 'unexpected_exception';
    }

    private function attempts(): int
    {
        return max(1, min(3, (int) config('seasonvar.media_file_size.retry_times', 2)));
    }

    private function retrySleepMilliseconds(): int
    {
        return max(0, min(5000, (int) config('seasonvar.media_file_size.retry_sleep_milliseconds', 250)));
    }

    private function timeoutSeconds(): int
    {
        return max(1, min(30, (int) config('seasonvar.media_file_size.timeout_seconds', 10)));
    }

    private function connectTimeoutSeconds(): int
    {
        return max(1, min(
            $this->timeoutSeconds(),
            (int) config('seasonvar.media_file_size.connect_timeout_seconds', 5),
        ));
    }
}
