<?php

declare(strict_types=1);

namespace App\Actions\ContentRequests;

use App\Enums\ContentRequestStatus;
use App\Exceptions\ContentRequests\ContentRequestActionException;
use App\Models\ContentRequest;
use App\Models\ContentRequestClarification;
use App\Models\ContentRequestStatusHistory;
use App\Models\User;
use App\Services\ContentRequests\ContentRequestCacheInvalidator;
use App\Services\ContentRequests\ContentRequestNotificationService;
use App\Services\ContentRequests\ContentRequestRateLimiter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

final readonly class ClarifyContentRequest
{
    public function __construct(
        private ContentRequestRateLimiter $rateLimiter,
        private ContentRequestCacheInvalidator $cache,
        private ContentRequestNotificationService $notifications,
    ) {}

    public function ask(User $moderator, int $requestId, string $body, string $submissionToken): ContentRequest
    {
        return $this->write($moderator, $requestId, $body, $submissionToken, moderator: true);
    }

    public function reply(User $requester, int $requestId, string $body, string $submissionToken): ContentRequest
    {
        return $this->write($requester, $requestId, $body, $submissionToken, moderator: false);
    }

    private function write(User $actor, int $requestId, string $body, string $submissionToken, bool $moderator): ContentRequest
    {
        $body = trim(mb_substr(strip_tags($body), 0, 2_000));

        if (mb_strlen($body) < 5 || ! Str::isUuid($submissionToken)) {
            throw new ContentRequestActionException('requests.errors.invalid_clarification');
        }

        $request = ContentRequest::query()->findOrFail($requestId);
        Gate::forUser($actor)->authorize($moderator ? 'moderate' : 'clarify', $request);
        $this->rateLimiter->hit('clarify', $actor, (string) $requestId);
        $submissionKey = hash('sha256', $actor->id.':'.$requestId.':'.Str::lower($submissionToken));

        $updated = DB::transaction(function () use ($actor, $requestId, $body, $submissionKey, $moderator): ContentRequest {
            $request = ContentRequest::query()->lockForUpdate()->findOrFail($requestId);
            Gate::forUser($actor)->authorize($moderator ? 'moderate' : 'clarify', $request);
            $existing = ContentRequestClarification::query()->where('submission_key', $submissionKey)->first();

            if ($existing !== null) {
                return $request;
            }

            $from = $request->status;
            $desired = $moderator ? ContentRequestStatus::ClarificationNeeded : ContentRequestStatus::PendingReview;

            if (! in_array($desired, $from->transitions(), true)) {
                throw new ContentRequestActionException('requests.errors.invalid_transition');
            }

            ContentRequestClarification::query()->create([
                'content_request_id' => $request->id,
                'author_id' => $actor->id,
                'author_role' => $moderator ? 'moderator' : 'requester',
                'body' => $body,
                'body_hash' => hash('sha256', $body),
                'submission_key' => $submissionKey,
            ]);
            $request->status = $desired;
            $request->version++;
            $request->save();
            ContentRequestStatusHistory::query()->create([
                'content_request_id' => $request->id,
                'actor_id' => $actor->id,
                'from_status' => $from,
                'to_status' => $desired,
                'public_reason' => null,
                'idempotency_key' => hash('sha256', 'clarify:'.$submissionKey),
            ]);

            return $request;
        }, attempts: 3);

        $this->cache->changed($updated->public_id);
        $this->notifications->statusChanged($updated, $actor);

        return $updated;
    }
}
