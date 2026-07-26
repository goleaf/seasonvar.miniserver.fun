<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\CatalogTitle;
use App\Models\Episode;
use App\Models\Season;
use App\Services\Media\ExternalPlaylistImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class ExternalPlaylistSubtitleLanguageImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_importer_persists_only_an_explicit_subtitle_language_marker(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'media.example.com/*' => Http::response('', 206, [
                'Content-Range' => 'bytes 0-0/1000',
            ]),
        ]);
        $title = CatalogTitle::factory()->create();
        $season = Season::factory()->create([
            'catalog_title_id' => $title->id,
            'number' => 1,
        ]);
        $episode = Episode::factory()->create([
            'season_id' => $season->id,
            'number' => 1,
        ]);

        app(ExternalPlaylistImporter::class)->importFromContent(
            "#EXTM3U\n#EXTINF:-1,Серия S01E01 Subtitles EN\nhttps://media.example.com/episode-s01e01.mp4",
            'https://playlist.example.com/list.m3u',
            $title,
        );

        $this->assertDatabaseHas('licensed_media', [
            'catalog_title_id' => $title->id,
            'season_id' => $season->id,
            'episode_id' => $episode->id,
            'has_subtitles' => true,
            'subtitle_language' => 'en',
        ]);
    }
}
