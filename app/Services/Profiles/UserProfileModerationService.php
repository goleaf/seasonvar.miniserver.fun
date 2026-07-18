<?php

declare(strict_types=1);

namespace App\Services\Profiles;

use App\Enums\UserProfileModerationStatus;
use App\Enums\UserProfileReportStatus;
use App\Models\User;
use App\Models\UserProfile;
use App\Models\UserProfileReport;
use App\Services\Storage\PrivateUploadStorage;
use App\Support\UserPlainText;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Throwable;

final class UserProfileModerationService
{
    public function __construct(
        private readonly PrivateUploadStorage $uploads,
        private readonly UserProfileCacheInvalidator $cache,
    ) {}

    /** @return LengthAwarePaginator<int, array<string, mixed>> */
    public function queue(User $moderator): LengthAwarePaginator
    {
        Gate::forUser($moderator)->authorize('moderate', UserProfile::class);

        return UserProfileReport::query()
            ->where('status', UserProfileReportStatus::Open->value)
            ->with([
                'target' => fn ($query) => $query->select(['id', 'public_id', 'name']),
                'target.profile' => fn ($query) => $query->select([
                    'user_id', 'username', 'biography', 'moderation_status',
                    'avatar_path', 'cover_path', 'content_version',
                ]),
            ])
            ->oldest('created_at')
            ->orderBy('id')
            ->paginate(25, ['id', 'public_id', 'target_user_id', 'category', 'details', 'status', 'created_at'], 'profileReportsPage')
            ->through(function (UserProfileReport $report): array {
                $profile = $report->target?->profile;

                return [
                    'public_id' => $report->public_id,
                    'category' => $report->category->label(),
                    'details' => $report->details,
                    'created_at' => $report->created_at?->diffForHumans() ?? '',
                    'target_available' => $report->target !== null && $profile !== null,
                    'display_name' => $report->target?->name ?? __('profiles.errors.unavailable'),
                    'username' => $profile?->username,
                    'biography' => $profile?->biography,
                    'moderation_status' => $profile?->moderation_status?->value,
                    'has_avatar' => $profile?->avatar_path !== null,
                    'has_cover' => $profile?->cover_path !== null,
                ];
            });
    }

    public function apply(User $moderator, string $reportPublicId, string $action, ?string $privateNote): void
    {
        Gate::forUser($moderator)->authorize('moderate', UserProfile::class);
        abort_unless(in_array($action, [
            'activate', 'hide', 'suspend', 'hide_biography', 'remove_avatar', 'remove_cover', 'dismiss',
        ], true), 422);
        $privateNote = UserPlainText::description($privateNote);
        $privateNote = $privateNote !== null ? Str::limit($privateNote, 2000, '') : null;
        $uploadsToDelete = [];
        $profileForCache = null;
        $previousVersion = null;

        DB::transaction(function () use (
            $moderator,
            $reportPublicId,
            $action,
            $privateNote,
            &$uploadsToDelete,
            &$profileForCache,
            &$previousVersion,
        ): void {
            $report = UserProfileReport::query()
                ->where('public_id', $reportPublicId)
                ->lockForUpdate()
                ->firstOrFail();
            abort_unless($report->status === UserProfileReportStatus::Open, 409);
            $profile = $report->target_user_id !== null
                ? UserProfile::query()->lockForUpdate()->find($report->target_user_id)
                : null;

            if ($action !== 'dismiss') {
                abort_unless($profile instanceof UserProfile, 404);
                $previousVersion = (int) $profile->content_version;
                $updates = ['content_version' => $previousVersion + 1];

                if (in_array($action, ['activate', 'hide', 'suspend'], true)) {
                    $updates['moderation_status'] = match ($action) {
                        'activate' => UserProfileModerationStatus::Active->value,
                        'hide' => UserProfileModerationStatus::Hidden->value,
                        default => UserProfileModerationStatus::Suspended->value,
                    };
                } elseif ($action === 'hide_biography') {
                    $updates['biography'] = null;
                } else {
                    $kind = $action === 'remove_avatar' ? 'avatar' : 'cover';
                    $path = $profile->getAttribute($kind.'_path');
                    $disk = $profile->getAttribute($kind.'_disk');

                    if ($disk === config('uploads.disk') && is_string($path) && $path !== '') {
                        $uploadsToDelete[] = $path;
                    }

                    $updates[$kind.'_disk'] = null;
                    $updates[$kind.'_path'] = null;
                    $updates[$kind.'_mime_type'] = null;
                    $updates[$kind.'_size'] = null;
                    $updates[$kind.'_version'] = (int) $profile->getAttribute($kind.'_version') + 1;
                }

                $profile->forceFill($updates)->save();
                $profileForCache = $profile;
            }

            $report->forceFill([
                'moderator_id' => $moderator->id,
                'status' => $action === 'dismiss'
                    ? UserProfileReportStatus::Dismissed->value
                    : UserProfileReportStatus::Resolved->value,
                'private_note' => $privateNote !== '' ? $privateNote : null,
                'resolved_at' => now(),
            ])->save();
        }, attempts: 3);

        foreach ($uploadsToDelete as $path) {
            try {
                $this->uploads->delete($path);
            } catch (Throwable $exception) {
                report($exception);
            }
        }

        if ($profileForCache instanceof UserProfile && is_int($previousVersion)) {
            $this->cache->changed($profileForCache, $previousVersion);
        }
    }
}
