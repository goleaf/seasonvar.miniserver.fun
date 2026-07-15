<?php

declare(strict_types=1);

namespace App\Services\Comments;

use App\DTOs\Comments\CommentViewerContext;
use App\Exceptions\Comments\CommentActionException;
use App\Models\User;
use App\Models\UserBlock;
use App\Models\UserMute;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;

final class CommentRelationshipService
{
    /** @param list<int>|null $relevantUserIds */
    public function context(?User $viewer, ?array $relevantUserIds = null): CommentViewerContext
    {
        if ($viewer === null) {
            return new CommentViewerContext(null, false, [], []);
        }

        $userId = (int) $viewer->id;
        $relevantUserIds = $relevantUserIds === null
            ? null
            : collect($relevantUserIds)
                ->filter(fn (int $id): bool => $id > 0 && $id !== $userId)
                ->unique()
                ->values()
                ->all();
        $isModerator = Gate::forUser($viewer)->allows('manage-comments');

        if ($isModerator) {
            return new CommentViewerContext($userId, true, [], []);
        }

        if ($relevantUserIds === []) {
            return new CommentViewerContext($userId, $isModerator, [], []);
        }

        $blockedUserIds = $this->blockedUserIds($viewer, $relevantUserIds);
        $mutedUserIds = UserMute::query()
            ->where('muter_id', $userId)
            ->when(
                $relevantUserIds !== null,
                fn (Builder $query): Builder => $query->whereIn('muted_id', $relevantUserIds),
            )
            ->pluck('muted_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->unique()
            ->values()
            ->all();

        return new CommentViewerContext(
            userId: $userId,
            isModerator: $isModerator,
            blockedUserIds: $blockedUserIds,
            mutedUserIds: $mutedUserIds,
        );
    }

    /**
     * @param  list<int>|null  $relevantUserIds
     * @return list<int>
     */
    public function blockedUserIds(User $viewer, ?array $relevantUserIds = null): array
    {
        if (! Schema::hasTable('user_blocks')) {
            return [];
        }

        $userId = (int) $viewer->id;

        return UserBlock::query()
            ->where(function (Builder $query) use ($userId, $relevantUserIds): void {
                $query
                    ->where(function (Builder $query) use ($userId, $relevantUserIds): void {
                        $query->where('blocker_id', $userId);

                        if ($relevantUserIds !== null) {
                            $query->whereIn('blocked_id', $relevantUserIds);
                        }
                    })
                    ->orWhere(function (Builder $query) use ($userId, $relevantUserIds): void {
                        $query->where('blocked_id', $userId);

                        if ($relevantUserIds !== null) {
                            $query->whereIn('blocker_id', $relevantUserIds);
                        }
                    });
            })
            ->get(['blocker_id', 'blocked_id'])
            ->map(fn (UserBlock $block): int => $block->blocker_id === $userId
                ? (int) $block->blocked_id
                : (int) $block->blocker_id)
            ->unique()
            ->values()
            ->all();
    }

    public function assertCanInteract(User $actor, ?int $otherUserId): void
    {
        if ($otherUserId === null || $otherUserId === (int) $actor->id) {
            return;
        }

        if ($this->isBlockedBetween((int) $actor->id, $otherUserId)) {
            throw new CommentActionException('comments.errors.interaction_blocked');
        }
    }

    public function shouldNotify(User $recipient, User $actor): bool
    {
        if ($recipient->is($actor) || $this->isBlockedBetween((int) $recipient->id, (int) $actor->id)) {
            return false;
        }

        return ! UserMute::query()
            ->where('muter_id', $recipient->id)
            ->where('muted_id', $actor->id)
            ->exists();
    }

    public function isBlockedBetween(int $firstUserId, int $secondUserId): bool
    {
        return UserBlock::query()
            ->where(function (Builder $query) use ($firstUserId, $secondUserId): void {
                $query
                    ->where('blocker_id', $firstUserId)
                    ->where('blocked_id', $secondUserId);
            })
            ->orWhere(function (Builder $query) use ($firstUserId, $secondUserId): void {
                $query
                    ->where('blocker_id', $secondUserId)
                    ->where('blocked_id', $firstUserId);
            })
            ->exists();
    }
}
