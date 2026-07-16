<?php

declare(strict_types=1);

namespace App\Services\Media;

use App\DTOs\LicensedMediaDownloadData;
use App\DTOs\SingleByteRangeData;
use App\Enums\MediaFileSizeCheckStatus;
use App\Models\CatalogTitle;
use App\Models\LicensedMedia;
use App\Models\User;
use App\Services\Catalog\CatalogCacheInvalidator;
use App\Services\Crawler\PoliteHttpClient;
use Illuminate\Http\Client\Response as UpstreamResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

final class StreamLicensedMediaDownload
{
    public function __construct(
        private readonly LicensedMediaDownloadEligibility $eligibility,
        private readonly LicensedMediaDownloadFilename $filenames,
        private readonly SingleByteRange $ranges,
        private readonly PoliteHttpClient $http,
        private readonly ExternalMediaFileType $fileTypes,
        private readonly CatalogCacheInvalidator $cache,
    ) {}

    public function response(
        Request $request,
        User $user,
        CatalogTitle $title,
        LicensedMedia $media,
    ): Response {
        if (! $request->isMethod('GET')) {
            return response(__('catalog.download.failed'), 405, [
                ...$this->privateHeaders(),
                'Allow' => 'GET',
            ]);
        }

        $download = $this->eligibility->resolve($user, $title, $media);

        if (! $download->eligible
            || $download->target === null
            || $download->extension === null
            || $download->contentType === null) {
            return $this->errorResponse(__($download->reasonKey), 404);
        }

        try {
            $range = $this->ranges->parse(
                $request->header('Range'),
                null,
            );
        } catch (InvalidArgumentException) {
            return $this->rangeError(null);
        }

        try {
            $upstream = $this->openUpstream($download, $range);
        } catch (Throwable) {
            return $this->errorResponse(__('catalog.download.remote_unavailable'), 502);
        }

        try {
            $validated = $this->validatedUpstream($upstream, $range, $media);
        } catch (Throwable) {
            $upstream->close();

            return $this->errorResponse(__('catalog.download.remote_unavailable'), 502);
        }

        if ($validated instanceof Response) {
            $upstream->close();

            return $validated;
        }

        $filename = $this->filenames->generate($title, $media, $download->extension);
        $headers = $this->downloadHeaders(
            $filename,
            $download->contentType,
            $validated['content_length'],
            $validated['content_range'],
            $validated['accept_ranges'],
        );
        $this->synchronizeKnownSize($media, $validated['total_bytes'], $validated['http_status']);

        return new StreamedResponse(
            function () use ($upstream): void {
                if (session_status() === PHP_SESSION_ACTIVE) {
                    session_write_close();
                }

                $body = $upstream->toPsrResponse()->getBody();
                $chunkBytes = max(8192, min(
                    1_048_576,
                    (int) config('playback.downloads.chunk_bytes', 65_536),
                ));

                try {
                    while (! $body->eof() && connection_aborted() === 0) {
                        $chunk = $body->read($chunkBytes);

                        if ($chunk === '') {
                            break;
                        }

                        echo $chunk;

                        if (ob_get_level() > 0) {
                            ob_flush();
                        }

                        flush();
                    }
                } finally {
                    $body->close();
                    $upstream->close();
                }
            },
            $validated['http_status'],
            $headers,
        );
    }

    private function openUpstream(
        LicensedMediaDownloadData $download,
        ?SingleByteRangeData $range,
    ): UpstreamResponse {
        return $this->http->requestVerifiedStream(
            $download->target,
            'GET',
            array_filter([
                'Accept' => $download->contentType,
                'Accept-Encoding' => 'identity',
                'Range' => $range?->header,
            ]),
            max(1, min(21_600, (int) config('playback.downloads.timeout_seconds', 3600))),
            max(1, min(30, (int) config('playback.downloads.connect_timeout_seconds', 10))),
            max(1, min(2, (int) config('playback.downloads.retry_times', 1))),
            max(0, min(5000, (int) config('playback.downloads.retry_sleep_milliseconds', 250))),
        );
    }

    /**
     * @return array{http_status: int, content_length: int|null, content_range: string|null, accept_ranges: bool, total_bytes: int|null}|Response
     */
    private function validatedUpstream(
        UpstreamResponse $upstream,
        ?SingleByteRangeData $range,
        LicensedMedia $media,
    ): array|Response {
        $status = $upstream->status();
        $contentType = $this->fileTypes->normalizedContentType($upstream->header('Content-Type'));
        $contentEncoding = strtolower(trim($upstream->header('Content-Encoding')));

        if ($status >= 300 && $status < 400) {
            return $this->errorResponse(__('catalog.download.remote_unavailable'), 502);
        }

        if ($this->fileTypes->isHtmlContentType($contentType)
            || $this->fileTypes->isPlaylist($media, $contentType)
            || ($contentEncoding !== '' && $contentEncoding !== 'identity')) {
            return $this->errorResponse(__('catalog.download.remote_unavailable'), 502);
        }

        if ($range === null) {
            if ($status !== 200) {
                return $status === 416
                    ? $this->rangeError($this->totalFromUnsatisfiedRange($upstream->header('Content-Range')))
                    : $this->errorResponse(__('catalog.download.remote_unavailable'), $this->downstreamFailureStatus($status));
            }

            $contentLength = $this->nonNegativeInteger($upstream->header('Content-Length'));

            return [
                'http_status' => 200,
                'content_length' => $contentLength,
                'content_range' => null,
                'accept_ranges' => strtolower(trim($upstream->header('Accept-Ranges'))) === 'bytes',
                'total_bytes' => $this->nonNegativeInteger($upstream->header('Content-Length')),
            ];
        }

        if ($status === 416) {
            return $this->rangeError($this->totalFromUnsatisfiedRange($upstream->header('Content-Range')));
        }

        if ($status !== 206) {
            return $this->rangeError(null);
        }

        $parsedRange = $this->parseContentRange($upstream->header('Content-Range'));

        if ($parsedRange === null
            || ($range->start !== null && $parsedRange['start'] !== $range->start)
            || ($range->end !== null && $parsedRange['end'] > $range->end)) {
            return $this->errorResponse(__('catalog.download.invalid_range'), 502);
        }

        $contentLength = $parsedRange['end'] - $parsedRange['start'] + 1;
        $reportedLength = $this->nonNegativeInteger($upstream->header('Content-Length'));

        if (($range->suffixLength !== null
                && ($parsedRange['end'] !== $parsedRange['total'] - 1
                    || $contentLength !== min($range->suffixLength, $parsedRange['total'])))
            || ($reportedLength !== null && $reportedLength !== $contentLength)) {
            return $this->errorResponse(__('catalog.download.invalid_range'), 502);
        }

        return [
            'http_status' => 206,
            'content_length' => $contentLength,
            'content_range' => sprintf(
                'bytes %d-%d/%d',
                $parsedRange['start'],
                $parsedRange['end'],
                $parsedRange['total'],
            ),
            'accept_ranges' => true,
            'total_bytes' => $parsedRange['total'],
        ];
    }

    /**
     * @return array{start: int, end: int, total: int}|null
     */
    private function parseContentRange(string $header): ?array
    {
        if (preg_match('/^bytes\s+([0-9]+)-([0-9]+)\/([0-9]+)$/iD', trim($header), $matches) !== 1) {
            return null;
        }

        $start = $this->nonNegativeInteger($matches[1]);
        $end = $this->nonNegativeInteger($matches[2]);
        $total = $this->nonNegativeInteger($matches[3]);

        if ($start === null || $end === null || $total === null || $end < $start || $total <= $end) {
            return null;
        }

        return ['start' => $start, 'end' => $end, 'total' => $total];
    }

    private function totalFromUnsatisfiedRange(string $header): ?int
    {
        if (preg_match('/^bytes\s+\*\/([0-9]+)$/iD', trim($header), $matches) !== 1) {
            return null;
        }

        return $this->nonNegativeInteger($matches[1]);
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

    /** @return array<string, string> */
    private function downloadHeaders(
        string $filename,
        string $contentType,
        ?int $contentLength,
        ?string $contentRange,
        bool $acceptRanges,
    ): array {
        $headers = [
            'Content-Type' => $contentType,
            'Content-Disposition' => 'attachment; filename="'.$filename.'"; filename*=UTF-8\'\''.rawurlencode($filename),
            'Cache-Control' => 'private, no-store, max-age=0',
            'Pragma' => 'no-cache',
            'Referrer-Policy' => 'no-referrer',
            'X-Content-Type-Options' => 'nosniff',
            'X-Robots-Tag' => 'noindex, nofollow',
        ];

        if ($contentLength !== null) {
            $headers['Content-Length'] = (string) $contentLength;
        }

        if ($contentRange !== null) {
            $headers['Content-Range'] = $contentRange;
        }

        if ($acceptRanges) {
            $headers['Accept-Ranges'] = 'bytes';
        }

        return $headers;
    }

    private function rangeError(?int $total): Response
    {
        $headers = $this->privateHeaders();

        if ($total !== null) {
            $headers['Content-Range'] = 'bytes */'.$total;
        }

        return response(__('catalog.download.invalid_range'), 416, $headers);
    }

    private function errorResponse(string $message, int $status): Response
    {
        return response($message, $status, $this->privateHeaders());
    }

    /** @return array<string, string> */
    private function privateHeaders(): array
    {
        return [
            'Cache-Control' => 'private, no-store, max-age=0',
            'Pragma' => 'no-cache',
            'X-Content-Type-Options' => 'nosniff',
            'X-Robots-Tag' => 'noindex, nofollow',
        ];
    }

    private function downstreamFailureStatus(int $upstreamStatus): int
    {
        return in_array($upstreamStatus, [401, 403, 404, 410], true) ? 404 : 502;
    }

    private function synchronizeKnownSize(LicensedMedia $media, ?int $bytes, int $httpStatus): void
    {
        if ($bytes === null || ($media->hasKnownFileSize() && $media->file_size_bytes === $bytes)) {
            return;
        }

        try {
            $attributes = [
                'file_size_bytes' => $bytes,
                'file_size_checked_at' => now(),
                'file_size_check_status' => MediaFileSizeCheckStatus::Known,
                'file_size_source' => $httpStatus === 206 ? 'download-content-range' : 'download-content-length',
                'file_size_http_status' => $httpStatus,
                'file_size_check_error' => null,
            ];
            $updated = LicensedMedia::query()
                ->whereKey($media->getKey())
                ->where('catalog_title_id', $media->catalog_title_id)
                ->where('playback_url', $media->playback_url)
                ->where('path', $media->path)
                ->where('format', $media->format)
                ->update($attributes);

            if ($updated !== 1) {
                return;
            }

            $media->forceFill($attributes);
            $this->cache->importedTitleChanged((int) $media->catalog_title_id);
        } catch (Throwable) {
            // Metadata repair is best-effort and must never interrupt an authorized stream.
        }
    }
}
