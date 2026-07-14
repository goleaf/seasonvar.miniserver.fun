<?php

declare(strict_types=1);

namespace App\Services\Catalog;

use App\Models\EpisodeViewProgress;
use App\Models\User;
use App\Services\Api\V1\Sync\UserSyncChangePublisher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

final class CatalogViewingActivityService
{
    public function __construct(private readonly UserSyncChangePublisher $syncChanges) {}

    public function remove(User $user, int $progressId): void
    {
        $this->removeProgress($user, $progressId, false);
    }

    public function removeOwned(User $user, int $progressId): void
    {
        $this->removeProgress($user, $progressId, true);
    }

    private function removeProgress(User $user, int $progressId, bool $ownerScoped): void
    {
        DB::transaction(function () use ($user, $progressId, $ownerScoped): void {
            $progress = EpisodeViewProgress::query()
                ->when($ownerScoped, fn ($query) => $query->whereBelongsTo($user))
                ->whereKey($progressId)
                ->lockForUpdate()
                ->firstOrFail();

            Gate::forUser($user)->authorize('delete', $progress);
            $progress->delete();
            $this->syncChanges->publishHistoryDelete($user, $progressId);
        }, attempts: 3);
    }

    public function clear(User $user): void
    {
        Gate::forUser($user)->authorize('deleteAny', EpisodeViewProgress::class);

        DB::transaction(function () use ($user): void {
            $deleted = EpisodeViewProgress::query()
                ->whereBelongsTo($user)
                ->whereNotNull('first_started_at')
                ->delete();

            if ($deleted > 0) {
                $this->syncChanges->publishHistoryClear($user);
            }
        }, attempts: 3);
    }
}
