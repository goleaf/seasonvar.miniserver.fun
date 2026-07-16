<?php

declare(strict_types=1);

namespace App\Services\Api\V1\Sync;

use App\DTOs\ApiSyncPullResult;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

final readonly class ApiSyncPullService
{
    public function __construct(
        private ApiSyncPullQuery $pullQuery,
        private ApiSyncCursorCodec $cursors,
    ) {}

    public function pull(
        string $scope,
        ?User $owner,
        ?string $encodedCursor,
        int $limit,
    ): ApiSyncPullResult {
        if ($encodedCursor === null) {
            return new ApiSyncPullResult(
                changes: new Collection,
                cursor: $this->cursors->encode($this->pullQuery->checkpoint($scope, $owner)),
                hasMore: false,
            );
        }

        $cursor = $this->cursors->decode($encodedCursor, $scope, $owner?->id);
        $result = $this->pullQuery->pull($cursor, $limit);

        return new ApiSyncPullResult(
            changes: $result['changes'],
            cursor: $this->cursors->encode($result['cursor']),
            hasMore: $result['has_more'],
        );
    }
}
