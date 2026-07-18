<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\DTOs\AnonymousAccountSettingsData;
use App\Http\Requests\MigrateAnonymousPreferencesRequest;
use App\Models\User;
use App\Services\Catalog\CatalogUserStateService;
use Illuminate\Http\Response;

final readonly class AnonymousPreferencesMigrationResponder
{
    public function __construct(
        private AccountSettingsService $settings,
        private CatalogUserStateService $userState,
    ) {}

    public function response(MigrateAnonymousPreferencesRequest $request): Response
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);
        $validated = $request->validated();
        $playbackProgress = $validated['playback_progress'] ?? [];

        abort_if($playbackProgress !== [] && ! $user->hasVerifiedEmail(), 403);

        $this->settings->migrateAnonymous($user, new AnonymousAccountSettingsData(
            locale: $validated['locale'] ?? null,
            timezone: $validated['timezone'] ?? null,
            autoplay: $validated['autoplay'] ?? null,
            rememberVolume: $validated['remember_volume'] ?? null,
            volume: $validated['volume'] ?? null,
            muted: $validated['muted'] ?? null,
            playbackSpeed: $validated['playback_speed'] ?? null,
            preferredQuality: $validated['preferred_quality'] ?? null,
            preferredVariant: $validated['preferred_variant'] ?? null,
            subtitlesEnabled: $validated['subtitles_enabled'] ?? null,
            keyboardShortcutsEnabled: $validated['keyboard_shortcuts_enabled'] ?? null,
            reducedMotion: $validated['reduced_motion'] ?? null,
        ));
        $acceptedEpisodeIds = $this->userState->migrateAnonymousProgress($user, $playbackProgress);

        return response()->noContent(headers: [
            'X-Seasonvar-Anonymous-Progress-Accepted' => implode(',', $acceptedEpisodeIds),
        ]);
    }
}
