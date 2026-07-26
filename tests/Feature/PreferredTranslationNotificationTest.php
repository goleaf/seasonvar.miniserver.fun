<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\CatalogTitle;
use App\Models\Episode;
use App\Models\LicensedMedia;
use App\Models\Season;
use App\Models\User;
use App\Models\UserAccountSetting;
use App\Models\UserHiddenPlaybackVariant;
use App\Services\Catalog\PreferredTranslationNotificationService;
use App\Services\ReleaseCalendar\ReleaseCalendarNotificationQuery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class PreferredTranslationNotificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_favorite_translation_notification_is_opt_in_idempotent_and_safe(): void
    {
        $recipient = User::factory()->create();
        $disabled = User::factory()->create();
        $hidden = User::factory()->create();
        UserAccountSetting::query()->create([
            'user_id' => $recipient->id,
            'preferred_variant' => 'voiceover-studio-a',
            'notify_preferred_translation' => true,
        ]);
        UserAccountSetting::query()->create([
            'user_id' => $disabled->id,
            'preferred_variant' => 'voiceover-studio-a',
            'notify_preferred_translation' => false,
        ]);
        UserAccountSetting::query()->create([
            'user_id' => $hidden->id,
            'preferred_variant' => 'voiceover-studio-a',
            'notify_preferred_translation' => true,
        ]);
        UserHiddenPlaybackVariant::query()->create([
            'user_id' => $hidden->id,
            'variant_key' => 'voiceover-studio-a',
        ]);
        $title = CatalogTitle::factory()->create(['title' => 'Сериал с переводом']);
        $season = Season::factory()->create(['catalog_title_id' => $title->id]);
        $episode = Episode::factory()->create(['season_id' => $season->id, 'number' => 1]);
        $media = $this->media($title, $season, $episode);
        $service = app(PreferredTranslationNotificationService::class);

        $service->available($media);
        $service->available($media);

        $notification = $recipient->notifications()
            ->where('type', 'playback-preference.translation-available')
            ->sole();
        $payload = json_encode($notification->data, JSON_THROW_ON_ERROR);

        $this->assertSame('voiceover-studio-a', $notification->data['variant_key']);
        $this->assertSame($title->slug, $notification->data['catalog_title_slug']);
        $this->assertStringNotContainsString('11cdn.org', $payload);
        $this->assertArrayNotHasKey('user_id', $notification->data);
        $this->assertArrayNotHasKey('licensed_media_id', $notification->data);
        $this->assertSame(1, $recipient->notifications()->where('type', 'playback-preference.translation-available')->count());
        $this->assertSame(0, $disabled->notifications()->where('type', 'playback-preference.translation-available')->count());
        $this->assertSame(0, $hidden->notifications()->where('type', 'playback-preference.translation-available')->count());
    }

    public function test_favorite_translation_notification_is_presented_in_existing_release_inbox(): void
    {
        app()->setLocale('ru');
        $recipient = User::factory()->create();
        UserAccountSetting::query()->create([
            'user_id' => $recipient->id,
            'preferred_variant' => 'voiceover-studio-a',
            'notify_preferred_translation' => true,
        ]);
        $title = CatalogTitle::factory()->create(['title' => 'Сериал с переводом']);
        $season = Season::factory()->create(['catalog_title_id' => $title->id]);
        $episode = Episode::factory()->create(['season_id' => $season->id, 'number' => 1]);
        app(PreferredTranslationNotificationService::class)->available(
            $this->media($title, $season, $episode),
        );

        $notifications = app(ReleaseCalendarNotificationQuery::class)->forUser($recipient);

        $this->assertCount(1, $notifications);
        $this->assertSame('Любимый перевод появился', $notifications->first()->label);
        $this->assertStringContainsString('Сериал с переводом', (string) $notifications->first()->detail);
        $this->assertSame(route('titles.show', $title), $notifications->first()->url);
    }

    public function test_unpublished_media_does_not_create_favorite_translation_notification(): void
    {
        $recipient = User::factory()->create();
        UserAccountSetting::query()->create([
            'user_id' => $recipient->id,
            'preferred_variant' => 'voiceover-studio-a',
            'notify_preferred_translation' => true,
        ]);
        $title = CatalogTitle::factory()->create();
        $season = Season::factory()->create(['catalog_title_id' => $title->id]);
        $episode = Episode::factory()->create(['season_id' => $season->id, 'number' => 1]);

        app(PreferredTranslationNotificationService::class)->available(
            $this->media($title, $season, $episode, 'draft'),
        );

        $this->assertSame(0, $recipient->notifications()
            ->where('type', 'playback-preference.translation-available')
            ->count());
    }

    private function media(
        CatalogTitle $title,
        Season $season,
        Episode $episode,
        string $status = 'published',
    ): LicensedMedia {
        return LicensedMedia::factory()->create([
            'catalog_title_id' => $title->id,
            'season_id' => $season->id,
            'episode_id' => $episode->id,
            'status' => $status,
            'published_at' => now()->subMinute(),
            'playback_url' => 'https://data00-cdn.11cdn.org/favorite.mp4',
            'path' => 'https://data00-cdn.11cdn.org/favorite.mp4',
            'health_status' => 'active',
            'variant_type' => 'voiceover',
            'variant_name' => 'Студия А',
            'variant_key' => 'voiceover-studio-a',
            'translation_name' => 'Студия А',
            'format' => 'mp4',
        ]);
    }
}
