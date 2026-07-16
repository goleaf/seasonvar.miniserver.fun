<?php

declare(strict_types=1);

namespace App\Services\Profiles;

use App\Enums\UserProfileVisibility;
use App\Models\User;
use App\Models\UserProfile;
use App\Models\UserProfileUsernameHistory;
use App\ValueObjects\ProfileUsername;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class UserProfileService
{
    public function __construct(
        private readonly UserProfileSchema $schema,
        private readonly UserProfileCacheInvalidator $cache,
    ) {}

    public function forUser(User $user): UserProfile
    {
        abort_unless($this->schema->available(), 404);
        $existing = UserProfile::query()->whereKey($user->id)->first();

        if ($existing instanceof UserProfile) {
            return $existing;
        }

        $base = ProfileUsername::generated($user->name, (string) $user->public_id);
        $candidate = $this->availableUsername($base, (string) $user->public_id);

        try {
            return UserProfile::query()->firstOrCreate(
                ['user_id' => $user->id],
                ['username' => $candidate, 'normalized_username' => $candidate],
            );
        } catch (UniqueConstraintViolationException) {
            $candidate = Str::limit($base, 24, '').'_'.substr(hash('sha256', (string) $user->public_id), 0, 7);

            return UserProfile::query()->firstOrCreate(
                ['user_id' => $user->id],
                ['username' => $candidate, 'normalized_username' => $candidate],
            );
        }
    }

    /** @param array{biography: ?string} $data */
    public function updateDetails(User $actor, UserProfile $profile, array $data): UserProfile
    {
        Gate::forUser($actor)->authorize('update', $profile);
        $biography = $this->biography($data['biography'] ?? null);

        return $this->persist($actor, $profile, ['biography' => $biography]);
    }

    public function identityChanged(UserProfile $profile): UserProfile
    {
        return $this->persist(null, $profile, []);
    }

    /** @param array<string, string> $visibility */
    public function updatePrivacy(User $actor, UserProfile $profile, array $visibility): UserProfile
    {
        Gate::forUser($actor)->authorize('update', $profile);
        $allowed = [
            'profile_visibility',
            'biography_visibility',
            'member_since_visibility',
            'collections_visibility',
            'reviews_visibility',
            'comments_visibility',
            'watching_visibility',
            'completed_visibility',
            'activity_visibility',
        ];
        $updates = [];

        foreach ($allowed as $key) {
            if (! array_key_exists($key, $visibility)) {
                continue;
            }

            $updates[$key] = UserProfileVisibility::from($visibility[$key])->value;
        }

        return $this->persist($actor, $profile, $updates);
    }

    public function changeUsername(
        User $actor,
        UserProfile $profile,
        string $username,
        string $currentPassword,
    ): UserProfile {
        Gate::forUser($actor)->authorize('update', $profile);
        $rateKey = 'profile-username-change:'.$actor->id;
        $attempts = max(1, (int) config('user-profiles.username.change_attempts', 5));
        $decay = max(60, (int) config('user-profiles.username.change_decay_seconds', 3600));

        if (RateLimiter::tooManyAttempts($rateKey, $attempts)) {
            throw ValidationException::withMessages(['username' => [__('profiles.validation.username_rate_limited')]]);
        }

        RateLimiter::hit($rateKey, $decay);

        $normalized = (string) new ProfileUsername($username);

        if ($normalized === $profile->normalized_username) {
            RateLimiter::clear($rateKey);

            return $profile;
        }

        try {
            $previousVersion = null;
            DB::transaction(function () use ($actor, $profile, $normalized, $currentPassword, &$previousVersion): void {
                $lockedActor = User::query()->lockForUpdate()->findOrFail($actor->id);

                if (! Hash::check($currentPassword, $lockedActor->getAuthPassword())) {
                    throw ValidationException::withMessages(['profile_password' => [__('profiles.validation.current_password')]]);
                }

                $locked = UserProfile::query()->lockForUpdate()->findOrFail($profile->user_id);
                Gate::forUser($lockedActor)->authorize('update', $locked);

                if (UserProfileUsernameHistory::query()->where('normalized_username', $normalized)->exists()) {
                    throw ValidationException::withMessages(['username' => [__('profiles.validation.username_unique')]]);
                }

                UserProfileUsernameHistory::query()->firstOrCreate(
                    ['normalized_username' => $locked->normalized_username],
                    ['user_id' => $locked->user_id, 'username' => $locked->username],
                );
                $previousVersion = (int) $locked->content_version;
                $locked->forceFill([
                    'username' => $normalized,
                    'normalized_username' => $normalized,
                    'content_version' => $previousVersion + 1,
                ])->save();
            }, attempts: 3);
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['username' => [__('profiles.validation.username_unique')]]);
        }

        RateLimiter::clear($rateKey);
        $profile->refresh();
        $this->cache->changed($profile, is_int($previousVersion) ? $previousVersion : (int) $profile->content_version - 1);

        return $profile;
    }

    public function export(User $user): array
    {
        $profile = UserProfile::query()->whereKey($user->id)->first();

        if (! $profile instanceof UserProfile) {
            return [];
        }

        return [
            'username' => $profile->username,
            'display_name' => $user->name,
            'biography' => $profile->biography,
            'profile_visibility' => $profile->profile_visibility->value,
            'section_visibility' => collect([
                'biography', 'member_since', 'collections', 'reviews', 'comments',
                'watching', 'completed', 'activity',
            ])->mapWithKeys(fn (string $section): array => [
                $section => $profile->getAttribute($section.'_visibility')->value,
            ])->all(),
            'avatar' => $profile->avatar_path !== null ? [
                'mime_type' => $profile->avatar_mime_type,
                'size' => $profile->avatar_size,
                'version' => $profile->avatar_version,
            ] : null,
            'cover' => $profile->cover_path !== null ? [
                'mime_type' => $profile->cover_mime_type,
                'size' => $profile->cover_size,
                'version' => $profile->cover_version,
            ] : null,
            'created_at' => $profile->created_at?->toAtomString(),
            'updated_at' => $profile->updated_at?->toAtomString(),
        ];
    }

    private function biography(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = str_replace(["\r\n", "\r"], "\n", trim($value));
        $value = preg_replace('/(?!\n|\t)[\p{Cc}\p{Cs}\x{202A}-\x{202E}\x{2066}-\x{2069}]/u', '', $value) ?? '';
        $value = strip_tags($value);
        $maximum = max(1, (int) config('user-profiles.biography_maximum_length', 1200));
        $value = Str::limit($value, $maximum, '');

        return $value !== '' ? $value : null;
    }

    private function availableUsername(string $base, string $stableIdentity): string
    {
        if (UserProfile::query()->where('normalized_username', $base)->doesntExist()
            && UserProfileUsernameHistory::query()->where('normalized_username', $base)->doesntExist()) {
            return $base;
        }

        return Str::limit($base, 24, '').'_'.substr(hash('sha256', $stableIdentity), 0, 7);
    }

    /** @param array<string, mixed> $updates */
    private function persist(?User $actor, UserProfile $profile, array $updates): UserProfile
    {
        $previousVersion = DB::transaction(function () use ($actor, $profile, $updates): int {
            $locked = UserProfile::query()->lockForUpdate()->findOrFail($profile->user_id);

            if ($actor instanceof User) {
                Gate::forUser($actor)->authorize('update', $locked);
            }

            $previousVersion = (int) $locked->content_version;
            $locked->forceFill([...$updates, 'content_version' => $previousVersion + 1])->save();

            return $previousVersion;
        }, attempts: 3);

        $profile->refresh();
        $this->cache->changed($profile, $previousVersion);

        return $profile;
    }
}
