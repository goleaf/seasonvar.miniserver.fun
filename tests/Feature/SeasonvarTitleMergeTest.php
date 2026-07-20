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
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Throwable;

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

    public function test_requested_family_merge_keeps_a_completed_season_when_the_next_season_fails(): void
    {
        $source = Source::factory()->create(['code' => 'seasonvar']);
        $seasonUrls = [
            1 => 'https://seasonvar.ru/serial-90001-Proverka_checkpointa-1-season.html',
            2 => 'https://seasonvar.ru/serial-90002-Proverka_checkpointa-2-season.html',
        ];
        $pages = collect($seasonUrls)->mapWithKeys(fn (string $url, int $number): array => [
            $number => SourcePage::factory()->create([
                'source_id' => $source->id,
                'url' => $url,
                'url_hash' => hash('sha256', $url),
            ]),
        ]);
        $canonical = CatalogTitle::factory()->create([
            'source_id' => $source->id,
            'source_page_id' => $pages[1]->id,
            'external_id' => '90001',
            'slug' => 'proverka-checkpointa',
            'title' => 'Проверка checkpoint',
            'source_url' => $seasonUrls[1],
            'source_url_hash' => hash('sha256', $seasonUrls[1]),
        ]);
        $duplicate = CatalogTitle::factory()->create([
            'source_id' => $source->id,
            'source_page_id' => $pages[2]->id,
            'external_id' => '90002',
            'slug' => 'proverka-checkpointa-2',
            'title' => 'Проверка checkpoint',
            'source_url' => $seasonUrls[2],
            'source_url_hash' => hash('sha256', $seasonUrls[2]),
        ]);
        $duplicateSeasons = collect();

        foreach ($seasonUrls as $number => $url) {
            $canonicalSeason = Season::factory()->create([
                'catalog_title_id' => $canonical->id,
                'source_page_id' => $pages[$number]->id,
                'number' => $number,
                'source_url' => $url,
                'source_url_hash' => hash('sha256', $url),
            ]);
            Episode::factory()->create([
                'season_id' => $canonicalSeason->id,
                'source_page_id' => $pages[$number]->id,
                'number' => 1,
            ]);
            $duplicateSeason = Season::factory()->create([
                'catalog_title_id' => $duplicate->id,
                'source_page_id' => $pages[$number]->id,
                'number' => $number,
                'source_url' => $url,
                'source_url_hash' => hash('sha256', $url),
            ]);
            Episode::factory()->create([
                'season_id' => $duplicateSeason->id,
                'source_page_id' => $pages[$number]->id,
                'number' => 1,
            ]);
            $duplicateSeasons->put($number, $duplicateSeason);
        }

        $failingSeasonId = (int) $duplicateSeasons[2]->id;
        DB::unprepared(<<<SQL
            CREATE TRIGGER fail_second_season_merge
            BEFORE DELETE ON seasons
            WHEN OLD.id = {$failingSeasonId}
            BEGIN
                SELECT RAISE(FAIL, 'forced second season failure');
            END
            SQL);

        try {
            app(SeasonvarTitleMerger::class)->mergeForCanonicalSlug($canonical->slug);
            $this->fail('Season family merge accepted a failed second season.');
        } catch (Throwable $exception) {
            $this->assertStringContainsString('forced second season failure', $exception->getMessage());
        } finally {
            DB::unprepared('DROP TRIGGER IF EXISTS fail_second_season_merge');
        }

        $this->assertDatabaseMissing('seasons', ['id' => $duplicateSeasons[1]->id]);
        $this->assertDatabaseHas('seasons', [
            'id' => $duplicateSeasons[2]->id,
            'catalog_title_id' => $duplicate->id,
        ]);
        $this->assertDatabaseHas('catalog_titles', ['id' => $duplicate->id]);

        $result = app(SeasonvarTitleMerger::class)->mergeForCanonicalSlug($canonical->slug);

        $this->assertSame(1, $result['titles']);
        $this->assertDatabaseMissing('catalog_titles', ['id' => $duplicate->id]);
        $this->assertSame(2, $canonical->fresh()->seasons()->count());
        $this->assertSame(2, $canonical->fresh()->episodes()->count());
    }

    public function test_requested_family_merge_keeps_the_last_season_discoverable_until_duplicate_title_deletion_commits(): void
    {
        $source = Source::factory()->create(['code' => 'seasonvar']);
        $seasonUrl = 'https://seasonvar.ru/serial-90003-Proverka_finalnogo_checkpointa-1-season.html';
        $duplicateTitleUrl = 'https://seasonvar.ru/serial-90004-Proverka_finalnogo_checkpointa-2-season.html';
        $page = SourcePage::factory()->create([
            'source_id' => $source->id,
            'url' => $seasonUrl,
            'url_hash' => hash('sha256', $seasonUrl),
        ]);
        $duplicatePage = SourcePage::factory()->create([
            'source_id' => $source->id,
            'url' => $duplicateTitleUrl,
            'url_hash' => hash('sha256', $duplicateTitleUrl),
        ]);
        $canonical = CatalogTitle::factory()->create([
            'source_id' => $source->id,
            'source_page_id' => $page->id,
            'external_id' => '90003',
            'slug' => 'proverka-finalnogo-checkpointa',
            'title' => 'Проверка финального checkpoint',
            'source_url' => $seasonUrl,
            'source_url_hash' => hash('sha256', $seasonUrl),
        ]);
        $duplicate = CatalogTitle::factory()->create([
            'source_id' => $source->id,
            'source_page_id' => $duplicatePage->id,
            'external_id' => '90004',
            'slug' => 'proverka-finalnogo-checkpointa-2',
            'title' => 'Проверка финального checkpoint',
            'source_url' => $duplicateTitleUrl,
            'source_url_hash' => hash('sha256', $duplicateTitleUrl),
        ]);
        $canonicalSeason = Season::factory()->create([
            'catalog_title_id' => $canonical->id,
            'source_page_id' => $page->id,
            'number' => 1,
            'source_url' => $seasonUrl,
            'source_url_hash' => hash('sha256', $seasonUrl),
        ]);
        Episode::factory()->create([
            'season_id' => $canonicalSeason->id,
            'source_page_id' => $page->id,
            'number' => 1,
        ]);
        $duplicateSeason = Season::factory()->create([
            'catalog_title_id' => $duplicate->id,
            'source_page_id' => $page->id,
            'number' => 1,
            'source_url' => $seasonUrl,
            'source_url_hash' => hash('sha256', $seasonUrl),
        ]);
        Episode::factory()->create([
            'season_id' => $duplicateSeason->id,
            'source_page_id' => $page->id,
            'number' => 1,
        ]);
        $duplicateId = (int) $duplicate->id;
        DB::unprepared(<<<SQL
            CREATE TRIGGER fail_duplicate_title_merge
            BEFORE DELETE ON catalog_titles
            WHEN OLD.id = {$duplicateId}
            BEGIN
                SELECT RAISE(FAIL, 'forced duplicate title failure');
            END
            SQL);

        try {
            app(SeasonvarTitleMerger::class)->mergeForCanonicalSlug($canonical->slug);
            $this->fail('Season family merge accepted a failed duplicate title deletion.');
        } catch (Throwable $exception) {
            $this->assertStringContainsString('forced duplicate title failure', $exception->getMessage());
        } finally {
            DB::unprepared('DROP TRIGGER IF EXISTS fail_duplicate_title_merge');
        }

        $this->assertDatabaseHas('catalog_titles', ['id' => $duplicate->id]);
        $this->assertDatabaseHas('seasons', [
            'id' => $duplicateSeason->id,
            'catalog_title_id' => $duplicate->id,
        ]);

        $result = app(SeasonvarTitleMerger::class)->mergeForCanonicalSlug($canonical->slug);

        $this->assertSame(1, $result['titles']);
        $this->assertDatabaseMissing('catalog_titles', ['id' => $duplicate->id]);
        $this->assertSame(1, $canonical->fresh()->seasons()->count());
        $this->assertSame(1, $canonical->fresh()->episodes()->count());
    }
}
