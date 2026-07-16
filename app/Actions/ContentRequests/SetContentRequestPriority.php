<?php

declare(strict_types=1);

namespace App\Actions\ContentRequests;

use App\Enums\ContentRequestPriority;
use App\Exceptions\ContentRequests\ContentRequestActionException;
use App\Models\ContentRequest;
use App\Models\User;
use App\Services\ContentRequests\ContentRequestCacheInvalidator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

final readonly class SetContentRequestPriority
{
    public function __construct(private ContentRequestCacheInvalidator $cache) {}

    public function handle(User $moderator, int $requestId, ContentRequestPriority|string $priority, int $expectedVersion): ContentRequest
    {
        $priority = is_string($priority) ? ContentRequestPriority::tryFrom($priority) : $priority;

        if ($priority === null) {
            throw new ContentRequestActionException('requests.errors.invalid_priority');
        }

        $updated = DB::transaction(function () use ($moderator, $requestId, $priority, $expectedVersion): ContentRequest {
            $request = ContentRequest::query()->lockForUpdate()->findOrFail($requestId);
            Gate::forUser($moderator)->authorize('moderate', $request);

            if ($request->version !== $expectedVersion) {
                throw new ContentRequestActionException('requests.errors.stale_request');
            }

            $request->priority = $priority;
            $request->version++;
            $request->save();

            return $request;
        }, attempts: 3);

        $this->cache->changed($updated->public_id);

        return $updated;
    }
}
