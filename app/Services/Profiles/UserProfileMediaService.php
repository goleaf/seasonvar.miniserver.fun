<?php

declare(strict_types=1);

namespace App\Services\Profiles;

use App\Models\User;
use App\Models\UserProfile;
use App\Services\Storage\PrivateUploadStorage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Throwable;

final class UserProfileMediaService
{
    public function __construct(
        private readonly PrivateUploadStorage $uploads,
        private readonly UserProfileCacheInvalidator $cache,
    ) {}

    public function replace(User $actor, UserProfile $profile, string $kind, UploadedFile $file): UserProfile
    {
        Gate::forUser($actor)->authorize('updateMedia', $profile);
        $this->validateKind($kind);
        $this->ensureRateLimit($actor, $kind);
        $profile->loadMissing('user:id,public_id');
        $stored = $this->uploads->store($file, 'user-profiles/'.$profile->user->public_id.'/'.$kind);

        try {
            [$oldDisk, $oldPath, $previousVersion] = DB::transaction(function () use ($actor, $profile, $kind, $stored): array {
                $locked = UserProfile::query()->lockForUpdate()->findOrFail($profile->user_id);
                Gate::forUser($actor)->authorize('updateMedia', $locked);
                $oldDisk = $locked->getAttribute($kind.'_disk');
                $oldPath = $locked->getAttribute($kind.'_path');
                $previousVersion = (int) $locked->content_version;
                $locked->forceFill([
                    $kind.'_disk' => $stored->disk,
                    $kind.'_path' => $stored->path,
                    $kind.'_mime_type' => $stored->mimeType,
                    $kind.'_size' => $stored->size,
                    $kind.'_version' => (int) $locked->getAttribute($kind.'_version') + 1,
                    'content_version' => $previousVersion + 1,
                ])->save();

                return [$oldDisk, $oldPath, $previousVersion];
            }, attempts: 3);
        } catch (Throwable $exception) {
            $this->deleteBestEffort($stored->path);

            throw $exception;
        }

        if ($oldDisk === config('uploads.disk') && is_string($oldPath) && $oldPath !== '') {
            $this->deleteBestEffort($oldPath);
        }

        $profile->refresh();
        $this->cache->changed($profile, $previousVersion);

        return $profile;
    }

    public function remove(User $actor, UserProfile $profile, string $kind): UserProfile
    {
        Gate::forUser($actor)->authorize('updateMedia', $profile);
        $this->validateKind($kind);
        $this->ensureRateLimit($actor, $kind);

        [$disk, $path, $previousVersion] = DB::transaction(function () use ($actor, $profile, $kind): array {
            $locked = UserProfile::query()->lockForUpdate()->findOrFail($profile->user_id);
            Gate::forUser($actor)->authorize('updateMedia', $locked);
            $disk = $locked->getAttribute($kind.'_disk');
            $path = $locked->getAttribute($kind.'_path');
            $previousVersion = (int) $locked->content_version;

            if ($disk !== null || $path !== null) {
                $locked->forceFill([
                    $kind.'_disk' => null,
                    $kind.'_path' => null,
                    $kind.'_mime_type' => null,
                    $kind.'_size' => null,
                    $kind.'_version' => (int) $locked->getAttribute($kind.'_version') + 1,
                    'content_version' => $previousVersion + 1,
                ])->save();
            }

            return [$disk, $path, $previousVersion];
        }, attempts: 3);

        if ($disk === config('uploads.disk') && is_string($path) && $path !== '') {
            $this->deleteBestEffort($path);
        }

        $profile->refresh();
        $this->cache->changed($profile, $previousVersion);

        return $profile;
    }

    public function url(UserProfile $profile, string $kind): ?string
    {
        $this->validateKind($kind);
        $profile->loadMissing('user:id,public_id');

        if ($profile->getAttribute($kind.'_path') === null || (int) $profile->getAttribute($kind.'_version') < 1) {
            return null;
        }

        return route('profiles.media', [
            'userPublicId' => $profile->user->public_id,
            'kind' => $kind,
            'version' => (int) $profile->getAttribute($kind.'_version'),
        ]);
    }

    public function purge(UserProfile $profile): void
    {
        foreach (['avatar', 'cover'] as $kind) {
            $disk = $profile->getAttribute($kind.'_disk');
            $path = $profile->getAttribute($kind.'_path');

            if ($disk === config('uploads.disk') && is_string($path) && $path !== '') {
                DB::afterCommit(fn () => $this->deleteBestEffort($path));
            }
        }

        $this->cache->deleted($profile);
    }

    private function ensureRateLimit(User $user, string $kind): void
    {
        $key = 'profile-media:'.$kind.':'.$user->id;

        abort_if(RateLimiter::tooManyAttempts($key, 10), 429, __('profiles.errors.rate_limited'));
        RateLimiter::hit($key, 3600);
    }

    private function validateKind(string $kind): void
    {
        abort_unless(in_array($kind, ['avatar', 'cover'], true), 404);
    }

    private function deleteBestEffort(string $path): void
    {
        try {
            $this->uploads->delete($path);
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
