<?php

declare(strict_types=1);

namespace App\Services\Auth;

use Illuminate\Support\Facades\Schema;

final class AccountSettingsSchema
{
    private ?bool $available = null;

    private ?bool $translationPreferencesAvailable = null;

    public function available(): bool
    {
        return $this->available ??= Schema::hasTable('user_account_settings');
    }

    public function translationPreferencesAvailable(): bool
    {
        return $this->translationPreferencesAvailable ??= $this->available()
            && Schema::hasColumns('user_account_settings', [
                'fallback_variant',
                'preferred_playback_mode',
                'preferred_subtitle_language',
                'notify_preferred_translation',
            ])
            && Schema::hasTable('user_hidden_playback_variants')
            && Schema::hasColumn('licensed_media', 'subtitle_language');
    }
}
