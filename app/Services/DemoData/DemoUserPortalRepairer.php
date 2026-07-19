<?php

declare(strict_types=1);

namespace App\Services\DemoData;

use App\DTOs\DemoData\DemoDataOptions;
use App\Models\CatalogCollection;
use App\Models\CatalogTitleUserState;
use App\Models\ContentRequest;
use App\Models\User;
use App\Models\UserProfile;
use App\Models\UserTag;
use App\Services\DemoData\Stages\DemoCatalogActivityStage;
use App\Services\DemoData\Stages\DemoContentRequestStage;
use App\Services\DemoData\Stages\DemoOrganizationStage;
use App\Services\UserPortal\UserPortalCacheInvalidator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use LogicException;

final readonly class DemoUserPortalRepairer
{
    public function __construct(
        private DemoStableValue $stable,
        private DemoOrganizationStage $organization,
        private DemoCatalogActivityStage $catalogActivity,
        private DemoContentRequestStage $contentRequests,
        private UserPortalCacheInvalidator $userPortalCache,
    ) {}

    /** @return array<string, int> */
    public function inspect(): array
    {
        $options = DemoDataOptions::fromConfig();
        $users = $this->users($options);
        $userIds = $users->pluck('id');

        return [
            'users' => $users->count(),
            'users_without_library' => $this->missingOwners(
                CatalogTitleUserState::query()->whereIn('user_id', $userIds)->distinct()->pluck('user_id'),
                $userIds,
            ),
            'users_without_personal_tags' => $this->missingOwners(
                UserTag::query()->whereIn('user_id', $userIds)->distinct()->pluck('user_id'),
                $userIds,
            ),
            'users_without_collections' => $this->missingOwners(
                CatalogCollection::query()->whereIn('owner_id', $userIds)->distinct()->pluck('owner_id'),
                $userIds,
            ),
            'users_without_requests' => $this->missingOwners(
                ContentRequest::query()->whereIn('requester_id', $userIds)->distinct()->pluck('requester_id'),
                $userIds,
            ),
            'invalid_profile_images' => $users->filter(fn (User $user): bool => ! $this->validProfileImages($user))->count(),
            'invalid_collection_images' => CatalogCollection::query()
                ->whereIn('owner_id', $userIds)
                ->get(['public_id', 'cover_disk', 'cover_path', 'cover_mime_type'])
                ->filter(fn (CatalogCollection $collection): bool => ! $this->validCollectionImage($collection))
                ->count(),
        ];
    }

    /** @return array{before: array<string, int>, after: array<string, int>, stage_counters: array<string, int>} */
    public function repair(): array
    {
        $options = DemoDataOptions::fromConfig();
        $users = $this->users($options);
        $before = $this->inspect();
        $needsProfileImages = $before['invalid_profile_images'] > 0;
        $needsOrganization = $before['users_without_personal_tags'] > 0
            || $before['users_without_collections'] > 0
            || $before['invalid_collection_images'] > 0;
        $needsCatalogActivity = $before['users_without_library'] > 0;
        $needsContentRequests = $before['users_without_requests'] > 0;
        $stageCounters = [];

        if ($needsProfileImages) {
            $this->repairProfileImages($users, $options);
        }

        if ($needsOrganization) {
            $stageCounters = [
                ...$stageCounters,
                ...$this->organization->repairKnownDemoUsers($options)->counters,
            ];
        }

        if ($needsCatalogActivity) {
            $stageCounters = [
                ...$stageCounters,
                ...$this->catalogActivity->repairKnownDemoUsers($options)->counters,
            ];
        }

        if ($needsContentRequests) {
            $stageCounters = [
                ...$stageCounters,
                ...$this->contentRequests->repairKnownDemoUsers($options)->counters,
            ];
        }

        if ($needsProfileImages || $needsOrganization || $needsCatalogActivity || $needsContentRequests) {
            foreach ($users as $user) {
                $this->userPortalCache->changed($user);
            }
        }

        return [
            'before' => $before,
            'after' => $this->inspect(),
            'stage_counters' => $stageCounters,
        ];
    }

    /** @return Collection<int, User> */
    private function users(DemoDataOptions $options): Collection
    {
        $emails = collect(range(1, $options->userCount))
            ->mapWithKeys(fn (int $index): array => ["user{$index}@example.com" => $index]);
        $users = User::query()
            ->with('profile:user_id,avatar_disk,avatar_path,avatar_mime_type,cover_disk,cover_path,cover_mime_type')
            ->whereIn('email', $emails->keys())
            ->get()
            ->keyBy('email');

        if ($users->count() !== $options->userCount) {
            throw new LogicException('Набор известных демонстрационных пользователей неполон; repair остановлен до записи.');
        }

        return $emails->map(function (int $index, string $email) use ($users): User {
            $user = $users->get($email);

            if (! $user instanceof User || ! $user->profile instanceof UserProfile) {
                throw new LogicException("У демонстрационного пользователя {$index} отсутствует профиль; repair остановлен до записи.");
            }

            return $user;
        })->values();
    }

    /** @param Collection<int, int> $presentOwners @param Collection<int, int> $allOwners */
    private function missingOwners(Collection $presentOwners, Collection $allOwners): int
    {
        return $allOwners->map(fn (mixed $id): int => (int) $id)
            ->diff($presentOwners->map(fn (mixed $id): int => (int) $id))
            ->count();
    }

    /** @param Collection<int, User> $users */
    private function repairProfileImages(Collection $users, DemoDataOptions $options): void
    {
        if ($options->assetDisk !== (string) config('uploads.disk')) {
            throw new LogicException('Demo asset disk не совпадает с private upload disk; repair остановлен.');
        }

        $assets = new DemoRasterAsset($options, $this->stable);

        foreach ($users as $user) {
            if ($this->validProfileImages($user)) {
                continue;
            }

            $publicId = (string) $user->public_id;
            $avatar = $assets->store('avatars', $publicId, 320, 320, "user-profiles/{$publicId}/avatar/demo", 'webp');
            $cover = $assets->store('profile-covers', $publicId, 1_280, 360, "user-profiles/{$publicId}/cover/demo", 'webp');

            DB::transaction(function () use ($avatar, $cover, $user): void {
                $profile = UserProfile::query()->where('user_id', $user->id)->lockForUpdate()->firstOrFail();
                $profile->forceFill([
                    'avatar_disk' => $avatar['disk'],
                    'avatar_path' => $avatar['path'],
                    'avatar_mime_type' => $avatar['mime_type'],
                    'avatar_size' => $avatar['size'],
                    'avatar_version' => max(1, (int) $profile->avatar_version + 1),
                    'cover_disk' => $cover['disk'],
                    'cover_path' => $cover['path'],
                    'cover_mime_type' => $cover['mime_type'],
                    'cover_size' => $cover['size'],
                    'cover_version' => max(1, (int) $profile->cover_version + 1),
                    'content_version' => (int) $profile->content_version + 1,
                ])->save();
            }, attempts: 3);
        }
    }

    private function validProfileImages(User $user): bool
    {
        $profile = $user->profile;

        if (! $profile instanceof UserProfile) {
            return false;
        }

        $prefix = 'user-profiles/'.$user->public_id.'/';

        return $profile->avatar_disk === config('uploads.disk')
            && $profile->cover_disk === config('uploads.disk')
            && $profile->avatar_mime_type === 'image/webp'
            && $profile->cover_mime_type === 'image/webp'
            && str_starts_with((string) $profile->avatar_path, $prefix.'avatar/')
            && str_starts_with((string) $profile->cover_path, $prefix.'cover/')
            && Storage::disk((string) $profile->avatar_disk)->exists((string) $profile->avatar_path)
            && Storage::disk((string) $profile->cover_disk)->exists((string) $profile->cover_path);
    }

    private function validCollectionImage(CatalogCollection $collection): bool
    {
        return $collection->cover_disk === config('uploads.disk')
            && $collection->cover_mime_type === 'image/webp'
            && str_starts_with((string) $collection->cover_path, 'catalog-collections/'.$collection->public_id.'/')
            && Storage::disk((string) $collection->cover_disk)->exists((string) $collection->cover_path);
    }
}
