<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Seasonvar\SeasonvarSitemapBodyDecoder;
use RuntimeException;
use Tests\TestCase;

final class SeasonvarSitemapBodyDecoderTest extends TestCase
{
    public function test_it_rejects_compressed_and_plain_bodies_above_the_hard_limit(): void
    {
        config(['seasonvar.http.sitemap_max_uncompressed_bytes' => 1024]);
        $decoder = app(SeasonvarSitemapBodyDecoder::class);
        $oversized = str_repeat('x', 1025);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Карта сайта Seasonvar превышает допустимый размер.');

        $decoder->decode($oversized);
    }

    public function test_it_bounds_compressed_output_before_full_decompression(): void
    {
        config(['seasonvar.http.sitemap_max_uncompressed_bytes' => 1024]);

        $this->expectException(RuntimeException::class);

        app(SeasonvarSitemapBodyDecoder::class)->decode(gzencode(str_repeat('x', 1025)));
    }

    public function test_it_decodes_a_bounded_gzip_body_without_changing_plain_xml(): void
    {
        config(['seasonvar.http.sitemap_max_uncompressed_bytes' => 4096]);
        $decoder = app(SeasonvarSitemapBodyDecoder::class);
        $xml = '<?xml version="1.0"?><urlset/>';

        $this->assertSame($xml, $decoder->decode($xml));
        $this->assertSame($xml, $decoder->decode(gzencode($xml)));
    }
}
