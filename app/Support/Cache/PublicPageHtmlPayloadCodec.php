<?php

declare(strict_types=1);

namespace App\Support\Cache;

final class PublicPageHtmlPayloadCodec
{
    /** @return array{body: string, encoding: 'gzip'}|null */
    public function encode(string $html): ?array
    {
        if (strlen($html) > $this->maxUncompressedBytes()) {
            return null;
        }

        $body = gzencode($html, 6, ZLIB_ENCODING_GZIP);

        if (! is_string($body) || strlen($body) > $this->maxPayloadBytes()) {
            return null;
        }

        return [
            'body' => $body,
            'encoding' => 'gzip',
        ];
    }

    /** @param array<string, mixed> $payload */
    public function decode(array $payload): ?string
    {
        $body = $payload['body'] ?? null;

        if (! is_string($body)) {
            return null;
        }

        $encoding = $payload['encoding'] ?? 'identity';

        if ($encoding === 'identity') {
            return strlen($body) <= $this->maxUncompressedBytes() ? $body : null;
        }

        if ($encoding !== 'gzip' || strlen($body) > $this->maxPayloadBytes()) {
            return null;
        }

        $html = @gzdecode($body, $this->maxUncompressedBytes() + 1);

        return is_string($html) && strlen($html) <= $this->maxUncompressedBytes()
            ? $html
            : null;
    }

    private function maxPayloadBytes(): int
    {
        return max(1, (int) config('cache-architecture.page_cache.max_payload_bytes', 850_000));
    }

    private function maxUncompressedBytes(): int
    {
        return max(1, (int) config('cache-architecture.page_cache.max_uncompressed_payload_bytes', 1_500_000));
    }
}
