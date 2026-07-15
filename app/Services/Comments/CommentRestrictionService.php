<?php

declare(strict_types=1);

namespace App\Services\Comments;

use App\Exceptions\Comments\CommentActionException;
use App\Models\CommentRestriction;
use App\Models\User;

final class CommentRestrictionService
{
    public function activeFor(User $user): ?CommentRestriction
    {
        return CommentRestriction::query()
            ->active()
            ->where('user_id', $user->id)
            ->latest('starts_at')
            ->latest('id')
            ->first();
    }

    public function assertCanComment(User $user): void
    {
        $restriction = $this->activeFor($user);

        if ($restriction === null) {
            return;
        }

        if ($restriction->expires_at === null) {
            throw new CommentActionException('comments.errors.permanently_restricted', [
                'reason' => $restriction->reason_code->label(),
            ]);
        }

        throw new CommentActionException('comments.errors.temporarily_restricted', [
            'reason' => $restriction->reason_code->label(),
            'expires' => $restriction->expires_at->translatedFormat('d.m.Y H:i'),
        ]);
    }
}
