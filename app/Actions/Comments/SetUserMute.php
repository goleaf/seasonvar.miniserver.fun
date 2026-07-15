<?php

declare(strict_types=1);

namespace App\Actions\Comments;

use App\Exceptions\Comments\CommentActionException;
use App\Models\User;
use App\Models\UserMute;
use App\Services\Comments\CommentRateLimiter;

final class SetUserMute
{
    public function __construct(private readonly CommentRateLimiter $rateLimiter) {}

    public function handle(User $actor, int $targetUserId, bool $muted): void
    {
        $target = User::query()->findOrFail($targetUserId);

        if ($actor->is($target)) {
            throw new CommentActionException('comments.errors.cannot_mute_self');
        }

        $this->rateLimiter->hit('relationship', $actor, 'mute:'.$target->id);

        if ($muted) {
            UserMute::query()->firstOrCreate([
                'muter_id' => $actor->id,
                'muted_id' => $target->id,
            ]);

            return;
        }

        UserMute::query()
            ->where('muter_id', $actor->id)
            ->where('muted_id', $target->id)
            ->delete();
    }
}
