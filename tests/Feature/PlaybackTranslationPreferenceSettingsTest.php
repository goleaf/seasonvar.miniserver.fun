<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\DTOs\PlaybackSettingsData;
use App\Enums\PlaybackPreferenceMode;
use App\Models\CatalogTitle;
use App\Models\LicensedMedia;
use App\Models\User;
use App\Services\Auth\AccountDataExportService;
use App\Services\Auth\AccountSettingsService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class PlaybackTranslationPreferenceSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_schema_adds_normalized_translation_preferences_with_cascade_and_unique_identity(): void
    {
        $this->assertTrue(Schema::hasColumns('user_account_settings', [
            'fallback_variant',
            'preferred_playback_mode',
            'preferred_subtitle_language',
            'notify_preferred_translation',
        ]));
        $this->assertTrue(Schema::hasColumn('licensed_media', 'subtitle_language'));
        $this->assertTrue(Schema::hasTable('user_hidden_playback_variants'));
        $this->assertSame(
            ['notify_preferred_translation', 'preferred_variant', 'user_id'],
            collect(DB::select("PRAGMA index_info('user_account_preferred_translation_notify_idx')"))
                ->pluck('name')
                ->all(),
        );

        $user = User::factory()->create();
        DB::table('user_hidden_playback_variants')->insert([
            'user_id' => $user->id,
            'variant_key' => 'voiceover-studio-c',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->expectException(QueryException::class);

        DB::table('user_hidden_playback_variants')->insert([
            'user_id' => $user->id,
            'variant_key' => 'voiceover-studio-c',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_hidden_variants_are_deleted_with_the_account(): void
    {
        $user = User::factory()->create();
        DB::table('user_hidden_playback_variants')->insert([
            'user_id' => $user->id,
            'variant_key' => 'voiceover-studio-c',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $user->deleteOrFail();

        $this->assertDatabaseMissing('user_hidden_playback_variants', [
            'user_id' => $user->id,
        ]);
    }

    public function test_service_atomically_saves_and_resolves_translation_preferences(): void
    {
        $user = User::factory()->create();
        $this->createVariant('voiceover-studio-a', 'Студия А');
        $this->createVariant('voiceover-studio-b', 'Студия Б');
        $this->createVariant('voiceover-studio-c', 'Студия В');
        $settings = $this->settings(
            preferredVariant: 'voiceover-studio-a',
            fallbackVariant: 'voiceover-studio-b',
            mode: PlaybackPreferenceMode::OriginalSubtitles,
            subtitleLanguage: 'en',
            hidden: ['voiceover-studio-c'],
            notify: true,
        );

        $resolved = app(AccountSettingsService::class)->updatePlayback(
            $user,
            $settings,
        );

        $this->assertSame('voiceover-studio-a', $resolved->preferredVariant);
        $this->assertSame('voiceover-studio-b', $resolved->fallbackVariant);
        $this->assertSame(PlaybackPreferenceMode::OriginalSubtitles, $resolved->playbackMode);
        $this->assertSame('en', $resolved->preferredSubtitleLanguage);
        $this->assertSame(['voiceover-studio-c'], $resolved->hiddenVariantKeys);
        $this->assertTrue($resolved->notifyPreferredTranslation);
        $this->assertTrue($resolved->subtitlesEnabled);
        $this->assertDatabaseHas('user_account_settings', [
            'user_id' => $user->id,
            'preferred_variant' => 'voiceover-studio-a',
            'fallback_variant' => 'voiceover-studio-b',
            'preferred_playback_mode' => 'original_subtitles',
            'preferred_subtitle_language' => 'en',
            'notify_preferred_translation' => true,
            'subtitles_enabled' => true,
        ]);
        $this->assertDatabaseHas('user_hidden_playback_variants', [
            'user_id' => $user->id,
            'variant_key' => 'voiceover-studio-c',
        ]);
        $exported = app(AccountDataExportService::class)->export($user)['settings'];
        $this->assertSame('voiceover-studio-a', $exported['preferred_variant']);
        $this->assertSame('voiceover-studio-b', $exported['fallback_variant']);
        $this->assertSame('original_subtitles', $exported['preferred_playback_mode']);
        $this->assertSame('en', $exported['preferred_subtitle_language']);
        $this->assertSame(['voiceover-studio-c'], $exported['hidden_variant_keys']);
        $this->assertTrue($exported['notify_preferred_translation']);

        $sameSettings = app(AccountSettingsService::class)->updatePlayback($user, $settings);

        $this->assertSame($resolved->version, $sameSettings->version);
        $this->assertDatabaseCount('user_hidden_playback_variants', 1);
    }

    public function test_conflicting_preferences_fail_without_partial_write(): void
    {
        $user = User::factory()->create();
        $this->createVariant('voiceover-studio-a', 'Студия А');
        $service = app(AccountSettingsService::class);

        try {
            $service->updatePlayback(
                $user,
                $this->settings(
                    preferredVariant: 'voiceover-studio-a',
                    fallbackVariant: 'voiceover-studio-a',
                    hidden: ['voiceover-studio-a'],
                ),
            );
            $this->fail('ValidationException was not thrown.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('fallbackVariant', $exception->errors());
            $this->assertArrayHasKey('hiddenVariantKeys', $exception->errors());
        }

        $this->assertDatabaseMissing('user_account_settings', [
            'user_id' => $user->id,
        ]);
        $this->assertDatabaseMissing('user_hidden_playback_variants', [
            'user_id' => $user->id,
        ]);
    }

    public function test_unsupported_language_and_more_than_fifty_hidden_variants_fail_without_partial_write(): void
    {
        $user = User::factory()->create();
        $hidden = [];

        foreach (range(1, 51) as $number) {
            $key = 'voiceover-studio-'.$number;
            $this->createVariant($key, 'Студия '.$number);
            $hidden[] = $key;
        }

        try {
            app(AccountSettingsService::class)->updatePlayback(
                $user,
                $this->settings(
                    preferredVariant: null,
                    fallbackVariant: null,
                    subtitleLanguage: 'xx',
                    hidden: $hidden,
                ),
            );
            $this->fail('ValidationException was not thrown.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('preferredSubtitleLanguage', $exception->errors());
            $this->assertArrayHasKey('hiddenVariantKeys', $exception->errors());
        }

        $this->assertDatabaseMissing('user_account_settings', [
            'user_id' => $user->id,
        ]);
        $this->assertDatabaseMissing('user_hidden_playback_variants', [
            'user_id' => $user->id,
        ]);
    }

    public function test_reset_clears_translation_preferences_without_touching_other_user_data(): void
    {
        $user = User::factory()->create();
        $this->createVariant('voiceover-studio-a', 'Студия А');
        $this->createVariant('voiceover-studio-b', 'Студия Б');
        $this->createVariant('voiceover-studio-c', 'Студия В');
        $service = app(AccountSettingsService::class);
        $service->updatePlayback(
            $user,
            $this->settings(
                preferredVariant: 'voiceover-studio-a',
                fallbackVariant: 'voiceover-studio-b',
                mode: PlaybackPreferenceMode::Dubbed,
                subtitleLanguage: 'ru',
                hidden: ['voiceover-studio-c'],
                notify: true,
            ),
        );

        $resolved = $service->resetPlayback($user);

        $this->assertNull($resolved->preferredVariant);
        $this->assertNull($resolved->fallbackVariant);
        $this->assertSame(PlaybackPreferenceMode::Automatic, $resolved->playbackMode);
        $this->assertNull($resolved->preferredSubtitleLanguage);
        $this->assertSame([], $resolved->hiddenVariantKeys);
        $this->assertFalse($resolved->notifyPreferredTranslation);
        $this->assertDatabaseMissing('user_hidden_playback_variants', [
            'user_id' => $user->id,
        ]);
    }

    /**
     * @param  list<string>  $hidden
     */
    private function settings(
        ?string $preferredVariant,
        ?string $fallbackVariant,
        PlaybackPreferenceMode $mode = PlaybackPreferenceMode::Automatic,
        ?string $subtitleLanguage = null,
        array $hidden = [],
        bool $notify = false,
    ): PlaybackSettingsData {
        return new PlaybackSettingsData(
            autoplay: false,
            rememberVolume: true,
            volume: 70,
            muted: false,
            playbackSpeed: '1.00',
            preferredQuality: null,
            preferredVariant: $preferredVariant,
            subtitlesEnabled: false,
            keyboardShortcutsEnabled: true,
            fallbackVariant: $fallbackVariant,
            playbackMode: $mode,
            preferredSubtitleLanguage: $subtitleLanguage,
            hiddenVariantKeys: $hidden,
            notifyPreferredTranslation: $notify,
        );
    }

    private function createVariant(string $key, string $label): LicensedMedia
    {
        return LicensedMedia::factory()->create([
            'catalog_title_id' => CatalogTitle::factory(),
            'status' => 'published',
            'published_at' => now(),
            'playback_url' => 'https://data00-cdn.11cdn.org/'.$key.'.mp4',
            'path' => 'https://data00-cdn.11cdn.org/'.$key.'.mp4',
            'health_status' => 'active',
            'variant_type' => 'voiceover',
            'variant_name' => $label,
            'variant_key' => $key,
            'format' => 'mp4',
        ]);
    }
}
