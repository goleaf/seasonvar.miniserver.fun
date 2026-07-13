<?php

declare(strict_types=1);

namespace App\Services\Catalog;

use App\Models\EpisodeViewProgress;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

final class CatalogViewingActivityService
{
    public function remove(User $user, int $progressId): void
    {
        $progress = EpisodeViewProgress::query()->findOrFail($progressId);

        Gate::forUser($user)->authorize('delete', $progress);
        $progress->delete();
    }

    public function clear(User $user): void
    {
        Gate::forUser($user)->authorize('deleteAny', EpisodeViewProgress::class);

        EpisodeViewProgress::query()
            ->whereBelongsTo($user)
            ->whereNotNull('first_started_at')
            ->delete();
    }
}
