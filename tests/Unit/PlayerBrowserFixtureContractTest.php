<?php

declare(strict_types=1);

namespace Tests\Unit;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

final class PlayerBrowserFixtureContractTest extends TestCase
{
    public function test_browser_player_fixtures_are_local_text_and_explicitly_routed(): void
    {
        foreach ([
            'direct.mp4.b64', 'hls-init.mp4.b64', 'hls-segment.m4s.b64',
            'valid.m3u8', 'subtitles-ru.vtt',
        ] as $fixture) {
            $path = base_path('tests/browser/fixtures/player/'.$fixture);
            $this->assertFileExists($path);
            $this->assertGreaterThan(0, File::size($path));
        }

        $router = File::get(base_path('tests/browser/support/player-media-fixtures.js'));
        $fixtures = File::get(base_path('tests/browser/prepare-fixtures.php'));

        $this->assertStringContainsString("page.route('https://media.example.com/player-fixtures/**'", $router);
        $this->assertStringContainsString('Content-Range', $router);
        $this->assertStringContainsString('Buffer.from', $router);
        $this->assertStringContainsString('player-fixtures/valid.m3u8', $fixtures);
        $this->assertStringContainsString('player-fixtures/direct.mp4', $fixtures);
        $this->assertStringNotContainsString('public/', $router);
    }
}
