<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\CatalogCollection;
use App\Models\CatalogTitleReview;
use App\Models\CatalogTitleUpdateState;
use App\Models\CatalogTitleUserState;
use App\Models\Comment;
use App\Models\EpisodePlaybackMarker;
use App\Models\EpisodeViewProgress;
use App\Models\User;
use App\Models\UserAccountSetting;
use App\Models\UserProfile;
use App\Models\UserTag;
use App\Services\UserPortal\UserPortalCacheInvalidator;
use Illuminate\Database\Eloquent\Model;

final readonly class UserPortalCacheObserver
{
    public function __construct(private UserPortalCacheInvalidator $cache) {}

    public function saved(Model $model): void
    {
        if ($model->wasRecentlyCreated || $model->wasChanged()) {
            $this->invalidate($model);
        }
    }

    public function deleted(Model $model): void
    {
        $this->invalidate($model);
    }

    public function restored(Model $model): void
    {
        $this->invalidate($model);
    }

    public function forceDeleted(Model $model): void
    {
        $this->invalidate($model);
    }

    private function invalidate(Model $model): void
    {
        $ownerId = match (true) {
            $model instanceof CatalogCollection => (int) $model->owner_id,
            $model instanceof CatalogTitleReview,
            $model instanceof CatalogTitleUpdateState,
            $model instanceof CatalogTitleUserState,
            $model instanceof Comment,
            $model instanceof EpisodePlaybackMarker,
            $model instanceof EpisodeViewProgress,
            $model instanceof UserAccountSetting,
            $model instanceof UserProfile,
            $model instanceof UserTag => (int) $model->getAttribute('user_id'),
            default => 0,
        };

        if ($ownerId < 1) {
            return;
        }

        $user = User::query()->find($ownerId);

        if ($user instanceof User) {
            $this->cache->changed($user);
        }
    }
}
