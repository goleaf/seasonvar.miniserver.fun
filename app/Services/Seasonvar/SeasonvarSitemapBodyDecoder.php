<?php

declare(strict_types=1);

namespace App\Services\Seasonvar;

use App\Support\NativeCall;
use ErrorException;
use RuntimeException;

final class SeasonvarSitemapBodyDecoder
{
    public function decode(string $body): string
    {
        $maximumBytes = max(
            1024,
            min(
                67_108_864,
                (int) config(
                    'seasonvar.http.sitemap_max_uncompressed_bytes',
                    33_554_432,
                ),
            ),
        );

        if (substr($body, 0, 2) !== chr(31).chr(139)) {
            $this->guardSize($body, $maximumBytes);

            return $body;
        }

        try {
            $decoded = NativeCall::withWarningsAsExceptions(
                static fn (): string|false => gzdecode($body, $maximumBytes + 1),
            );
        } catch (ErrorException) {
            throw new RuntimeException('Карта сайта Seasonvar повреждена или превышает допустимый размер.');
        }

        if (! is_string($decoded)) {
            throw new RuntimeException('Карта сайта Seasonvar повреждена или превышает допустимый размер.');
        }

        $this->guardSize($decoded, $maximumBytes);

        return $decoded;
    }

    private function guardSize(string $body, int $maximumBytes): void
    {
        if (mb_strlen($body, '8bit') > $maximumBytes) {
            throw new RuntimeException('Карта сайта Seasonvar превышает допустимый размер.');
        }
    }
}
