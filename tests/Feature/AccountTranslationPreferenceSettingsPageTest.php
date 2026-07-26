<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Livewire\Settings\AccountSettingsPage;
use App\Models\CatalogTitle;
use App\Models\LicensedMedia;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

final class AccountTranslationPreferenceSettingsPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_playback_settings_page_renders_and_saves_translation_preferences(): void
    {
        $user = User::factory()->create();
        $this->variant('voiceover-studio-a', 'Студия А');
        $this->variant('voiceover-studio-b', 'Студия Б');
        $this->variant('voiceover-studio-c', 'Студия В');

        Livewire::actingAs($user)
            ->test(AccountSettingsPage::class, ['section' => 'playback'])
            ->assertSee('Любимая озвучка')
            ->assertSee('Запасной перевод')
            ->assertSee('Оригинал с субтитрами')
            ->assertSee('Язык субтитров')
            ->assertSee('Не показывать переводы')
            ->assertSee('Уведомить, когда появится любимый перевод')
            ->assertDontSee('overflow-y-auto', false)
            ->set('preferredVariant', 'voiceover-studio-a')
            ->set('fallbackVariant', 'voiceover-studio-b')
            ->set('preferredPlaybackMode', 'original_subtitles')
            ->set('preferredSubtitleLanguage', 'en')
            ->set('hiddenVariantKeys', ['voiceover-studio-c'])
            ->set('notifyPreferredTranslation', true)
            ->call('savePlayback')
            ->assertHasNoErrors()
            ->assertSet('statusMessage', __('settings.status.playback_saved'));

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
    }

    public function test_playback_settings_page_rejects_conflicting_translation_preferences(): void
    {
        $user = User::factory()->create();
        $this->variant('voiceover-studio-a', 'Студия А');

        Livewire::actingAs($user)
            ->test(AccountSettingsPage::class, ['section' => 'playback'])
            ->set('preferredVariant', 'voiceover-studio-a')
            ->set('fallbackVariant', 'voiceover-studio-a')
            ->set('hiddenVariantKeys', ['voiceover-studio-a'])
            ->call('savePlayback')
            ->assertHasErrors(['fallbackVariant', 'hiddenVariantKeys']);

        $this->assertDatabaseMissing('user_account_settings', [
            'user_id' => $user->id,
        ]);
    }

    public function test_playback_settings_page_rejects_unknown_mode_and_subtitle_language(): void
    {
        $user = User::factory()->create();

        Livewire::actingAs($user)
            ->test(AccountSettingsPage::class, ['section' => 'playback'])
            ->set('preferredPlaybackMode', 'unsupported-mode')
            ->set('preferredSubtitleLanguage', 'xx')
            ->call('savePlayback')
            ->assertHasErrors(['preferredPlaybackMode', 'preferredSubtitleLanguage']);

        $this->assertDatabaseMissing('user_account_settings', [
            'user_id' => $user->id,
        ]);
    }

    public function test_playback_settings_route_requires_authentication(): void
    {
        $this->get(route('settings.index', ['section' => 'playback']))
            ->assertRedirect(route('login'));
    }

    private function variant(string $key, string $label): LicensedMedia
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
