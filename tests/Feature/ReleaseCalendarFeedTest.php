<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\AccountRestrictionType;
use App\Enums\ReleaseCalendarFeedScope;
use App\Enums\ReleaseDatePrecision;
use App\Enums\ReleaseScheduleEntryType;
use App\Enums\ReleaseScheduleSource;
use App\Enums\ReleaseScheduleStatus;
use App\Models\AccountRestriction;
use App\Models\CatalogCollection;
use App\Models\CatalogCollectionItem;
use App\Models\CatalogTitle;
use App\Models\Episode;
use App\Models\LicensedMedia;
use App\Models\ReleaseCalendarFeed;
use App\Models\ReleaseCalendarSubscription;
use App\Models\ReleaseScheduleEntry;
use App\Models\Season;
use App\Models\User;
use App\Services\ReleaseCalendar\ReleaseCalendarAccountService;
use App\Services\ReleaseCalendar\ReleaseCalendarFeedService;
use App\Services\ReleaseCalendar\ReleaseCalendarTargetMergeService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class ReleaseCalendarFeedTest extends TestCase
{
    use RefreshDatabase;

    public function test_private_all_calendar_feed_returns_visible_personal_events_as_ics(): void
    {
        CarbonImmutable::setTestNow('2026-07-26 12:00:00 UTC');
        $user = User::factory()->create();
        $title = CatalogTitle::factory()->create([
            'title' => 'Личный календарный сериал',
            'slug' => 'lichnyj-kalendarnyj-serial',
        ]);
        ReleaseCalendarSubscription::query()->create([
            'user_id' => $user->id,
            'catalog_title_id' => $title->id,
        ]);
        $entry = ReleaseScheduleEntry::query()->create([
            'logical_key' => 'calendar-feed-serial-premiere-'.$title->id,
            'entry_type' => ReleaseScheduleEntryType::SerialPremiere,
            'status' => ReleaseScheduleStatus::Confirmed,
            'precision' => ReleaseDatePrecision::ExactDateTime,
            'source' => ReleaseScheduleSource::Official,
            'catalog_title_id' => $title->id,
            'starts_at' => CarbonImmutable::parse('2026-08-10 18:30:00 UTC'),
            'original_timezone' => 'UTC',
            'is_public' => true,
        ]);
        $feed = app(ReleaseCalendarFeedService::class)->create(
            $user,
            ReleaseCalendarFeedScope::All,
            'ru',
        );

        $response = $this->get(route('calendar.feed', [
            'privateToken' => $feed->token_secret,
        ]));

        $response
            ->assertOk()
            ->assertHeader('Content-Type', 'text/calendar; charset=utf-8')
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive')
            ->assertHeader('Referrer-Policy', 'no-referrer')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertSee('BEGIN:VCALENDAR', false)
            ->assertSee('BEGIN:VEVENT', false)
            ->assertSee('UID:'.$entry->public_id.'@', false)
            ->assertSee('DTSTART:20260810T183000Z', false)
            ->assertSee('Личный календарный сериал', false)
            ->assertSee('END:VCALENDAR', false);
        $this->assertTrue($response->headers->hasCacheControlDirective('private'));
        $this->assertTrue($response->headers->hasCacheControlDirective('no-store'));
        $this->assertSame('0', $response->headers->getCacheControlDirective('max-age'));
        $this->assertFalse($response->headers->has('Set-Cookie'));
    }

    public function test_collection_feed_rejects_a_collection_owned_by_another_user(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $collection = CatalogCollection::query()->create([
            'public_id' => (string) Str::uuid(),
            'owner_id' => $otherUser->id,
            'name' => 'Чужая подборка',
            'slug' => 'chuzhaya-podborka',
        ]);

        try {
            app(ReleaseCalendarFeedService::class)->create(
                $user,
                ReleaseCalendarFeedScope::Collection,
                'ru',
                collection: $collection,
            );
            $this->fail('Чужая подборка не должна создавать приватный feed.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('feedCollection', $exception->errors());
        }

        $this->assertSame(0, ReleaseCalendarFeed::query()->count());
    }

    public function test_regenerating_a_feed_atomically_revokes_the_old_private_url(): void
    {
        $user = User::factory()->create();
        $service = app(ReleaseCalendarFeedService::class);
        $feed = $service->create($user, ReleaseCalendarFeedScope::All, 'ru');
        $oldToken = $feed->token_secret;

        $rotated = $service->regenerate($user, $feed->public_id);

        $this->assertNotSame($oldToken, $rotated->token_secret);
        $this->assertSame(2, $rotated->version);
        $this->get(route('calendar.feed', ['privateToken' => $oldToken]))
            ->assertNotFound();
        $this->get(route('calendar.feed', ['privateToken' => $rotated->token_secret]))
            ->assertOk();
    }

    public function test_deleting_a_feed_revokes_its_private_url_without_touching_another_feed(): void
    {
        $user = User::factory()->create();
        $service = app(ReleaseCalendarFeedService::class);
        $deletedFeed = $service->create($user, ReleaseCalendarFeedScope::All, 'ru');
        $remainingFeed = $service->create($user, ReleaseCalendarFeedScope::NewEpisodes, 'ru');

        $service->delete($user, $deletedFeed->public_id);

        $this->get(route('calendar.feed', ['privateToken' => $deletedFeed->token_secret]))
            ->assertNotFound();
        $this->get(route('calendar.feed', ['privateToken' => $remainingFeed->token_secret]))
            ->assertOk();
        $this->assertSame(1, ReleaseCalendarFeed::query()->count());
    }

    public function test_personal_category_collection_and_title_scopes_filter_the_ics_result_set(): void
    {
        CarbonImmutable::setTestNow('2026-07-26 12:00:00 UTC');
        $user = User::factory()->create();
        [$episodeTitle] = $this->createPersonalEntry(
            $user,
            'Только новая серия',
            ReleaseScheduleEntryType::EpisodeRelease,
        );
        [$seasonTitle] = $this->createPersonalEntry(
            $user,
            'Только премьера сезона',
            ReleaseScheduleEntryType::SeasonPremiere,
        );
        [$collectionTitle] = $this->createPersonalEntry(
            $user,
            'Только выбранная подборка',
            ReleaseScheduleEntryType::SerialPremiere,
        );
        [$specificTitle] = $this->createPersonalEntry(
            $user,
            'Только выбранный сериал',
            ReleaseScheduleEntryType::SerialPremiere,
        );
        $collection = CatalogCollection::query()->create([
            'public_id' => (string) Str::uuid(),
            'owner_id' => $user->id,
            'name' => 'Моя календарная подборка',
            'slug' => 'moya-kalendarnaya-podborka',
        ]);
        CatalogCollectionItem::query()->create([
            'catalog_collection_id' => $collection->id,
            'catalog_title_id' => $collectionTitle->id,
            'added_by_id' => $user->id,
            'position' => 1,
        ]);
        $service = app(ReleaseCalendarFeedService::class);

        $all = $this->feedBody($service->create($user, ReleaseCalendarFeedScope::All, 'ru'));
        $episodes = $this->feedBody($service->create($user, ReleaseCalendarFeedScope::NewEpisodes, 'ru'));
        $seasons = $this->feedBody($service->create($user, ReleaseCalendarFeedScope::SeasonPremieres, 'ru'));
        $collectionFeed = $this->feedBody($service->create(
            $user,
            ReleaseCalendarFeedScope::Collection,
            'ru',
            collection: $collection,
        ));
        $titleFeed = $this->feedBody($service->create(
            $user,
            ReleaseCalendarFeedScope::Title,
            'ru',
            title: $specificTitle,
        ));

        $this->assertStringContainsString($episodeTitle->title, $all);
        $this->assertStringContainsString($seasonTitle->title, $all);
        $this->assertStringContainsString($episodeTitle->title, $episodes);
        $this->assertStringNotContainsString($seasonTitle->title, $episodes);
        $this->assertStringContainsString($seasonTitle->title, $seasons);
        $this->assertStringNotContainsString($episodeTitle->title, $seasons);
        $this->assertStringContainsString($collectionTitle->title, $collectionFeed);
        $this->assertStringNotContainsString($specificTitle->title, $collectionFeed);
        $this->assertStringContainsString($specificTitle->title, $titleFeed);
        $this->assertStringNotContainsString($collectionTitle->title, $titleFeed);
    }

    public function test_translation_and_subtitle_scopes_support_exact_track_and_optional_title_filters(): void
    {
        CarbonImmutable::setTestNow('2026-07-26 12:00:00 UTC');
        $user = User::factory()->create();
        [$selectedTranslationTitle] = $this->createPersonalEntry(
            $user,
            'Выбранный RuDub',
            ReleaseScheduleEntryType::TranslationRelease,
            languageCode: 'ru',
            translationName: 'RuDub',
        );
        $this->createPersonalEntry(
            $user,
            'Другой сериал RuDub',
            ReleaseScheduleEntryType::TranslationRelease,
            languageCode: 'ru',
            translationName: 'RuDub',
        );
        $this->createPersonalEntry(
            $user,
            'Другой перевод',
            ReleaseScheduleEntryType::TranslationRelease,
            languageCode: 'ru',
            translationName: 'OtherDub',
        );
        [$selectedSubtitleTitle] = $this->createPersonalEntry(
            $user,
            'Выбранные английские субтитры',
            ReleaseScheduleEntryType::SubtitleRelease,
            languageCode: 'en',
        );
        $this->createPersonalEntry(
            $user,
            'Другой сериал с английскими субтитрами',
            ReleaseScheduleEntryType::SubtitleRelease,
            languageCode: 'en',
        );
        $service = app(ReleaseCalendarFeedService::class);

        $translationFeed = $this->feedBody($service->create(
            $user,
            ReleaseCalendarFeedScope::Translation,
            'ru',
            title: $selectedTranslationTitle,
            languageCode: ' RU ',
            translationName: ' RuDub ',
        ));
        $subtitleFeed = $this->feedBody($service->create(
            $user,
            ReleaseCalendarFeedScope::Subtitles,
            'ru',
            title: $selectedSubtitleTitle,
            languageCode: ' EN ',
        ));

        $this->assertSame(1, substr_count($translationFeed, 'BEGIN:VEVENT'));
        $this->assertStringContainsString('Выбранный RuDub', $translationFeed);
        $this->assertStringNotContainsString('Другой сериал RuDub', $translationFeed);
        $this->assertStringNotContainsString('OtherDub', $translationFeed);
        $this->assertSame(1, substr_count($subtitleFeed, 'BEGIN:VEVENT'));
        $this->assertStringContainsString('Выбранные английские субтитры', $subtitleFeed);
        $this->assertStringNotContainsString('Другой сериал с английскими субтитрами', $subtitleFeed);
    }

    public function test_an_explicit_title_track_feed_does_not_require_a_personal_calendar_subscription(): void
    {
        CarbonImmutable::setTestNow('2026-07-26 12:00:00 UTC');
        $user = User::factory()->create();
        [$title] = $this->createPersonalEntry(
            $user,
            'Публичный сериал без личной подписки',
            ReleaseScheduleEntryType::TranslationRelease,
            languageCode: 'ru',
            translationName: 'SelectedDub',
        );
        ReleaseCalendarSubscription::query()
            ->whereBelongsTo($user)
            ->whereBelongsTo($title)
            ->delete();

        $feed = app(ReleaseCalendarFeedService::class)->create(
            $user,
            ReleaseCalendarFeedScope::Translation,
            'ru',
            title: $title,
            languageCode: 'ru',
            translationName: 'SelectedDub',
        );

        $this->assertStringContainsString(
            'Публичный сериал',
            $this->feedBody($feed),
        );
    }

    public function test_feed_tokens_are_high_entropy_hashed_encrypted_and_hidden_from_serialization(): void
    {
        $user = User::factory()->create();
        $feed = app(ReleaseCalendarFeedService::class)->create(
            $user,
            ReleaseCalendarFeedScope::All,
            'ru',
        );
        $stored = DB::table('release_calendar_feeds')->where('id', $feed->id)->first();

        $this->assertSame(64, strlen($feed->token_secret));
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]{64}$/', $feed->token_secret);
        $this->assertSame(hash('sha256', $feed->token_secret), $stored->token_hash);
        $this->assertNotSame($feed->token_secret, $stored->token_secret);
        $this->assertArrayNotHasKey('token_hash', $feed->toArray());
        $this->assertArrayNotHasKey('token_secret', $feed->toArray());
    }

    public function test_invalid_scope_combinations_and_the_per_user_limit_are_rejected(): void
    {
        $user = User::factory()->create();
        $title = CatalogTitle::factory()->create();
        $service = app(ReleaseCalendarFeedService::class);

        $this->assertValidationError(
            fn (): ReleaseCalendarFeed => $service->create(
                $user,
                ReleaseCalendarFeedScope::All,
                'ru',
                title: $title,
            ),
            'feedScope',
        );
        $this->assertValidationError(
            fn (): ReleaseCalendarFeed => $service->create(
                $user,
                ReleaseCalendarFeedScope::Translation,
                'ru',
                title: $title,
                languageCode: 'ru',
            ),
            'feedScope',
        );
        $this->assertValidationError(
            fn (): ReleaseCalendarFeed => $service->create(
                $user,
                ReleaseCalendarFeedScope::Subtitles,
                'ru',
            ),
            'feedScope',
        );
        $this->assertValidationError(
            fn (): ReleaseCalendarFeed => $service->create(
                $user,
                ReleaseCalendarFeedScope::Subtitles,
                'ru',
                languageCode: 'not a locale',
            ),
            'feedLanguageCode',
        );
        $this->assertValidationError(
            fn (): ReleaseCalendarFeed => $service->create(
                $user,
                ReleaseCalendarFeedScope::All,
                'de',
            ),
            'feedLocale',
        );

        config()->set('release-calendar.feeds.max_per_user', 2);
        $service->create($user, ReleaseCalendarFeedScope::All, 'ru');
        $service->create($user, ReleaseCalendarFeedScope::NewEpisodes, 'ru');
        $this->assertValidationError(
            fn (): ReleaseCalendarFeed => $service->create(
                $user,
                ReleaseCalendarFeedScope::SeasonPremieres,
                'ru',
            ),
            'feedScope',
        );
        $this->assertSame(2, ReleaseCalendarFeed::query()->count());
    }

    public function test_blocked_accounts_invalid_tokens_and_deleted_owners_cannot_use_a_private_feed(): void
    {
        $administrator = User::factory()->create();
        $user = User::factory()->create();
        $feed = app(ReleaseCalendarFeedService::class)->create(
            $user,
            ReleaseCalendarFeedScope::All,
            'ru',
        );

        $invalidResponse = $this->get('/calendar/feed/'.str_repeat('x', 64).'.ics');
        $invalidResponse->assertNotFound();
        $this->assertFalse($invalidResponse->headers->has('Set-Cookie'));

        AccountRestriction::query()->create([
            'user_id' => $user->id,
            'type' => AccountRestrictionType::LoginSuspended,
            'reason_code' => 'security_review',
            'public_notice_key' => AccountRestrictionType::LoginSuspended->noticeKey(),
            'applied_by_id' => $administrator->id,
            'starts_at' => now()->subMinute(),
        ]);
        $this->get(route('calendar.feed', ['privateToken' => $feed->token_secret]))
            ->assertNotFound();

        $user->delete();
        $this->assertDatabaseMissing('release_calendar_feeds', ['id' => $feed->id]);
    }

    public function test_private_feed_rate_limit_is_token_scoped_and_keeps_private_response_headers(): void
    {
        config()->set('release-calendar.rate_limits.feed_requests_per_minute', 1);
        config()->set('release-calendar.rate_limits.feed_requests_per_ip_per_minute', 100);
        RateLimiter::clear('calendar-feed');
        $user = User::factory()->create();
        $feed = app(ReleaseCalendarFeedService::class)->create(
            $user,
            ReleaseCalendarFeedScope::All,
            'ru',
        );
        $url = route('calendar.feed', ['privateToken' => $feed->token_secret]);

        $this->get($url)->assertOk();
        $limited = $this->get($url)
            ->assertTooManyRequests()
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive')
            ->assertHeader('Referrer-Policy', 'no-referrer')
            ->assertHeader('X-Content-Type-Options', 'nosniff');

        $this->assertTrue($limited->headers->hasCacheControlDirective('private'));
        $this->assertTrue($limited->headers->hasCacheControlDirective('no-store'));
        $this->assertFalse($limited->headers->has('Set-Cookie'));
    }

    public function test_account_export_excludes_private_token_material(): void
    {
        $user = User::factory()->create();
        $feed = app(ReleaseCalendarFeedService::class)->create(
            $user,
            ReleaseCalendarFeedScope::NewEpisodes,
            'ru',
        );

        $export = app(ReleaseCalendarAccountService::class)->export($user);

        $this->assertSame($feed->public_id, $export['feeds'][0]['public_id']);
        $this->assertSame('new_episodes', $export['feeds'][0]['scope']);
        $this->assertArrayNotHasKey('token_hash', $export['feeds'][0]);
        $this->assertArrayNotHasKey('token_secret', $export['feeds'][0]);
        $this->assertStringNotContainsString($feed->token_secret, json_encode($export, JSON_THROW_ON_ERROR));
    }

    public function test_title_merge_retargets_existing_private_feeds(): void
    {
        $user = User::factory()->create();
        $source = CatalogTitle::factory()->create();
        $target = CatalogTitle::factory()->create();
        $feed = app(ReleaseCalendarFeedService::class)->create(
            $user,
            ReleaseCalendarFeedScope::Title,
            'ru',
            title: $source,
        );

        app(ReleaseCalendarTargetMergeService::class)->moveTitle($source, $target);

        $this->assertSame($target->id, $feed->refresh()->catalog_title_id);
    }

    public function test_ics_uses_rfc_date_ranges_escaping_and_octet_safe_line_folding(): void
    {
        CarbonImmutable::setTestNow('2026-07-26 12:00:00 UTC');
        $user = User::factory()->create();
        $title = CatalogTitle::factory()->create([
            'title' => 'Очень длинный сериал; специальный, календарный выпуск с кириллицей',
            'slug' => 'ics-escaping-and-folding',
        ]);
        ReleaseCalendarSubscription::query()->create([
            'user_id' => $user->id,
            'catalog_title_id' => $title->id,
        ]);

        ReleaseScheduleEntry::query()->create([
            'logical_key' => 'ics-exact-date',
            'entry_type' => ReleaseScheduleEntryType::SerialPremiere,
            'status' => ReleaseScheduleStatus::Confirmed,
            'precision' => ReleaseDatePrecision::ExactDate,
            'source' => ReleaseScheduleSource::Official,
            'catalog_title_id' => $title->id,
            'date_value' => '2026-08-01',
            'is_public' => true,
        ]);
        ReleaseScheduleEntry::query()->create([
            'logical_key' => 'ics-date-range',
            'entry_type' => ReleaseScheduleEntryType::SeasonPremiere,
            'status' => ReleaseScheduleStatus::Confirmed,
            'precision' => ReleaseDatePrecision::DateRange,
            'source' => ReleaseScheduleSource::Official,
            'catalog_title_id' => $title->id,
            'season_number' => 2,
            'date_value' => '2026-08-03',
            'date_end' => '2026-08-05',
            'is_public' => true,
        ]);
        ReleaseScheduleEntry::query()->create([
            'logical_key' => 'ics-partial-month',
            'entry_type' => ReleaseScheduleEntryType::SerialPremiere,
            'status' => ReleaseScheduleStatus::Scheduled,
            'precision' => ReleaseDatePrecision::Month,
            'source' => ReleaseScheduleSource::Official,
            'catalog_title_id' => $title->id,
            'release_year' => 2026,
            'release_month' => 8,
            'is_public' => true,
        ]);
        $feed = app(ReleaseCalendarFeedService::class)->create(
            $user,
            ReleaseCalendarFeedScope::All,
            'ru',
        );

        $body = $this->feedBody($feed);
        $unfolded = str_replace("\r\n ", '', $body);

        $this->assertSame(2, substr_count($body, 'BEGIN:VEVENT'));
        $this->assertStringContainsString('DTSTART;VALUE=DATE:20260801', $body);
        $this->assertStringContainsString('DTEND;VALUE=DATE:20260802', $body);
        $this->assertStringContainsString('DTSTART;VALUE=DATE:20260803', $body);
        $this->assertStringContainsString('DTEND;VALUE=DATE:20260806', $body);
        $this->assertStringContainsString('сериал\\; специальный\\, календарный', $unfolded);

        foreach (explode("\r\n", rtrim($body)) as $line) {
            $this->assertLessThanOrEqual(75, strlen($line), $line);
        }
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    /** @return array{CatalogTitle, ReleaseScheduleEntry} */
    private function createPersonalEntry(
        User $user,
        string $titleText,
        ReleaseScheduleEntryType $type,
        ?string $languageCode = null,
        ?string $translationName = null,
    ): array {
        $title = CatalogTitle::factory()->create([
            'title' => $titleText,
            'slug' => 'calendar-feed-'.Str::uuid(),
        ]);
        $season = Season::factory()->for($title)->create(['number' => 1]);
        $episode = Episode::factory()->for($season)->create(['number' => 1]);
        $media = null;

        if (in_array($type, [
            ReleaseScheduleEntryType::TranslationRelease,
            ReleaseScheduleEntryType::SubtitleRelease,
        ], true)) {
            $media = LicensedMedia::withoutEvents(fn (): LicensedMedia => LicensedMedia::factory()->create([
                'catalog_title_id' => $title->id,
                'season_id' => $season->id,
                'episode_id' => $episode->id,
                'status' => 'published',
                'published_at' => CarbonImmutable::now(),
                'path' => 'licensed/calendar-feed-'.Str::uuid().'.mp4',
                'translation_name' => $translationName,
                'has_subtitles' => $type === ReleaseScheduleEntryType::SubtitleRelease,
            ]));
        }

        ReleaseCalendarSubscription::query()->create([
            'user_id' => $user->id,
            'catalog_title_id' => $title->id,
        ]);
        $entry = ReleaseScheduleEntry::query()->create([
            'logical_key' => 'calendar-feed-'.Str::uuid(),
            'entry_type' => $type,
            'status' => ReleaseScheduleStatus::Confirmed,
            'precision' => ReleaseDatePrecision::ExactDateTime,
            'source' => ReleaseScheduleSource::Official,
            'catalog_title_id' => $title->id,
            'season_id' => $type === ReleaseScheduleEntryType::SerialPremiere ? null : $season->id,
            'episode_id' => in_array($type, [
                ReleaseScheduleEntryType::EpisodeRelease,
                ReleaseScheduleEntryType::TranslationRelease,
                ReleaseScheduleEntryType::SubtitleRelease,
            ], true) ? $episode->id : null,
            'licensed_media_id' => $media?->id,
            'season_number' => $type === ReleaseScheduleEntryType::SerialPremiere ? null : 1,
            'episode_number' => in_array($type, [
                ReleaseScheduleEntryType::EpisodeRelease,
                ReleaseScheduleEntryType::TranslationRelease,
                ReleaseScheduleEntryType::SubtitleRelease,
            ], true) ? 1 : null,
            'language_code' => $languageCode,
            'translation_name' => $translationName,
            'starts_at' => CarbonImmutable::now()->addDays(10),
            'original_timezone' => 'UTC',
            'is_public' => true,
        ]);

        return [$title, $entry];
    }

    private function feedBody(ReleaseCalendarFeed $feed): string
    {
        return (string) $this->get(route('calendar.feed', [
            'privateToken' => $feed->token_secret,
        ]))->assertOk()->getContent();
    }

    /** @param callable(): ReleaseCalendarFeed $callback */
    private function assertValidationError(callable $callback, string $field): void
    {
        try {
            $callback();
            $this->fail("Ожидалась ошибка валидации поля {$field}.");
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey($field, $exception->errors());
        }
    }
}
