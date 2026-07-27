<?php

declare(strict_types=1);

namespace App\Services\Seasonvar;

use App\Support\NativeCall;
use ErrorException;
use JsonException;
use RuntimeException;

final class SeasonvarImportPayloadCodec
{
    private const JSON_CODEC = 'gzip-json-v1';

    private const TEXT_CODEC = 'gzip-text-v1';

    /**
     * @param  array<string, mixed>  $payload
     * @return array{blob: string, codec: string, uncompressed_bytes: int}
     */
    public function encodeJson(array $payload): array
    {
        try {
            $json = json_encode(
                $payload,
                JSON_THROW_ON_ERROR
                    | JSON_UNESCAPED_SLASHES
                    | JSON_UNESCAPED_UNICODE,
            );
        } catch (JsonException $exception) {
            throw new RuntimeException(
                'Payload Seasonvar нельзя сериализовать.',
                previous: $exception,
            );
        }

        return $this->encode($json, self::JSON_CODEC);
    }

    /**
     * @return array{blob: string, codec: string, uncompressed_bytes: int}
     */
    public function encodeString(string $value): array
    {
        return $this->encode($value, self::TEXT_CODEC);
    }

    /**
     * @return array<string, mixed>
     */
    public function decodeJson(
        string $blob,
        string $codec,
        int $uncompressedBytes,
    ): array {
        if ($codec !== self::JSON_CODEC) {
            throw new RuntimeException('Кодек payload Seasonvar не поддерживается.');
        }

        $json = $this->decode($blob, $uncompressedBytes);

        try {
            $payload = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException(
                'Payload Seasonvar повреждён.',
                previous: $exception,
            );
        }

        if (! is_array($payload)) {
            throw new RuntimeException('Payload Seasonvar имеет некорректную структуру.');
        }

        return $payload;
    }

    public function decodeString(
        string $blob,
        string $codec,
        int $uncompressedBytes,
    ): string {
        if ($codec !== self::TEXT_CODEC) {
            throw new RuntimeException('Кодек HTML-снимка Seasonvar не поддерживается.');
        }

        return $this->decode($blob, $uncompressedBytes);
    }

    /**
     * @return array{blob: string, codec: string, uncompressed_bytes: int}
     */
    private function encode(string $value, string $codec): array
    {
        $bytes = mb_strlen($value, '8bit');

        if ($bytes > $this->maximumBytes()) {
            throw new RuntimeException('Payload Seasonvar превышает допустимый размер.');
        }

        $blob = gzencode($value, 6);

        if (! is_string($blob)) {
            throw new RuntimeException('Payload Seasonvar не удалось сжать.');
        }

        return [
            'blob' => $blob,
            'codec' => $codec,
            'uncompressed_bytes' => $bytes,
        ];
    }

    private function decode(string $blob, int $uncompressedBytes): string
    {
        $maximumBytes = $this->maximumBytes();

        if ($uncompressedBytes < 0 || $uncompressedBytes > $maximumBytes) {
            throw new RuntimeException('Payload Seasonvar имеет недопустимый размер.');
        }

        try {
            $decoded = NativeCall::withWarningsAsExceptions(
                static fn (): string|false => gzdecode($blob, $maximumBytes + 1),
            );
        } catch (ErrorException) {
            throw new RuntimeException('Payload Seasonvar повреждён или превышает лимит.');
        }

        if (! is_string($decoded)
            || mb_strlen($decoded, '8bit') !== $uncompressedBytes
        ) {
            throw new RuntimeException('Payload Seasonvar повреждён или превышает лимит.');
        }

        return $decoded;
    }

    private function maximumBytes(): int
    {
        return max(
            1024,
            min(
                67_108_864,
                (int) config(
                    'seasonvar.import.compact_payload_max_uncompressed_bytes',
                    16_777_216,
                ),
            ),
        );
    }
}
