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
        ]);
        $secondSeason = Season::factory()->create([
            'catalog_title_id' => $duplicate->id,
            'source_page_id' => $secondPage->id,
            'number' => 2,
            'title' => '2 сезон',
            'source_url' => $secondUrl,
            'source_url_hash' => hash('sha256', $secondUrl),
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
            'relation_metadata_version' => 1,
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
            'relation_metadata_version' => 0,
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
        $this->assertDatabaseHas('catalog_title_slugs', [
            'catalog_title_id' => $canonical->id,
            'slug' => 'bitva-ekstrasensovnovaia-bitva-ekstrasensov',
        ]);
        $this->get('/titles/bitva-ekstrasensovnovaia-bitva-ekstrasensov')
            ->assertMovedPermanently()
            ->assertRedirect(route('titles.show', $canonical));
    }

    public function test_global_merge_consolidates_a_verified_season_family_with_different_provider_ids(): void
    {
        $source = Source::factory()->create(['code' => 'seasonvar']);
        $seasonOneUrl = 'https://seasonvar.ru/serial-7780-Mamochka_psxtsdh.html';
        $seasonTwoUrl = 'https://seasonvar.ru/serial-10781-Mamochka_pszlnxu-2-season.html';
        $seasonThreeUrl = 'https://seasonvar.ru/serial-12712-Mamochka_psphzei-3-sezon.html';

        $pages = collect([$seasonOneUrl, $seasonTwoUrl, $seasonThreeUrl])
            ->mapWithKeys(fn (string $url): array => [
                $url => SourcePage::factory()->create([
                    'source_id' => $source->id,
                    'url' => $url,
                    'url_hash' => hash('sha256', $url),
                ]),
            ]);

        $canonical = CatalogTitle::factory()->create([
            'source_id' => $source->id,
            'source_page_id' => $pages[$seasonOneUrl]->id,
            'external_id' => '7780',
            'slug' => 'mamockamom',
            'title' => 'Мамочка/Mom',
            'source_url' => $seasonOneUrl,
            'source_url_hash' => hash('sha256', $seasonOneUrl),
        ]);
        $duplicate = CatalogTitle::factory()->create([
            'source_id' => $source->id,
            'source_page_id' => $pages[$seasonThreeUrl]->id,
            'external_id' => '12712',
            'slug' => 'mamockamom-2',
            'title' => 'Мамочка/Mom',
            'source_url' => $seasonThreeUrl,
            'source_url_hash' => hash('sha256', $seasonThreeUrl),
        ]);

        foreach ([1 => $seasonOneUrl, 2 => $seasonTwoUrl] as $number => $url) {
            Season::factory()->create([
                'catalog_title_id' => $canonical->id,
                'source_page_id' => $pages[$url]->id,
                'number' => $number,
                'source_url' => $url,
                'source_url_hash' => hash('sha256', $url),
            ]);
        }

        foreach ([2 => $seasonTwoUrl, 3 => $seasonThreeUrl] as $number => $url) {
            $season = Season::factory()->create([
                'catalog_title_id' => $duplicate->id,
                'source_page_id' => $pages[$url]->id,
                'number' => $number,
                'source_url' => $url,
                'source_url_hash' => hash('sha256', $url),
            ]);
            Episode::factory()->create([
                'season_id' => $season->id,
                'source_page_id' => $pages[$url]->id,
                'number' => 1,
            ]);
        }

        $result = app(SeasonvarTitleMerger::class)->merge();

        $this->assertSame(1, $result['groups']);
        $this->assertSame(1, $result['titles']);
        $this->assertDatabaseMissing('catalog_titles', ['id' => $duplicate->id]);
        $canonical->refresh()->load('seasons.episodes');
        $this->assertSame([1, 2, 3], $canonical->seasons->sortBy('number')->pluck('number')->values()->all());
        $this->assertSame(2, $canonical->episodes()->count());
    }

    public function test_it_merges_requested_season_family_even_when_provider_ids_differ(): void
    {
        $source = Source::factory()->create(['code' => 'seasonvar']);
        $seasonOneUrl = 'https://seasonvar.ru/serial-7780-Mamochka_psxtsdh.html';
        $seasonTwoUrl = 'https://seasonvar.ru/serial-10781-Mamochka_pszlnxu-2-season.html';
        $seasonThreeUrl = 'https://seasonvar.ru/serial-12712-Mamochka_psphzei-3-sezon.html';

        $seasonOnePage = SourcePage::factory()->create([
            'source_id' => $source->id,
            'url' => $seasonOneUrl,
            'url_hash' => hash('sha256', $seasonOneUrl),
        ]);
        $seasonTwoPage = SourcePage::factory()->create([
            'source_id' => $source->id,
            'url' => $seasonTwoUrl,
            'url_hash' => hash('sha256', $seasonTwoUrl),
        ]);
        $seasonThreePage = SourcePage::factory()->create([
            'source_id' => $source->id,
            'url' => $seasonThreeUrl,
            'url_hash' => hash('sha256', $seasonThreeUrl),
        ]);

        $duplicate = CatalogTitle::factory()->create([
            'source_id' => $source->id,
            'source_page_id' => $seasonThreePage->id,
            'external_id' => '12712',
            'slug' => 'mamockamom',
            'title' => 'Мамочка/Mom',
            'source_url' => $seasonThreeUrl,
            'source_url_hash' => hash('sha256', $seasonThreeUrl),
        ]);
        $canonical = CatalogTitle::factory()->create([
            'source_id' => $source->id,
            'source_page_id' => $seasonOnePage->id,
            'external_id' => '20002',
            'slug' => 'mamockamom-5',
            'title' => 'Мамочка/Mom',
            'source_url' => $seasonOneUrl,
            'source_url_hash' => hash('sha256', $seasonOneUrl),
        ]);

        $duplicateSeason = Season::factory()->create([
            'catalog_title_id' => $duplicate->id,
            'source_page_id' => $seasonTwoPage->id,
            'number' => 2,
            'source_url' => $seasonTwoUrl,
            'source_url_hash' => hash('sha256', $seasonTwoUrl),
        ]);
        $duplicateEpisode = Episode::factory()->create([
            'season_id' => $duplicateSeason->id,
            'source_page_id' => $seasonTwoPage->id,
            'number' => 1,
            'title' => 'Дубль второй серии',
        ]);
        $duplicateMedia = LicensedMedia::factory()->create([
            'catalog_title_id' => $duplicate->id,
            'season_id' => $duplicateSeason->id,
            'episode_id' => $duplicateEpisode->id,
            'source_media_key' => 'mamochka-s02e01-duplicate',
        ]);

        foreach ([
            1 => [$seasonOnePage, $seasonOneUrl],
            2 => [$seasonTwoPage, $seasonTwoUrl],
            3 => [$seasonThreePage, $seasonThreeUrl],
        ] as $number => [$page, $url]) {
            $season = Season::factory()->create([
                'catalog_title_id' => $canonical->id,
                'source_page_id' => $page->id,
                'number' => $number,
                'source_url' => $url,
                'source_url_hash' => hash('sha256', $url),
            ]);
            Episode::factory()->create([
                'season_id' => $season->id,
                'source_page_id' => $page->id,
                'number' => 1,
                'title' => $number.' серия',
            ]);
        }

        $result = app(SeasonvarTitleMerger::class)->mergeForCanonicalSlug('mamockamom-5');

        $this->assertSame([
            'groups' => 1,
            'titles' => 1,
            'seasons' => 1,
            'episodes' => 1,
        ], $result);
        $this->assertDatabaseMissing('catalog_titles', ['id' => $duplicate->id]);
        $this->assertDatabaseHas('catalog_titles', ['id' => $canonical->id, 'slug' => 'mamockamom-5']);
        $this->assertDatabaseHas('catalog_title_slugs', [
            'catalog_title_id' => $canonical->id,
            'slug' => 'mamockamom',
        ]);

        $canonical->refresh()->load('seasons.episodes');

        $this->assertSame([1, 2, 3], $canonical->seasons->sortBy('number')->pluck('number')->values()->all());
        $this->assertSame(3, CatalogTitle::query()->where('title', 'Мамочка/Mom')->sole()->episodes()->count());

        $canonicalSeasonTwo = $canonical->seasons->firstWhere('number', 2);
        $canonicalEpisodeTwo = $canonicalSeasonTwo?->episodes->firstWhere('number', 1);
        $duplicateMedia->refresh();

        $this->assertSame($canonical->id, $duplicateMedia->catalog_title_id);
        $this->assertSame($canonicalSeasonTwo?->id, $duplicateMedia->season_id);
        $this->assertSame($canonicalEpisodeTwo?->id, $duplicateMedia->episode_id);

        $this->get('/titles/mamockamom')
            ->assertMovedPermanently()
            ->assertRedirect(route('titles.show', $canonical));
    }
}
