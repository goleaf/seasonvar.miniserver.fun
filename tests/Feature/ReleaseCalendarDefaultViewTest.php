<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\CatalogRecommendationFeedback;
use App\Enums\ReleaseCalendarNotificationType;
use App\Enums\ReleaseDatePrecision;
use App\Enums\ReleaseScheduleEntryType;
use App\Enums\ReleaseScheduleSource;
use App\Enums\ReleaseScheduleStatus;
use App\Models\CatalogTitle;
use App\Models\CatalogTitleUserState;
use App\Models\Episode;
use App\Models\LicensedMedia;
use App\Models\ReleaseScheduleEntry;
use App\Models\Season;
use App\Models\User;
use App\Notifications\ReleaseCalendarActivityNotification;
use App\Services\ReleaseCalendar\ReleaseCalendarNotificationQuery;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ReleaseCalendarDefaultViewTest extends TestCase
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

    public function test_calendar_index_shows_recent_releases_by_default(): void
    {
        $this->createReleasedEntry('Недавний календарный сериал');

        $this->get('/calendar')
            ->assertOk()
            ->assertSeeText('Недавний календарный сериал')
            ->assertSee('<meta name="robots" content="index, follow">', false)
            ->assertSee('<link rel="canonical" href="'.route('calendar.index').'">', false)
            ->assertSee('<link rel="alternate" hreflang="ru" href="'.route('localized.calendar.index', ['locale' => 'ru']).'">', false)
            ->assertSee('"@type":"ItemList"', false);
    }

    public function test_calendar_index_shows_the_newest_recent_date_first_but_honors_an_explicit_earliest_sort(): void
    {
        $this->createReleasedEntry(
            'Сериал за 28 мая',
            CarbonImmutable::parse('2026-05-28 12:00:00 UTC'),
        );
        $this->createReleasedEntry(
            'Сериал за 29 мая',
            CarbonImmutable::parse('2026-05-29 12:00:00 UTC'),
        );

        $this->get('/calendar')
            ->assertOk()
            ->assertSeeInOrder(['29 мая 2026', '28 мая 2026']);

        $this->get('/calendar?sort=earliest')
            ->assertOk()
            ->assertSeeInOrder(['28 мая 2026', '29 мая 2026']);
    }

    public function test_invalid_recent_sort_falls_back_to_latest(): void
    {
        $this->createReleasedEntry(
            'Старый релиз при неверной сортировке',
            CarbonImmutable::parse('2026-05-28 12:00:00 UTC'),
        );
        $this->createReleasedEntry(
            'Новый релиз при неверной сортировке',
            CarbonImmutable::parse('2026-05-29 12:00:00 UTC'),
        );

        $this->get('/calendar?sort=invalid')
            ->assertOk()
            ->assertSeeInOrder([
                'Новый релиз при неверной сортировке',
                'Старый релиз при неверной сортировке',
            ]);
    }

    public function test_upcoming_calendar_keeps_the_earliest_default(): void
    {
        $this->createScheduledEntry(
            'Ближайший подтверждённый релиз',
            CarbonImmutable::parse('2026-07-20 12:00:00 UTC'),
        );
        $this->createScheduledEntry(
            'Поздний подтверждённый релиз',
            CarbonImmutable::parse('2026-07-21 12:00:00 UTC'),
        );

        $this->get('/calendar/upcoming')
            ->assertOk()
            ->assertSeeInOrder([
                'Ближайший подтверждённый релиз',
                'Поздний подтверждённый релиз',
            ]);
    }

    public function test_upcoming_calendar_does_not_mix_in_past_publications(): void
    {
        $this->createReleasedEntry('Только прошедший релиз');

        $this->get('/calendar/upcoming')
            ->assertOk()
            ->assertDontSeeText('Только прошедший релиз')
            ->assertSeeText('В этом периоде релизов нет')
            ->assertSee('<meta name="robots" content="noindex, follow">', false)
            ->assertSee('<link rel="canonical" href="'.route('calendar.upcoming').'">', false);
    }

    public function test_old_recent_routes_redirect_permanently_to_the_new_index(): void
    {
        $this->get('/calendar/recent')
            ->assertStatus(301)
            ->assertRedirect(route('calendar.index'));

        $this->get('/en/calendar/recent')
            ->assertStatus(301)
            ->assertRedirect(route('localized.calendar.index', ['locale' => 'en']));
    }

    public function test_global_navigation_uses_the_calendar_index(): void
    {
        $response = $this->get('/calendar/upcoming')->assertOk();
        $document = new \DOMDocument;
        @$document->loadHTML((string) $response->getContent());
        $links = (new \DOMXPath($document))->query(
            '//header[@data-site-header]//a[normalize-space(.//span)="Календарь релизов"]/@href'
            .' | //footer[@data-site-footer]//a[normalize-space(.//span)="Календарь релизов"]/@href',
        );

        $this->assertNotFalse($links);
        $this->assertGreaterThan(0, $links->length);

        foreach ($links as $link) {
            $this->assertSame(route('calendar.index'), $link->nodeValue);
        }
    }

    public function test_release_notifications_open_the_calendar_index(): void
    {
        $user = User::factory()->create();
        $entry = $this->createReleasedEntry('Сериал из уведомления');
        $user->notify(new ReleaseCalendarActivityNotification(
            ReleaseCalendarNotificationType::Released,
            $entry->public_id,
            $entry->entry_type->value,
            $entry->status->value,
            $entry->revision,
        ));

        $notifications = app(ReleaseCalendarNotificationQuery::class)->forUser($user);

        $this->assertCount(1, $notifications);
        $this->assertSame(route('calendar.index'), $notifications->first()?->url);
    }

    public function test_more_like_this_keeps_release_notifications_while_negative_feedback_suppresses_them(): void
    {
        $user = User::factory()->create();
        $entry = $this->createReleasedEntry('Сериал с положительным сигналом');
        $state = CatalogTitleUserState::query()->create([
            'user_id' => $user->id,
            'catalog_title_id' => $entry->catalog_title_id,
            'recommendation_feedback' => CatalogRecommendationFeedback::MoreLikeThis,
            'recommendation_feedback_updated_at' => now(),
        ]);
        $user->notify(new ReleaseCalendarActivityNotification(
            ReleaseCalendarNotificationType::Released,
            $entry->public_id,
            $entry->entry_type->value,
            $entry->status->value,
            $entry->revision,
        ));

        $this->assertCount(1, app(ReleaseCalendarNotificationQuery::class)->forUser($user));

        $state->update([
            'recommendation_feedback' => CatalogRecommendationFeedback::NotInterested,
            'recommendation_feedback_updated_at' => now(),
        ]);

        $this->assertCount(0, app(ReleaseCalendarNotificationQuery::class)->forUser($user));
    }

    private function createReleasedEntry(
        string $titleText,
        ?CarbonImmutable $publishedAt = null,
    ): ReleaseScheduleEntry {
        $title = CatalogTitle::factory()->create([
            'title' => $titleText,
            'slug' => 'calendar-'.str()->uuid(),
        ]);
        $season = Season::factory()->for($title)->create(['number' => 1]);
        $episode = Episode::factory()->for($season)->create(['number' => 1]);
        $publishedAt ??= CarbonImmutable::now()->subDay();
        $media = LicensedMedia::withoutEvents(fn (): LicensedMedia => LicensedMedia::factory()->create([
            'catalog_title_id' => $title->id,
            'season_id' => $season->id,
            'episode_id' => $episode->id,
            'status' => 'published',
            'published_at' => $publishedAt,
            'path' => 'licensed/calendar-test.mp4',
        ]));

        return ReleaseScheduleEntry::query()->create([
            'logical_key' => 'portal-publication-test-'.$media->id,
            'entry_type' => ReleaseScheduleEntryType::PortalPublication,
            'status' => ReleaseScheduleStatus::Released,
            'precision' => ReleaseDatePrecision::ExactDateTime,
            'source' => ReleaseScheduleSource::Portal,
            'catalog_title_id' => $title->id,
            'season_id' => $season->id,
            'episode_id' => $episode->id,
            'licensed_media_id' => $media->id,
            'season_number' => 1,
            'episode_number' => 1,
            'starts_at' => $publishedAt,
            'original_timezone' => 'UTC',
            'is_public' => true,
            'notifications_enabled' => true,
            'released_at' => $publishedAt,
        ]);
    }

    private function createScheduledEntry(
        string $titleText,
        CarbonImmutable $startsAt,
    ): ReleaseScheduleEntry {
        $title = CatalogTitle::factory()->create([
            'title' => $titleText,
            'slug' => 'calendar-scheduled-'.str()->uuid(),
        ]);
        $season = Season::factory()->for($title)->create(['number' => 1]);
        $episode = Episode::factory()->for($season)->create(['number' => 1]);

        return ReleaseScheduleEntry::query()->create([
            'logical_key' => 'episode-release-test-'.$episode->id,
            'entry_type' => ReleaseScheduleEntryType::EpisodeRelease,
            'status' => ReleaseScheduleStatus::Confirmed,
            'precision' => ReleaseDatePrecision::ExactDateTime,
            'source' => ReleaseScheduleSource::Official,
            'catalog_title_id' => $title->id,
            'season_id' => $season->id,
            'episode_id' => $episode->id,
            'season_number' => 1,
            'episode_number' => 1,
            'starts_at' => $startsAt,
            'original_timezone' => 'UTC',
            'is_public' => true,
            'notifications_enabled' => false,
        ]);
    }
}
