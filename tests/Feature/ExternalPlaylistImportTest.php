<?php

namespace Tests\Feature;

use App\Enums\ReleaseKind;
use App\Models\CatalogTitle;
use App\Models\Episode;
use App\Models\LicensedMedia;
use App\Models\Season;
use App\Models\Source;
use App\Models\SourcePage;
use App\Services\Media\ExternalPlaylistImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ExternalPlaylistImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_imports_external_playlist_files_for_the_local_player(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'playlist.example.com/*' => Http::response(<<<'M3U'
                #EXTM3U
                #EXTINF:-1,6 кадров S01E02
                https://media.example.com/files/6_kadrov_s01e02.mp4
                M3U),
        ]);

        $source = Source::factory()->create(['code' => 'seasonvar']);
        $page = SourcePage::factory()->create(['source_id' => $source->id]);
        $catalogTitle = CatalogTitle::factory()->create([
            'source_id' => $source->id,
            'source_page_id' => $page->id,
            'slug' => '6-kadrov',
            'title' => '6 кадров',
        ]);
        $season = Season::factory()->create([
            'catalog_title_id' => $catalogTitle->id,
            'number' => 1,
        ]);
        $episode = Episode::factory()->create([
            'season_id' => $season->id,
            'number' => 2,
        ]);
        $specialSeason = Season::factory()->create([
            'catalog_title_id' => $catalogTitle->id,
            'kind' => ReleaseKind::Special,
            'number' => 1,
        ]);
        Episode::factory()->create([
            'season_id' => $specialSeason->id,
            'kind' => ReleaseKind::Special,
            'number' => 2,
        ]);

        app(ExternalPlaylistImporter::class)->importFromUrl('https://playlist.example.com/list.m3u');

        $this->assertDatabaseHas('licensed_media', [
            'catalog_title_id' => $catalogTitle->id,
            'season_id' => $season->id,
            'episode_id' => $episode->id,
            'title' => '6 кадров S01E02',
            'storage_disk' => 'external_playlist',
            'playback_url' => 'https://media.example.com/files/6_kadrov_s01e02.mp4',
            'status' => 'published',
        ]);
        $media = LicensedMedia::query()
            ->where('catalog_title_id', $catalogTitle->id)
            ->where('episode_id', $episode->id)
            ->sole();

        $this->get(route('titles.show', $catalogTitle))
            ->assertOk()
            ->assertSeeText('Начать с 2 серии')
            ->assertSeeText('Выбрана 2 серия')
            ->assertSee('/playback/'.$media->id.'?', false)
            ->assertDontSee('https://media.example.com/files/6_kadrov_s01e02.mp4', false);

        $this->get(route('titles.show', ['catalogTitle' => $catalogTitle, 'episode' => $episode->id]))
            ->assertOk()
            ->assertSeeText('Выбрана 2 серия')
            ->assertDontSeeText('видео найдено')
            ->assertSee('/playback/'.$media->id.'?', false)
            ->assertDontSee('https://media.example.com/files/6_kadrov_s01e02.mp4', false);
    }

    public function test_it_can_force_all_playlist_files_to_one_catalog_title(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'playlist.example.com/*' => Http::response(<<<'M3U'
                #EXTM3U
                #EXTINF:-1,2 серия
                https://media.example.com/files/episode-2.mp4
                M3U),
        ]);

        $source = Source::factory()->create(['code' => 'seasonvar']);
        $page = SourcePage::factory()->create(['source_id' => $source->id]);
        $catalogTitle = CatalogTitle::factory()->create([
            'source_id' => $source->id,
            'source_page_id' => $page->id,
            'slug' => '6-kadrov',
            'title' => '6 кадров',
        ]);
        $season = Season::factory()->create([
            'catalog_title_id' => $catalogTitle->id,
            'number' => 1,
        ]);
        $episode = Episode::factory()->create([
            'season_id' => $season->id,
            'number' => 2,
        ]);

        app(ExternalPlaylistImporter::class)->importFromUrl(
            'https://playlist.example.com/list.m3u',
            $catalogTitle,
        );

        $this->assertDatabaseHas('licensed_media', [
            'catalog_title_id' => $catalogTitle->id,
            'season_id' => $season->id,
            'episode_id' => $episode->id,
            'title' => '2 серия',
            'playback_url' => 'https://media.example.com/files/episode-2.mp4',
            'status' => 'published',
        ]);
    }

    public function test_it_does_not_follow_playlist_redirects_to_an_unverified_target(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'playlist.example.com/*' => Http::response('', 302, [
                'Location' => 'http://127.0.0.1/private.m3u',
            ]),
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('HTTP 302');

        app(ExternalPlaylistImporter::class)->importFromUrl('https://playlist.example.com/list.m3u');
    }

    public function test_it_imports_all_playlist_entries_by_default(): void
    {
        $catalogTitle = CatalogTitle::factory()->create([
            'slug' => 'bolshoi-pleilist',
            'title' => 'Большой плейлист',
        ]);
        $playlistLines = collect(['#EXTM3U']);

        foreach (range(1, 501) as $index) {
            $playlistLines->push("#EXTINF:-1,Большой плейлист {$index}");
            $playlistLines->push("https://media.example.com/files/big-playlist-{$index}.mp4");
        }

        $result = app(ExternalPlaylistImporter::class)->importFromContent(
            $playlistLines->implode("\n"),
            'https://playlist.example.com/list.m3u',
            $catalogTitle,
        );

        $this->assertSame(501, $result['total']);
        $this->assertSame(501, $result['imported']);
        $this->assertSame(501, LicensedMedia::query()->where('catalog_title_id', $catalogTitle->id)->count());
    }

    public function test_it_updates_existing_playlist_media_without_creating_duplicates(): void
    {
        $catalogTitle = CatalogTitle::factory()->create([
            'slug' => 'idempotentnyi-pleilist',
            'title' => 'Идемпотентный плейлист',
        ]);
        $season = Season::factory()->create([
            'catalog_title_id' => $catalogTitle->id,
            'number' => 1,
        ]);
        $episode = Episode::factory()->create([
            'season_id' => $season->id,
            'number' => 2,
        ]);
        $firstPlaylist = <<<'M3U'
            #EXTM3U
            #EXTINF:-1,2 серия
            https://media.example.com/files/episode-2.mp4
            M3U;
        $updatedPlaylist = <<<'M3U'
            #EXTM3U
            #EXTINF:-1,2 серия HD
            https://media.example.com/files/episode-2.mp4
            M3U;

        $importer = app(ExternalPlaylistImporter::class);
        $firstResult = $importer->importFromContent($firstPlaylist, 'https://playlist.example.com/list.m3u', $catalogTitle);
        $secondResult = $importer->importFromContent($updatedPlaylist, 'https://playlist.example.com/list.m3u', $catalogTitle);

        $this->assertSame(1, $firstResult['imported']);
        $this->assertSame(0, $secondResult['imported']);
        $this->assertSame(1, $secondResult['updated']);
        $this->assertSame(1, LicensedMedia::query()->where('catalog_title_id', $catalogTitle->id)->count());

        $media = LicensedMedia::query()->where('catalog_title_id', $catalogTitle->id)->sole();

        $this->assertSame($season->id, $media->season_id);
        $this->assertSame($episode->id, $media->episode_id);
        $this->assertSame('2 серия HD', $media->title);
        $this->assertSame('https://media.example.com/files/episode-2.mp4', $media->playback_url);
        $this->assertSame('published', $media->status);
    }

    public function test_it_excludes_selected_episode_without_media_from_public_playback(): void
    {
        $source = Source::factory()->create(['code' => 'seasonvar']);
        $page = SourcePage::factory()->create(['source_id' => $source->id]);
        $catalogTitle = CatalogTitle::factory()->create([
            'source_id' => $source->id,
            'source_page_id' => $page->id,
            'slug' => '6-kadrov',
            'title' => '6 кадров',
        ]);
        $season = Season::factory()->create([
            'catalog_title_id' => $catalogTitle->id,
            'number' => 1,
        ]);
        $episode = Episode::factory()->create([
            'season_id' => $season->id,
            'number' => 3,
            'title' => 'Третья серия',
        ]);

        $this->get(route('titles.show', ['catalogTitle' => $catalogTitle, 'episode' => $episode->id]))
            ->assertOk()
            ->assertDontSeeText('Выбрана 3 серия')
            ->assertSeeText('Видео пока недоступно')
            ->assertDontSeeText('готовится');
    }
}
