<?php

declare(strict_types=1);

namespace App\Actions\ContentRequests;

use App\Models\ContentRequest;
use App\Models\ContentRequestFollower;
use App\Models\ContentRequestVote;
use App\Models\User;
use App\Services\ContentRequests\ContentRequestCacheInvalidator;
use App\Services\ContentRequests\ContentRequestRateLimiter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

final readonly class SetContentRequestEngagement
{
    public function __construct(private ContentRequestRateLimiter $rateLimiter, private ContentRequestCacheInvalidator $cache) {}

    public function vote(User $user, int $requestId, bool $desired): ContentRequest
    {
        return $this->set($user, $requestId, $desired, 'vote');
    }

    public function follow(User $user, int $requestId, bool $desired): ContentRequest
    {
        return $this->set($user, $requestId, $desired, 'follow');
    }

    private function set(User $user, int $requestId, bool $desired, string $kind): ContentRequest
    {
        $request = ContentRequest::query()->findOrFail($requestId);
        Gate::forUser($user)->authorize($kind, $request);
        $this->rateLimiter->hit($kind, $user, (string) $requestId);

        DB::transaction(function () use ($user, $requestId, $desired, $kind): void {
            $locked = ContentRequest::query()->lockForUpdate()->findOrFail($requestId);
            Gate::forUser($user)->authorize($kind, $locked);
            $model = $kind === 'vote' ? ContentRequestVote::class : ContentRequestFollower::class;
            $keys = ['content_request_id' => $locked->id, 'user_id' => $user->id];
            $desired ? $model::query()->firstOrCreate($keys) : $model::query()->where($keys)->delete();
        }, attempts: 3);

        $this->cache->changed($request->public_id);

        return $request->refresh();
    }
}
