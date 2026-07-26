<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\MediaHealthStatus;
use App\Enums\ReleaseCalendarSort;
use App\Enums\ReleaseCalendarView;
use App\Enums\ReleaseDatePrecision;
use App\Enums\ReleaseScheduleEntryType;
use App\Enums\ReleaseScheduleSource;
use App\Enums\ReleaseScheduleStatus;
use App\Models\CatalogTitle;
use App\Models\Episode;
use App\Models\LicensedMedia;
use App\Models\ReleaseScheduleEntry;
use App\Models\Season;
use App\Services\ReleaseCalendar\ReleaseCalendarPeriod;
use App\Services\ReleaseCalendar\ReleaseCalendarQuery;
use Carbon\CarbonImmutable;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

final class ReleaseCalendarGroupingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow('2026-07-19 12:00:00 UTC');
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_calendar_groups_a_simultaneous_episode_batch_into_one_expandable_card(): void
    {
        $title = $this->createPortalPublicationBatch(
            range(185, 194),
            CarbonImmutable::parse('2026-07-18 10:00:00 UTC'),
        );

        $response = $this->get('/calendar')->assertOk();
        $xpath = $this->xpath($response);

        $this->assertSame(1, $xpath->query('//*[@data-release-batch-card]')->length);
        $this->assertSame(10, $xpath->query('//*[@data-release-batch-item]')->length);
        $response
            ->assertSeeText($title->title)
            ->assertSeeText('Добавлены серии 185–194')
            ->assertSeeText('Список серий (10)')
            ->assertSeeText('Серия 185')
            ->assertSeeText('Серия 194');
    }

    public function test_calendar_formats_non_consecutive_episode_numbers_without_a_false_range(): void
    {
        $this->createPortalPublicationBatch(
            [185, 186, 188, 190, 191, 192],
            CarbonImmutable::parse('2026-07-18 10:00:00 UTC'),
        );

        $this->get('/calendar')
            ->assertOk()
            ->assertSeeText('Добавлены серии 185–186, 188, 190–192')
            ->assertDontSeeText('Добавлены серии 185–192');
    }

    public function test_a_batch_larger_than_the_page_size_is_not_split_between_pages(): void
    {
        config(['release-calendar.per_page' => 1]);
        $batchTitle = $this->createPortalPublicationBatch(
            range(185, 209),
            CarbonImmutable::parse('2026-07-18 10:00:00 UTC'),
        );
        $otherTitle = $this->createPortalPublicationBatch(
            [1],
            CarbonImmutable::parse('2026-07-17 10:00:00 UTC'),
            'Другой календарный сериал',
        );

        $firstPage = $this->get('/calendar')->assertOk();
        $firstXpath = $this->xpath($firstPage);

        $this->assertSame(1, $firstXpath->query('//*[@data-release-batch-card]')->length);
        $this->assertSame(25, $firstXpath->query('//*[@data-release-batch-item]')->length);
        $firstPage
            ->assertSeeText($batchTitle->title)
            ->assertSeeText('Добавлены серии 185–209')
            ->assertDontSeeText($otherTitle->title);

        $this->get('/calendar?calendarPage=2')
            ->assertOk()
            ->assertSeeText($otherTitle->title)
            ->assertDontSeeText($batchTitle->title);
    }

    public function test_grouping_keeps_different_seasons_times_and_translations_separate(): void
    {
        $publishedAt = CarbonImmutable::parse('2026-07-18 10:00:00 UTC');
        $title = CatalogTitle::factory()->create([
            'title' => 'Сериал со смешанным пакетом',
            'slug' => 'calendar-mixed-'.str()->uuid(),
        ]);
        $firstSeason = Season::factory()->for($title)->create(['number' => 1]);
        $secondSeason = Season::factory()->for($title)->create(['number' => 2]);

        $this->createPortalPublication($title, $firstSeason, 1, $publishedAt, 'Дубляж');
        $this->createPortalPublication($title, $firstSeason, 2, $publishedAt, 'Дубляж');
        $this->createPortalPublication($title, $firstSeason, 3, $publishedAt, 'Авторский');
        $this->createPortalPublication($title, $firstSeason, 4, $publishedAt->addMinute(), 'Дубляж');
        $this->createPortalPublication($title, $secondSeason, 1, $publishedAt, 'Дубляж');

        $response = $this->get('/calendar')->assertOk();
        $xpath = $this->xpath($response);

        $this->assertSame(4, $xpath->query('//*[@data-release-card]')->length);
        $this->assertSame(1, $xpath->query('//*[@data-release-batch-card]')->length);
        $response
            ->assertSeeText('Добавлены серии 1–2')
            ->assertSeeText('Перевод: Авторский')
            ->assertSeeText('Сезон 2');
    }

    public function test_a_single_episode_keeps_the_existing_non_disclosure_card(): void
    {
        $this->createPortalPublicationBatch(
            [7],
            CarbonImmutable::parse('2026-07-18 10:00:00 UTC'),
        );

        $response = $this->get('/calendar')->assertOk();
        $xpath = $this->xpath($response);

        $this->assertSame(1, $xpath->query('//*[@data-release-card]')->length);
        $this->assertSame(0, $xpath->query('//*[@data-release-batch-card]')->length);
        $response
            ->assertSeeText('Сезон 1 · Серия 7')
            ->assertDontSeeText('Список серий');
    }

    public function test_events_without_an_episode_remain_individual_cards(): void
    {
        $title = CatalogTitle::factory()->create([
            'title' => 'Сериал с двумя объявлениями',
            'slug' => 'calendar-announcement-'.str()->uuid(),
        ]);
        $startsAt = CarbonImmutable::parse('2026-07-18 10:00:00 UTC');

        foreach (range(1, 2) as $index) {
            ReleaseScheduleEntry::query()->create([
                'logical_key' => 'serial-premiere-announcement-'.$index.'-'.$title->id,
                'entry_type' => ReleaseScheduleEntryType::SerialPremiere,
                'status' => ReleaseScheduleStatus::Released,
                'precision' => ReleaseDatePrecision::ExactDateTime,
                'source' => ReleaseScheduleSource::Official,
                'catalog_title_id' => $title->id,
                'starts_at' => $startsAt,
                'original_timezone' => 'UTC',
                'is_public' => true,
                'notifications_enabled' => false,
                'released_at' => $startsAt,
            ]);
        }

        $response = $this->get('/calendar')->assertOk();
        $xpath = $this->xpath($response);

        $this->assertSame(2, $xpath->query('//*[@data-release-card]')->length);
        $this->assertSame(0, $xpath->query('//*[@data-release-batch-card]')->length);
    }

    public function test_multiple_media_variants_of_one_episode_do_not_create_a_false_batch(): void
    {
        $publishedAt = CarbonImmutable::parse('2026-07-18 10:00:00 UTC');
        $title = $this->createPortalPublicationBatch([7], $publishedAt);
        $firstEntry = ReleaseScheduleEntry::query()
            ->where('catalog_title_id', $title->id)
            ->firstOrFail();
        $secondMedia = LicensedMedia::withoutEvents(fn (): LicensedMedia => LicensedMedia::factory()->create([
            'catalog_title_id' => $title->id,
            'season_id' => $firstEntry->season_id,
            'episode_id' => $firstEntry->episode_id,
            'status' => 'published',
            'published_at' => $publishedAt,
            'health_status' => MediaHealthStatus::Active,
            'path' => 'licensed/calendar-group-variant-'.$firstEntry->episode_id.'.mp4',
        ]));

        ReleaseScheduleEntry::query()->create([
            'logical_key' => 'portal-publication-group-'.$secondMedia->id,
            'entry_type' => ReleaseScheduleEntryType::PortalPublication,
            'status' => ReleaseScheduleStatus::Released,
            'precision' => ReleaseDatePrecision::ExactDateTime,
            'source' => ReleaseScheduleSource::Portal,
            'catalog_title_id' => $title->id,
            'season_id' => $firstEntry->season_id,
            'episode_id' => $firstEntry->episode_id,
            'licensed_media_id' => $secondMedia->id,
            'season_number' => $firstEntry->season_number,
            'episode_number' => $firstEntry->episode_number,
            'translation_name' => $firstEntry->translation_name,
            'starts_at' => $publishedAt,
            'original_timezone' => 'UTC',
            'is_public' => true,
            'notifications_enabled' => true,
            'released_at' => $publishedAt,
        ]);

        $response = $this->get('/calendar')->assertOk();
        $xpath = $this->xpath($response);

        $this->assertSame(1, $xpath->query('//*[@data-release-card]')->length);
        $this->assertSame(0, $xpath->query('//*[@data-release-batch-card]')->length);
        $response
            ->assertSeeText('Сезон 1 · Серия 7')
            ->assertDontSeeText('Список серий');
    }

    public function test_grouping_keeps_different_event_types_and_statuses_separate(): void
    {
        $publishedAt = CarbonImmutable::parse('2026-07-18 10:00:00 UTC');
        $title = $this->createPortalPublicationBatch([1, 2, 3], $publishedAt);
        $entries = ReleaseScheduleEntry::query()
            ->where('catalog_title_id', $title->id)
            ->orderBy('episode_number')
            ->get();

        $entries[1]->update(['entry_type' => ReleaseScheduleEntryType::EpisodeRelease]);
        $entries[2]->update(['status' => ReleaseScheduleStatus::Confirmed]);

        $response = $this->get('/calendar/month/2026-07')->assertOk();
        $xpath = $this->xpath($response);

        $this->assertSame(3, $xpath->query('//*[@data-release-card]')->length);
        $this->assertSame(0, $xpath->query('//*[@data-release-batch-card]')->length);
        $response
            ->assertSeeText('Публикация на портале')
            ->assertSeeText('Выход серии')
            ->assertSeeText('Подтверждено');
    }

    public function test_grouping_works_with_combined_filters_and_sorting(): void
    {
        $publishedAt = CarbonImmutable::parse('2026-07-18 10:00:00 UTC');
        $portalTitle = $this->createPortalPublicationBatch(
            [20, 21],
            $publishedAt,
            'Сериал из отфильтрованного пакета',
        );
        $otherTitle = $this->createPortalPublicationBatch(
            [30, 31],
            $publishedAt->subHour(),
            'Сериал другого типа',
        );
        ReleaseScheduleEntry::query()
            ->where('catalog_title_id', $otherTitle->id)
            ->update(['entry_type' => ReleaseScheduleEntryType::EpisodeRelease]);

        $response = $this->get('/calendar?type=portal_publication&status=released&sort=earliest')
            ->assertOk();
        $xpath = $this->xpath($response);

        $this->assertSame(1, $xpath->query('//*[@data-release-batch-card]')->length);
        $response
            ->assertSeeText($portalTitle->title)
            ->assertSeeText('Добавлены серии 20–21')
            ->assertDontSeeText($otherTitle->title);
    }

    public function test_localized_calendar_uses_the_english_batch_copy(): void
    {
        $this->createPortalPublicationBatch(
            [10, 11, 12],
            CarbonImmutable::parse('2026-07-18 10:00:00 UTC'),
        );

        $this->get('/en/calendar')
            ->assertOk()
            ->assertSeeText('Episodes 10–12 added')
            ->assertSeeText('Episode list (3)');
    }

    public function test_member_query_count_does_not_grow_with_the_batch_size(): void
    {
        $publishedAt = CarbonImmutable::parse('2026-07-18 10:00:00 UTC');
        $title = CatalogTitle::factory()->create([
            'title' => 'Сериал для query budget',
            'slug' => 'calendar-query-budget-'.str()->uuid(),
        ]);
        $season = Season::factory()->for($title)->create(['number' => 1]);
        $this->createPortalPublication($title, $season, 1, $publishedAt, 'Дубляж');
        $this->createPortalPublication($title, $season, 2, $publishedAt, 'Дубляж');
        $queries = [];
        DB::listen(static function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });
        $calendar = app(ReleaseCalendarQuery::class);
        $period = ReleaseCalendarPeriod::resolve(ReleaseCalendarView::Recent, null, 'UTC');

        $firstStart = count($queries);
        $calendar->entries(
            null,
            ReleaseCalendarView::Recent,
            $period,
            null,
            null,
            ReleaseCalendarSort::Latest,
            'ru',
            'UTC',
        );
        $firstCount = count($queries) - $firstStart;

        foreach (range(3, 20) as $episodeNumber) {
            $this->createPortalPublication($title, $season, $episodeNumber, $publishedAt, 'Дубляж');
        }

        $secondStart = count($queries);
        $calendar->entries(
            null,
            ReleaseCalendarView::Recent,
            $period,
            null,
            null,
            ReleaseCalendarSort::Latest,
            'ru',
            'UTC',
        );
        $secondCount = count($queries) - $secondStart;

        $this->assertSame($firstCount, $secondCount);
        $this->assertLessThanOrEqual(6, $secondCount);
    }

    /**
     * @param  list<int>  $episodeNumbers
     */
    private function createPortalPublicationBatch(
        array $episodeNumbers,
        CarbonImmutable $publishedAt,
        string $titleText = 'Осторожно с ангелом',
        int $seasonNumber = 1,
        ?string $translationName = 'Дубляж',
    ): CatalogTitle {
        $title = CatalogTitle::factory()->create([
            'title' => $titleText,
            'slug' => 'calendar-group-'.str()->uuid(),
        ]);
        $season = Season::factory()->for($title)->create(['number' => $seasonNumber]);

        foreach ($episodeNumbers as $episodeNumber) {
            $this->createPortalPublication(
                $title,
                $season,
                $episodeNumber,
                $publishedAt,
                $translationName,
            );
        }

        return $title;
    }

    private function createPortalPublication(
        CatalogTitle $title,
        Season $season,
        int $episodeNumber,
        CarbonImmutable $publishedAt,
        ?string $translationName,
    ): ReleaseScheduleEntry {
        $episode = Episode::factory()->for($season)->create([
            'number' => $episodeNumber,
            'title' => 'Название серии '.$episodeNumber,
        ]);
        $media = LicensedMedia::withoutEvents(fn (): LicensedMedia => LicensedMedia::factory()->create([
            'catalog_title_id' => $title->id,
            'season_id' => $season->id,
            'episode_id' => $episode->id,
            'status' => 'published',
            'published_at' => $publishedAt,
            'translation_name' => $translationName,
            'health_status' => MediaHealthStatus::Active,
            'path' => 'licensed/calendar-group-'.$episode->id.'.mp4',
        ]));

        return ReleaseScheduleEntry::query()->create([
            'logical_key' => 'portal-publication-group-'.$media->id,
            'entry_type' => ReleaseScheduleEntryType::PortalPublication,
            'status' => ReleaseScheduleStatus::Released,
            'precision' => ReleaseDatePrecision::ExactDateTime,
            'source' => ReleaseScheduleSource::Portal,
            'catalog_title_id' => $title->id,
            'season_id' => $season->id,
            'episode_id' => $episode->id,
            'licensed_media_id' => $media->id,
            'season_number' => $season->number,
            'episode_number' => $episodeNumber,
            'translation_name' => $translationName,
            'starts_at' => $publishedAt,
            'original_timezone' => 'UTC',
            'is_public' => true,
            'notifications_enabled' => true,
            'released_at' => $publishedAt,
        ]);
    }

    /** @param TestResponse<Response> $response */
    private function xpath(TestResponse $response): DOMXPath
    {
        $document = new DOMDocument;
        @$document->loadHTML((string) $response->getContent());

        return new DOMXPath($document);
    }
}
