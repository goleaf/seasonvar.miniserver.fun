<?php

namespace Tests\Feature;

use App\Models\CatalogTitle;
use App\Models\Country;
use App\Models\Episode;
use App\Models\Genre;
use App\Models\LicensedMedia;
use App\Models\Season;
use App\Models\Source;
use App\Models\SourcePage;
use App\Services\Seasonvar\SeasonvarTitleMerger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SeasonvarTitleMergeTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_does_not_merge_distinct_provider_ids_only_because_titles_match(): void
    {
        $source = Source::factory()->create(['code' => 'seasonvar']);
        $firstUrl = 'https://seasonvar.ru/serial/odin-serial-1-season';
        $secondUrl = 'https://seasonvar.ru/serial/odin-serial-2-season';
        $firstPage = SourcePage::factory()->create([
            'source_id' => $source->id,
            'url' => $firstUrl,
            'url_hash' => hash('sha256', $firstUrl),
        ]);
        $secondPage = SourcePage::factory()->create([
            'source_id' => $source->id,
            'url' => $secondUrl,
            'url_hash' => hash('sha256', $secondUrl),
        ]);
        $country = Country::query()->create([
            'name' => 'Россия',
            'slug' => 'rossiia',
        ]);
        $genre = Genre::query()->create([
            'name' => 'Комедия',
            'slug' => 'komediia',
        ]);

        $canonical = CatalogTitle::factory()->create([
            'source_id' => $source->id,
            'source_page_id' => $firstPage->id,
            'external_id' => 'serial-1',
            'slug' => 'odin-serial',
            'title' => 'Один сериал',
            'year' => 2010,
            'source_url' => $firstUrl,
            'source_url_hash' => hash('sha256', $firstUrl),
        ]);
        $duplicate = CatalogTitle::factory()->create([
            'source_id' => $source->id,
            'source_page_id' => $secondPage->id,
            'external_id' => 'serial-2',
            'slug' => 'odin-serial-2',
            'title' => 'Один сериал',
            'year' => 2011,
            'source_url' => $secondUrl,
            'source_url_hash' => hash('sha256', $secondUrl),
        ]);
        $canonical->countries()->attach($country->id);
        $duplicate->countries()->attach($country->id);
        $duplicate->genres()->attach($genre->id);

        $firstSeason = Season::factory()->create([
            'catalog_title_id' => $canonical->id,
            'source_page_id' => $firstPage->id,
            'number' => 1,
            'title' => '1 сезон',
            'source_url' => $firstUrl,
            'source_url_hash' => hash('sha256', $firstUrl),
            'relation_metadata_version' => 1,
        ]);
        $secondSeason = Season::factory()->create([
            'catalog_title_id' => $duplicate->id,
            'source_page_id' => $secondPage->id,
            'number' => 2,
            'title' => '2 сезон',
            'source_url' => $secondUrl,
            'source_url_hash' => hash('sha256', $secondUrl),
            'relation_metadata_version' => 0,
        ]);
        Episode::factory()->create([
            'season_id' => $firstSeason->id,
            'source_page_id' => $firstPage->id,
            'number' => 1,
            'title' => 'Первая серия',
        ]);
        $secondEpisode = Episode::factory()->create([
            'season_id' => $secondSeason->id,
            'source_page_id' => $secondPage->id,
            'number' => 1,
            'title' => 'Вторая серия',
        ]);
        $media = LicensedMedia::factory()->create([
            'catalog_title_id' => $duplicate->id,
            'season_id' => $secondSeason->id,
            'episode_id' => $secondEpisode->id,
        ]);

        app(SeasonvarTitleMerger::class)->merge();

        $this->assertDatabaseHas('catalog_titles', ['id' => $duplicate->id]);
        $this->assertDatabaseHas('catalog_title_country', [
            'catalog_title_id' => $canonical->id,
            'country_id' => $country->id,
        ]);
        $this->assertDatabaseHas('catalog_title_genre', [
            'catalog_title_id' => $duplicate->id,
            'genre_id' => $genre->id,
        ]);

        $canonical->refresh()->load(['seasons.episodes', 'countries', 'genres']);
        $this->assertSame([1], $canonical->seasons->pluck('number')->all());
        $this->assertSame(1, $canonical->episodes()->count());

        $this->assertDatabaseHas('episodes', [
            'id' => $secondEpisode->id,
            'season_id' => $secondSeason->id,
        ]);
        $this->assertDatabaseHas('licensed_media', [
            'id' => $media->id,
            'catalog_title_id' => $duplicate->id,
            'season_id' => $secondSeason->id,
            'episode_id' => $secondEpisode->id,
        ]);

        $this->get(route('titles.taxonomy', ['type' => 'country', 'taxonomy' => 'rossiia']))
            ->assertOk()
            ->assertSeeText('Найдено сейчас: 2');
    }

    public function test_it_uses_canonical_url_family_when_stable_provider_ids_are_missing(): void
    {
        $source = Source::factory()->create(['code' => 'seasonvar']);
        $firstUrl = 'https://seasonvar.ru/serial-1179--Bitva_ekstrasensov_psyegsq-1-season.html';
        $secondUrl = 'https://seasonvar.ru/serial-48017-Bitva_ekstrasensov-23-season.html';
        $firstPage = SourcePage::factory()->create([
            'source_id' => $source->id,
            'url' => $firstUrl,
            'url_hash' => hash('sha256', $firstUrl),
        ]);
        $secondPage = SourcePage::factory()->create([
            'source_id' => $source->id,
            'url' => $secondUrl,
            'url_hash' => hash('sha256', $secondUrl),
        ]);

        $canonical = CatalogTitle::factory()->create([
            'source_id' => $source->id,
            'source_page_id' => $firstPage->id,
            'external_id' => null,
            'slug' => 'bitva-ekstrasensov',
            'title' => 'Битва экстрасенсов',
            'year' => 2007,
            'source_url' => $firstUrl,
            'source_url_hash' => hash('sha256', $firstUrl),
        ]);
        $variant = CatalogTitle::factory()->create([
            'source_id' => $source->id,
            'source_page_id' => $secondPage->id,
            'external_id' => null,
            'slug' => 'bitva-ekstrasensovnovaia-bitva-ekstrasensov',
            'title' => 'Битва экстрасенсов/Новая битва экстрасенсов',
            'year' => 2022,
            'source_url' => $secondUrl,
            'source_url_hash' => hash('sha256', $secondUrl),
        ]);

        Season::factory()->create([
            'catalog_title_id' => $canonical->id,
            'source_page_id' => $firstPage->id,
            'number' => 1,
            'title' => '1 сезон',
            'source_url' => $firstUrl,
            'source_url_hash' => hash('sha256', $firstUrl),
        ]);
        Season::factory()->create([
            'catalog_title_id' => $variant->id,
            'source_page_id' => $secondPage->id,
            'number' => 23,
            'title' => '23 сезон',
            'source_url' => $secondUrl,
            'source_url_hash' => hash('sha256', $secondUrl),
        ]);

        app(SeasonvarTitleMerger::class)->merge();

        $this->assertDatabaseMissing('catalog_titles', ['id' => $variant->id]);

        $canonical->refresh()->load('seasons');
        $this->assertSame('Битва экстрасенсов', $canonical->title);
        $this->assertSame([1, 23], $canonical->seasons->sortBy('number')->pluck('number')->values()->all());
        $this->assertSame(0, $canonical->relation_metadata_version);
    }
}
