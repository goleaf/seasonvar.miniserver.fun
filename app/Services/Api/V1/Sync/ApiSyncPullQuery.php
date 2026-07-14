<?php

declare(strict_types=1);

namespace App\Services\Api\V1\Sync;

use App\DTOs\ApiSyncCursor;
use App\Exceptions\ApiSyncCursorException;
use App\Models\ApiSyncChange;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use InvalidArgumentException;

final class ApiSyncPullQuery
{
    public function checkpoint(string $scope, ?User $owner): ApiSyncCursor
    {
        $ownerId = $this->ownerId($scope, $owner?->id);
        $cursor = new ApiSyncCursor($scope, $ownerId, 0);

        return new ApiSyncCursor(
            scope: $scope,
            ownerId: $ownerId,
            changeId: (int) ($this->scopeQuery($cursor)->max('id') ?? 0),
        );
    }

    /**
     * @return array{changes: Collection<int, ApiSyncChange>, cursor: ApiSyncCursor, has_more: bool}
     *
     * @throws ApiSyncCursorException
     */
    public function pull(ApiSyncCursor $cursor, int $limit): array
    {
        $this->ownerId($cursor->scope, $cursor->ownerId);
        $limit = max(1, min(200, $limit));
        $scopeQuery = $this->scopeQuery($cursor);
        $oldestRetainedId = (clone $scopeQuery)->min('id');

        if ($cursor->changeId > 0
            && $oldestRetainedId !== null
            && $cursor->changeId < (int) $oldestRetainedId) {
            throw new ApiSyncCursorException(ApiSyncCursorException::EXPIRED);
        }

        $changes = $scopeQuery
            ->select(['id', 'resource_type', 'resource_key', 'operation', 'changed_at'])
            ->where('id', '>', $cursor->changeId)
            ->orderBy('id')
            ->limit($limit + 1)
            ->get();
        $hasMore = $changes->count() > $limit;

        if ($hasMore) {
            $changes = $changes->take($limit)->values();
        }

        $changeId = (int) ($changes->last()?->getKey() ?? $cursor->changeId);

        return [
            'changes' => $changes,
            'cursor' => new ApiSyncCursor($cursor->scope, $cursor->ownerId, $changeId),
            'has_more' => $hasMore,
        ];
    }

    /** @return Builder<ApiSyncChange> */
    private function scopeQuery(ApiSyncCursor $cursor): Builder
    {
        $query = ApiSyncChange::query()->where('scope', $cursor->scope);

        if ($cursor->scope === ApiSyncChange::SCOPE_CATALOG) {
            return $query->whereNull('user_id');
        }

        return $query->where('user_id', $cursor->ownerId);
    }

    private function ownerId(string $scope, ?int $ownerId): ?int
    {
        if ($scope === ApiSyncChange::SCOPE_CATALOG && $ownerId === null) {
            return null;
        }

        if ($scope === ApiSyncChange::SCOPE_USER && $ownerId !== null && $ownerId > 0) {
            return $ownerId;
        }

        throw new InvalidArgumentException('Invalid API sync scope or owner.');
    }
}
