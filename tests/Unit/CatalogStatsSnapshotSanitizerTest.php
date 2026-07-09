<?php

namespace Tests\Unit;

use App\Services\Catalog\CatalogStatsSnapshotSanitizer;
use Tests\TestCase;

class CatalogStatsSnapshotSanitizerTest extends TestCase
{
    public function test_it_hides_external_urls_but_keeps_internal_stats_links(): void
    {
        $sanitizer = app(CatalogStatsSnapshotSanitizer::class);

        $data = $sanitizer->sanitize([
            'internal' => route('stats.poster', ['catalogTitle' => 'testovyi-serial']),
            'source' => 'https://seasonvar.ru/serial-777-Test-1-season.html',
            'media' => 'https://cdn.example.com/private-video.m3u8',
            'source_name' => 'Seasonvar',
            'nested' => [
                'text' => 'Видео: https://media.example.com/private-playback.m3u8',
            ],
        ]);

        $this->assertSame(route('stats.poster', ['catalogTitle' => 'testovyi-serial']), $data['internal']);
        $this->assertSame('скрыто', $data['source']);
        $this->assertSame('скрыто', $data['media']);
        $this->assertSame('скрыто', $data['source_name']);
        $this->assertSame('скрыто', $data['nested']['text']);
    }
}
