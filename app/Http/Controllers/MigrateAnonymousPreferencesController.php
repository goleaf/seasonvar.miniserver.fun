<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\DTOs\AnonymousAccountSettingsData;
use App\Http\Requests\MigrateAnonymousPreferencesRequest;
use App\Models\User;
use App\Services\Auth\AccountSettingsService;
use Illuminate\Http\Response;

final class MigrateAnonymousPreferencesController extends Controller
{
    public function __invoke(
        MigrateAnonymousPreferencesRequest $request,
        AccountSettingsService $settings,
    ): Response {
        $user = $request->user();
        abort_unless($user instanceof User, 403);
        $validated = $request->validated();

        $settings->migrateAnonymous($user, new AnonymousAccountSettingsData(
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

        return response()->noContent();
    }
}
