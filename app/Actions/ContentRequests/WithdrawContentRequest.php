<?php

declare(strict_types=1);

namespace App\Actions\ContentRequests;

use App\Enums\ContentRequestStatus;
use App\Models\ContentRequest;
use App\Models\ContentRequestStatusHistory;
use App\Models\User;
use App\Services\ContentRequests\ContentRequestCacheInvalidator;
use App\Services\ContentRequests\ContentRequestNotificationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

final readonly class WithdrawContentRequest
{
    public function __construct(private ContentRequestCacheInvalidator $cache, private ContentRequestNotificationService $notifications) {}

    public function handle(User $user, int $requestId): ContentRequest
    {
        $updated = DB::transaction(function () use ($user, $requestId): ContentRequest {
            $request = ContentRequest::query()->withCount(['votes', 'followers'])->lockForUpdate()->findOrFail($requestId);
            Gate::forUser($user)->authorize('withdraw', $request);
            $communitySupported = $request->votes_count > 1 || $request->followers_count > 1;
            $from = $request->status;
            $request->votes()->where('user_id', $user->id)->delete();
            $request->followers()->where('user_id', $user->id)->delete();
            $request->requester_id = null;
            $request->withdrawn_at = now();
            $request->version++;

            if (! $communitySupported) {
                $request->status = ContentRequestStatus::Withdrawn;
                $request->active_identity_key = null;
            }

            $request->save();
            ContentRequestStatusHistory::query()->create([
                'content_request_id' => $request->id,
                'actor_id' => $user->id,
                'from_status' => $from,
                'to_status' => $request->status,
                'public_reason' => null,
                'idempotency_key' => hash('sha256', 'withdraw:'.$request->id.':'.$request->version),
            ]);

            return $request;
        }, attempts: 3);

        $this->cache->changed($updated->public_id, sitemap: true);
        $this->notifications->statusChanged($updated, $user);

        return $updated;
    }
}
