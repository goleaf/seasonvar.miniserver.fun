<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\Cache\PublicPageHtmlPayloadCodec;
use Tests\TestCase;

final class PublicPageHtmlPayloadCodecTest extends TestCase
{
    public function test_it_compresses_and_restores_a_large_bounded_html_payload(): void
    {
        config([
            'cache-architecture.page_cache.max_payload_bytes' => 850_000,
            'cache-architecture.page_cache.max_uncompressed_payload_bytes' => 1_500_000,
        ]);
        $html = '<html>'.str_repeat('Статистика каталога ', 25_000).'</html>';

        $payload = app(PublicPageHtmlPayloadCodec::class)->encode($html);

        $this->assertIsArray($payload);
        $this->assertSame('gzip', $payload['encoding']);
        $this->assertLessThan(850_000, strlen($payload['body']));
        $this->assertSame($html, app(PublicPageHtmlPayloadCodec::class)->decode($payload));
    }

    public function test_it_rejects_oversized_or_corrupt_payloads_and_reads_legacy_plain_html(): void
    {
        config([
            'cache-architecture.page_cache.max_payload_bytes' => 850_000,
            'cache-architecture.page_cache.max_uncompressed_payload_bytes' => 1_500_000,
        ]);
        $codec = app(PublicPageHtmlPayloadCodec::class);

        $this->assertNull($codec->encode(str_repeat('x', 1_500_001)));
        $this->assertNull($codec->decode(['body' => 'not-gzip', 'encoding' => 'gzip']));
        $this->assertNull($codec->decode(['body' => '<html></html>', 'encoding' => 'unknown']));
        $this->assertSame('<html>legacy</html>', $codec->decode(['body' => '<html>legacy</html>']));
    }
}
