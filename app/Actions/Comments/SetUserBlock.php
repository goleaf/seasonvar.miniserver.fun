<?php

declare(strict_types=1);

namespace App\Actions\Comments;

use App\Exceptions\Comments\CommentActionException;
use App\Models\User;
use App\Models\UserBlock;
use App\Models\UserMute;
use App\Services\Comments\CommentRateLimiter;
use Illuminate\Support\Facades\DB;

final class SetUserBlock
{
    public function __construct(private readonly CommentRateLimiter $rateLimiter) {}

    public function handle(User $actor, int $targetUserId, bool $blocked): void
    {
        $target = User::query()->findOrFail($targetUserId);

        if ($actor->is($target)) {
            throw new CommentActionException('comments.errors.cannot_block_self');
        }

        $this->rateLimiter->hit('relationship', $actor, 'block:'.$target->id);

        DB::transaction(function () use ($actor, $target, $blocked): void {
            if ($blocked) {
                UserBlock::query()->firstOrCreate([
                    'blocker_id' => $actor->id,
                    'blocked_id' => $target->id,
                ]);
                UserMute::query()
                    ->where('muter_id', $actor->id)
                    ->where('muted_id', $target->id)
                    ->delete();

                return;
            }

            UserBlock::query()
                ->where('blocker_id', $actor->id)
                ->where('blocked_id', $target->id)
                ->delete();
        }, attempts: 3);
    }
}
