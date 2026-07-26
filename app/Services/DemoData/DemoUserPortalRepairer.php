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
        private DemoPublicTagAssignmentCleaner $publicTagAssignments,
        private DemoPublicCollectionCleaner $publicCollections,
        private UserPortalCacheInvalidator $userPortalCache,
    ) {}

    /** @return array<string, int> */
    public function inspect(): array
    {
        $options = DemoDataOptions::fromConfig();
        $users = $this->users($options);
        $userIds = $users->pluck('id');
        $publicTagState = $this->publicTagAssignments->inspect($options);
        $publicCollectionState = $this->publicCollections->inspect($options);

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
            'legacy_demo_tag_pool_size' => $publicTagState['legacy_tag_pool_size'],
            'legacy_demo_tag_expected_pairs' => $publicTagState['expected_pairs'],
            'legacy_demo_tag_matched_pairs' => $publicTagState['attached_expected_pairs'],
            'legacy_demo_tag_protected_current_pairs' => $publicTagState['protected_current_assignments'],
            'legacy_demo_owned_tags' => $publicTagState['owned_demo_tags'],
            'legacy_demo_affected_titles' => $publicTagState['affected_titles'],
            'legacy_demo_match_basis_points' => $publicTagState['match_basis_points'],
            'orphaned_demo_public_tag_assignments' => $publicTagState['cleanup_candidates'],
            'archivable_demo_public_tags' => $publicTagState['archivable_demo_tags'],
            'demo_public_collections' => $publicCollectionState['demo_public_collections'],
            'demo_unlisted_collections' => $publicCollectionState['demo_unlisted_collections'],
            'demo_oversized_collections' => $publicCollectionState['demo_oversized_collections'],
            'demo_collection_quarantine_candidates' => $publicCollectionState['demo_quarantine_candidates'],
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
            || $before['users_without_collections'] > 0;
        $needsCatalogActivity = $before['users_without_library'] > 0;
        $needsContentRequests = $before['users_without_requests'] > 0;
        $needsPublicTagCleanup = $before['orphaned_demo_public_tag_assignments'] > 0
            || $before['archivable_demo_public_tags'] > 0;
        $needsPublicCollectionCleanup = $before['demo_collection_quarantine_candidates'] > 0;
        $stageCounters = [];

        if ($needsPublicCollectionCleanup) {
            $publicCollectionCleanup = $this->publicCollections->repair($options);
            $stageCounters['demo_collections_quarantined'] = $publicCollectionCleanup[
                'demo_collections_quarantined'
            ];
        }

        if ($needsPublicTagCleanup) {
            $publicTagCleanup = $this->publicTagAssignments->repair($options);
            $stageCounters = [
                ...$stageCounters,
                'public_assignments_removed' => $publicTagCleanup['removed_assignments'],
                'public_tags_archived' => $publicTagCleanup['archived_demo_tags'],
            ];
        }

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

        if ($needsProfileImages
            || $needsOrganization
            || $needsCatalogActivity
            || $needsContentRequests
            || $needsPublicCollectionCleanup) {
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

    /**
     * @param  Collection<int, int>  $presentOwners
     * @param  Collection<int, int>  $allOwners
     */
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
}
