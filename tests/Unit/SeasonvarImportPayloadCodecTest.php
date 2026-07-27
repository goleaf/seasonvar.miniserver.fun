<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Seasonvar\SeasonvarImportPayloadCodec;
use RuntimeException;
use Tests\TestCase;

final class SeasonvarImportPayloadCodecTest extends TestCase
{
    public function test_json_and_unicode_text_round_trip_without_value_drift(): void
    {
        $codec = app(SeasonvarImportPayloadCodec::class);
        $payload = [
            'title' => 'Синий экзорцист — сезон 2',
            'urls' => [
                'https://seasonvar.ru/serial-1-Test_pshash-2-season.html',
            ],
            'nested' => [
                'enabled' => true,
                'count' => 2600,
                'repeated' => str_repeat('сезон-серия-', 200),
            ],
        ];

        $encodedJson = $codec->encodeJson($payload);
        $encodedText = $codec->encodeString('Русский текст ✓');

        $this->assertSame('gzip-json-v1', $encodedJson['codec']);
        $this->assertSame($payload, $codec->decodeJson(
            $encodedJson['blob'],
            $encodedJson['codec'],
            $encodedJson['uncompressed_bytes'],
        ));
        $this->assertSame('Русский текст ✓', $codec->decodeString(
            $encodedText['blob'],
            $encodedText['codec'],
            $encodedText['uncompressed_bytes'],
        ));
        $this->assertLessThan(
            $encodedJson['uncompressed_bytes'],
            mb_strlen($encodedJson['blob'], '8bit'),
        );
    }

    public function test_corrupt_and_oversized_payloads_are_rejected_before_use(): void
    {
        config([
            'seasonvar.import.compact_payload_max_uncompressed_bytes' => 128,
        ]);
        $codec = app(SeasonvarImportPayloadCodec::class);
        $encoded = $codec->encodeString(str_repeat('a', 128));

        $this->expectException(RuntimeException::class);
        $codec->decodeString(
            substr($encoded['blob'], 0, -3),
            $encoded['codec'],
            $encoded['uncompressed_bytes'],
        );
    }

    public function test_declared_uncompressed_size_cannot_bypass_the_hard_limit(): void
    {
        config([
            'seasonvar.import.compact_payload_max_uncompressed_bytes' => 128,
        ]);
        $codec = app(SeasonvarImportPayloadCodec::class);
        $bomb = gzencode(str_repeat('x', 1024), 6);
        $this->assertIsString($bomb);

        $this->expectException(RuntimeException::class);
        $codec->decodeString($bomb, 'gzip-text-v1', 64);
    }
}
