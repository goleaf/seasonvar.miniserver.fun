<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\DTOs\AccountSettingsData;
use App\DTOs\AnonymousAccountSettingsData;
use App\DTOs\PlaybackSettingsData;
use App\Enums\CatalogCollectionVisibility;
use App\Enums\PlaybackPreferenceMode;
use App\Models\User;
use App\Models\UserAccountSetting;
use App\Models\UserHiddenPlaybackVariant;
use App\Services\Catalog\PlaybackPreferenceOptions;
use App\ValueObjects\AccountTimezone;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final class AccountSettingsService
{
    public function __construct(
        private readonly AccountSettingsSchema $schema,
        private readonly PlaybackPreferenceOptions $playbackOptions,
    ) {}

    public function resolve(?User $user): AccountSettingsData
    {
        $setting = $user !== null && $this->schema->available() && $user->relationLoaded('accountSetting')
            ? $user->accountSetting
            : ($user !== null && $this->schema->available() ? $user->accountSetting()->first() : null);

        if ($user !== null && $this->schema->available() && ! $user->relationLoaded('accountSetting')) {
            $user->setRelation('accountSetting', $setting);
        }

        return $this->resolved(
            $setting,
            $setting instanceof UserAccountSetting ? $this->hiddenVariantKeys($user) : [],
        );
    }

    public function updateAppearance(
        User $user,
        string $locale,
        AccountTimezone $timezone,
        bool $reducedMotion,
    ): AccountSettingsData {
        $this->ensureLocale($locale);

        return $this->mutate($user, [
            'locale' => $locale,
            'timezone' => $timezone->value,
            'reduced_motion' => $reducedMotion,
        ]);
    }

    public function updateLocaleIfAvailable(User $user, string $locale): void
    {
        $this->ensureLocale($locale);

        if (! $this->schema->available()) {
            return;
        }

        $this->mutate($user, ['locale' => $locale]);
    }

    public function adoptLocaleIfUnset(User $user, string $locale): void
    {
        $this->ensureLocale($locale);

        if (! $this->schema->available()) {
            return;
        }

        Gate::forUser($user)->authorize('update-account-settings');

        DB::transaction(function () use ($user, $locale): void {
            User::query()->lockForUpdate()->findOrFail($user->id);
            $setting = UserAccountSetting::query()->lockForUpdate()->find($user->id)
                ?? new UserAccountSetting(['user_id' => $user->id]);

            if ($setting->locale !== null) {
                return;
            }

            $setting->locale = $locale;
            $setting->settings_version = max(1, (int) $setting->settings_version) + 1;
            $setting->save();
            $user->setRelation('accountSetting', $setting);
        }, attempts: 3);
    }

    public function updatePlayback(User $user, PlaybackSettingsData $data): AccountSettingsData
    {
        $this->ensurePlayback($user, $data);

        return $this->mutatePlayback($user, [
            'autoplay' => $data->autoplay,
            'remember_volume' => $data->rememberVolume,
            'volume' => $data->volume,
            'muted' => $data->muted,
            'playback_speed' => $data->playbackSpeed,
            'preferred_quality' => $data->preferredQuality,
            'preferred_variant' => $data->preferredVariant,
            'fallback_variant' => $data->fallbackVariant,
            'preferred_playback_mode' => $data->playbackMode->value,
            'preferred_subtitle_language' => $data->preferredSubtitleLanguage,
            'notify_preferred_translation' => $data->notifyPreferredTranslation,
            'subtitles_enabled' => $data->subtitlesEnabled
                || $data->playbackMode === PlaybackPreferenceMode::OriginalSubtitles
                || $data->preferredSubtitleLanguage !== null,
            'keyboard_shortcuts_enabled' => $data->keyboardShortcutsEnabled,
        ], $data->hiddenVariantKeys);
    }

    public function updateOnboardingPreferences(
        User $user,
        string $locale,
        ?bool $subtitlesEnabled,
        PlaybackPreferenceMode $playbackMode = PlaybackPreferenceMode::Automatic,
    ): AccountSettingsData {
        $this->ensureLocale($locale);

        $attributes = ['locale' => $locale];

        if ($subtitlesEnabled !== null) {
            $attributes['subtitles_enabled'] = $subtitlesEnabled;
        }
        if ($this->schema->translationPreferencesAvailable()) {
            $attributes['preferred_playback_mode'] = $playbackMode->value;
        }

        return $this->mutate($user, $attributes);
    }

    public function updateCollectionDefault(User $user, CatalogCollectionVisibility $visibility): AccountSettingsData
    {
        return $this->mutate($user, [
            'collection_default_visibility' => $visibility->value,
        ]);
    }

    public function resetPlayback(User $user): AccountSettingsData
    {
        $defaults = (array) config('account-settings.defaults', []);

        return $this->mutatePlayback($user, [
            'autoplay' => (bool) ($defaults['autoplay'] ?? false),
            'remember_volume' => (bool) ($defaults['remember_volume'] ?? true),
            'volume' => min(100, max(0, (int) ($defaults['volume'] ?? 70))),
            'muted' => (bool) ($defaults['muted'] ?? false),
            'playback_speed' => (string) ($defaults['playback_speed'] ?? '1.00'),
            'preferred_quality' => null,
            'preferred_variant' => null,
            'fallback_variant' => null,
            'preferred_playback_mode' => PlaybackPreferenceMode::Automatic->value,
            'preferred_subtitle_language' => null,
            'notify_preferred_translation' => false,
            'subtitles_enabled' => (bool) ($defaults['subtitles_enabled'] ?? false),
            'keyboard_shortcuts_enabled' => (bool) ($defaults['keyboard_shortcuts_enabled'] ?? true),
        ], []);
    }

    public function migrateAnonymous(User $user, AnonymousAccountSettingsData $data): AccountSettingsData
    {
        Gate::forUser($user)->authorize('update-account-settings');
        abort_unless($this->schema->available(), 503, __('settings.errors.unavailable'));
        $this->ensureAnonymous($user, $data);

        return DB::transaction(function () use ($user, $data): AccountSettingsData {
            User::query()->lockForUpdate()->findOrFail($user->id);
            $setting = UserAccountSetting::query()->lockForUpdate()->find($user->id)
                ?? new UserAccountSetting(['user_id' => $user->id]);
            $candidates = [
                'locale' => $data->locale,
                'timezone' => $data->timezone,
                'autoplay' => $data->autoplay,
                'remember_volume' => $data->rememberVolume,
                'volume' => $data->volume,
                'muted' => $data->muted,
                'playback_speed' => $data->playbackSpeed,
                'preferred_quality' => $data->preferredQuality,
                'preferred_variant' => $data->preferredVariant,
                'subtitles_enabled' => $data->subtitlesEnabled,
                'keyboard_shortcuts_enabled' => $data->keyboardShortcutsEnabled,
                'reduced_motion' => $data->reducedMotion,
            ];
            $changed = false;

            foreach ($candidates as $attribute => $value) {
                if ($value !== null && $setting->getAttribute($attribute) === null) {
                    $setting->setAttribute($attribute, $value);
                    $changed = true;
                }
            }

            if ($changed) {
                $setting->settings_version = max(1, (int) $setting->settings_version) + 1;
                $setting->save();
            }

            $setting = $setting->exists ? $setting->refresh() : null;
            $user->setRelation('accountSetting', $setting);

            return $this->resolved($setting, $this->hiddenVariantKeys($user, false));
        }, attempts: 3);
    }

    /**
     * @param  array<string, bool|int|string|null>  $attributes
     * @param  list<string>  $hiddenVariantKeys
     */
    private function mutatePlayback(User $user, array $attributes, array $hiddenVariantKeys): AccountSettingsData
    {
        Gate::forUser($user)->authorize('update-account-settings');
        abort_unless($this->schema->translationPreferencesAvailable(), 503, __('settings.errors.unavailable'));
        $hiddenVariantKeys = collect($hiddenVariantKeys)
            ->filter(fn (mixed $key): bool => is_string($key) && $key !== '')
            ->unique()
            ->sort()
            ->values()
            ->all();

        return DB::transaction(function () use ($user, $attributes, $hiddenVariantKeys): AccountSettingsData {
            User::query()->lockForUpdate()->findOrFail($user->id);
            $setting = UserAccountSetting::query()->lockForUpdate()->find($user->id)
                ?? new UserAccountSetting(['user_id' => $user->id]);
            $currentHidden = UserHiddenPlaybackVariant::query()
                ->whereBelongsTo($user)
                ->lockForUpdate()
                ->orderBy('variant_key')
                ->pluck('variant_key')
                ->all();

            foreach ($attributes as $attribute => $value) {
                $setting->setAttribute($attribute, $value);
            }

            $hiddenChanged = $currentHidden !== $hiddenVariantKeys;

            if (! $setting->exists || $setting->isDirty(array_keys($attributes)) || $hiddenChanged) {
                $setting->settings_version = max(1, (int) $setting->settings_version) + 1;
                $setting->save();
            }

            if ($hiddenChanged) {
                UserHiddenPlaybackVariant::query()->whereBelongsTo($user)->delete();

                if ($hiddenVariantKeys !== []) {
                    $timestamp = now();
                    UserHiddenPlaybackVariant::query()->insert(array_map(
                        static fn (string $variantKey): array => [
                            'user_id' => $user->id,
                            'variant_key' => $variantKey,
                            'created_at' => $timestamp,
                            'updated_at' => $timestamp,
                        ],
                        $hiddenVariantKeys,
                    ));
                }
            }

            $setting = $setting->refresh();
            $user->setRelation('accountSetting', $setting);
            $user->unsetRelation('hiddenPlaybackVariants');

            return $this->resolved($setting, $hiddenVariantKeys);
        }, attempts: 3);
    }

    /** @param array<string, bool|int|string|null> $attributes */
    private function mutate(User $user, array $attributes): AccountSettingsData
    {
        Gate::forUser($user)->authorize('update-account-settings');
        abort_unless($this->schema->available(), 503, __('settings.errors.unavailable'));

        return DB::transaction(function () use ($user, $attributes): AccountSettingsData {
            User::query()->lockForUpdate()->findOrFail($user->id);
            $setting = UserAccountSetting::query()->lockForUpdate()->find($user->id)
                ?? new UserAccountSetting(['user_id' => $user->id]);

            foreach ($attributes as $attribute => $value) {
                $setting->setAttribute($attribute, $value);
            }

            if (! $setting->exists || $setting->isDirty(array_keys($attributes))) {
                $setting->settings_version = max(1, (int) $setting->settings_version) + 1;
                $setting->save();
            }

            $setting = $setting->refresh();
            $user->setRelation('accountSetting', $setting);

            return $this->resolved($setting, $this->hiddenVariantKeys($user, false));
        }, attempts: 3);
    }

    /**
     * @param  list<string>  $hiddenVariantKeys
     */
    private function resolved(?UserAccountSetting $setting, array $hiddenVariantKeys = []): AccountSettingsData
    {
        $defaults = (array) config('account-settings.defaults', []);
        $defaultLocale = (string) config('account-settings.default_locale', 'ru');
        $defaultTimezone = (string) config('account-settings.default_timezone', 'UTC');
        $locale = is_string($setting?->locale) ? $setting->locale : $defaultLocale;
        $timezone = is_string($setting?->timezone) ? $setting->timezone : $defaultTimezone;

        if (! in_array($locale, (array) config('catalog-collections.supported_locales', []), true)) {
            $locale = $defaultLocale;
        }

        try {
            AccountTimezone::from($timezone);
        } catch (\InvalidArgumentException) {
            $timezone = $defaultTimezone;
        }

        $quality = is_string($setting?->preferred_quality)
            && in_array($setting->preferred_quality, (array) config('playback.supported_qualities', []), true)
                ? $setting->preferred_quality
                : null;
        $speed = is_string($setting?->playback_speed)
            && in_array($setting->playback_speed, (array) config('account-settings.playback_speeds', []), true)
                ? $setting->playback_speed
                : (string) ($defaults['playback_speed'] ?? '1.00');
        $visibility = is_string($setting?->collection_default_visibility)
            && CatalogCollectionVisibility::tryFrom($setting->collection_default_visibility) !== null
                ? $setting->collection_default_visibility
                : (string) ($defaults['collection_default_visibility'] ?? 'private');
        $preferredVariant = $this->variantKey($setting?->preferred_variant);
        $translationPreferencesAvailable = $setting instanceof UserAccountSetting
            && $this->schema->translationPreferencesAvailable();
        $fallbackVariant = $translationPreferencesAvailable
            ? $this->variantKey($setting?->fallback_variant)
            : null;
        $playbackMode = $translationPreferencesAvailable
            && is_string($setting?->preferred_playback_mode)
            ? (PlaybackPreferenceMode::tryFrom($setting->preferred_playback_mode) ?? PlaybackPreferenceMode::Automatic)
            : PlaybackPreferenceMode::Automatic;
        $subtitleLanguage = $translationPreferencesAvailable
            && is_string($setting?->preferred_subtitle_language)
            && in_array($setting->preferred_subtitle_language, (array) config('playback.supported_subtitle_languages', []), true)
                ? $setting->preferred_subtitle_language
                : null;
        $hiddenVariantKeys = collect($hiddenVariantKeys)
            ->filter(fn (mixed $key): bool => $this->variantKey($key) !== null)
            ->unique()
            ->sort()
            ->values()
            ->all();

        return new AccountSettingsData(
            locale: $locale,
            timezone: $timezone,
            autoplay: $setting->autoplay ?? (bool) ($defaults['autoplay'] ?? false),
            rememberVolume: $setting->remember_volume ?? (bool) ($defaults['remember_volume'] ?? true),
            volume: min(100, max(0, $setting->volume ?? (int) ($defaults['volume'] ?? 70))),
            muted: $setting->muted ?? (bool) ($defaults['muted'] ?? false),
            playbackSpeed: $speed,
            preferredQuality: $quality,
            preferredVariant: $preferredVariant,
            fallbackVariant: $fallbackVariant,
            playbackMode: $playbackMode,
            preferredSubtitleLanguage: $subtitleLanguage,
            hiddenVariantKeys: $hiddenVariantKeys,
            notifyPreferredTranslation: $translationPreferencesAvailable
                ? ($setting->notify_preferred_translation ?? false)
                : false,
            subtitlesEnabled: $setting->subtitles_enabled ?? (bool) ($defaults['subtitles_enabled'] ?? false),
            keyboardShortcutsEnabled: $setting->keyboard_shortcuts_enabled ?? (bool) ($defaults['keyboard_shortcuts_enabled'] ?? true),
            reducedMotion: $setting->reduced_motion ?? (bool) ($defaults['reduced_motion'] ?? false),
            collectionDefaultVisibility: $visibility,
            version: max(1, (int) ($setting->settings_version ?? 1)),
        );
    }

    private function ensureLocale(string $locale): void
    {
        if (! in_array($locale, (array) config('catalog-collections.supported_locales', []), true)) {
            throw ValidationException::withMessages(['locale' => [__('settings.validation.locale')]]);
        }
    }

    private function ensurePlayback(User $user, PlaybackSettingsData $data): void
    {
        if ($data->volume < 0 || $data->volume > 100) {
            throw ValidationException::withMessages(['volume' => [__('settings.validation.volume')]]);
        }

        if (! in_array($data->playbackSpeed, (array) config('account-settings.playback_speeds', []), true)) {
            throw ValidationException::withMessages(['playbackSpeed' => [__('settings.validation.playback_speed')]]);
        }

        abort_unless($this->schema->translationPreferencesAvailable(), 503, __('settings.errors.unavailable'));
        $current = $this->resolve($user);
        $qualityKeys = collect($this->playbackOptions->qualities($current->preferredQuality, $user))->pluck('value')->all();
        $voiceoverKeys = collect($this->playbackOptions->voiceovers([
            $current->preferredVariant,
            $current->fallbackVariant,
        ], $user))->pluck('value')->all();
        $variantKeys = collect($this->playbackOptions->variants([
            ...$current->hiddenVariantKeys,
            $current->preferredVariant,
            $current->fallbackVariant,
        ], $user))->pluck('value')->all();
        $errors = [];

        if ($data->preferredQuality !== null && ! in_array($data->preferredQuality, $qualityKeys, true)) {
            $errors['preferredQuality'] = [__('settings.validation.quality')];
        }

        if ($data->preferredVariant !== null && ! in_array($data->preferredVariant, $voiceoverKeys, true)) {
            $errors['preferredVariant'] = [__('settings.validation.variant')];
        }

        if ($data->fallbackVariant !== null && ! in_array($data->fallbackVariant, $voiceoverKeys, true)) {
            $errors['fallbackVariant'] = [__('settings.validation.variant')];
        }

        if ($data->preferredVariant !== null && $data->preferredVariant === $data->fallbackVariant) {
            $errors['fallbackVariant'] = [__('settings.validation.fallback_variant')];
        }

        if ($data->preferredSubtitleLanguage !== null
            && ! in_array($data->preferredSubtitleLanguage, (array) config('playback.supported_subtitle_languages', []), true)) {
            $errors['preferredSubtitleLanguage'] = [__('settings.validation.subtitle_language')];
        }

        if (count($data->hiddenVariantKeys) > 50
            || count(array_unique($data->hiddenVariantKeys)) !== count($data->hiddenVariantKeys)
            || collect($data->hiddenVariantKeys)->contains(fn (mixed $key): bool => ! is_string($key) || ! in_array($key, $variantKeys, true))) {
            $errors['hiddenVariantKeys'] = [__('settings.validation.hidden_variants')];
        }

        if (in_array($data->preferredVariant, $data->hiddenVariantKeys, true)
            || in_array($data->fallbackVariant, $data->hiddenVariantKeys, true)) {
            $errors['hiddenVariantKeys'] = [__('settings.validation.hidden_variant_conflict')];
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    private function variantKey(mixed $value): ?string
    {
        return is_string($value)
            && preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $value) === 1
                ? $value
                : null;
    }

    /**
     * @return list<string>
     */
    private function hiddenVariantKeys(?User $user, bool $useLoaded = true): array
    {
        if ($user === null || ! $this->schema->translationPreferencesAvailable()) {
            return [];
        }

        if ($useLoaded && $user->relationLoaded('hiddenPlaybackVariants')) {
            return $user->hiddenPlaybackVariants
                ->pluck('variant_key')
                ->filter(fn (mixed $key): bool => is_string($key))
                ->sort()
                ->values()
                ->all();
        }

        return UserHiddenPlaybackVariant::query()
            ->whereBelongsTo($user)
            ->orderBy('variant_key')
            ->pluck('variant_key')
            ->all();
    }

    private function ensureAnonymous(User $user, AnonymousAccountSettingsData $data): void
    {
        if ($data->locale !== null) {
            $this->ensureLocale($data->locale);
        }

        if ($data->timezone !== null) {
            try {
                AccountTimezone::from($data->timezone);
            } catch (\InvalidArgumentException) {
                throw ValidationException::withMessages(['timezone' => [__('settings.validation.timezone')]]);
            }
        }

        if ($data->volume !== null && ($data->volume < 0 || $data->volume > 100)) {
            throw ValidationException::withMessages(['volume' => [__('settings.validation.volume')]]);
        }

        if ($data->playbackSpeed !== null
            && ! in_array($data->playbackSpeed, (array) config('account-settings.playback_speeds', []), true)) {
            throw ValidationException::withMessages(['playbackSpeed' => [__('settings.validation.playback_speed')]]);
        }

        $qualityKeys = collect($this->playbackOptions->qualities(user: $user))->pluck('value')->all();
        $variantKeys = collect($this->playbackOptions->variants(user: $user))->pluck('value')->all();

        if ($data->preferredQuality !== null && ! in_array($data->preferredQuality, $qualityKeys, true)) {
            throw ValidationException::withMessages(['preferredQuality' => [__('settings.validation.quality')]]);
        }

        if ($data->preferredVariant !== null && ! in_array($data->preferredVariant, $variantKeys, true)) {
            throw ValidationException::withMessages(['preferredVariant' => [__('settings.validation.variant')]]);
        }
    }
}
