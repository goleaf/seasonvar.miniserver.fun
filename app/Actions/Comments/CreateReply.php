<?php

declare(strict_types=1);

namespace App\Actions\Comments;

use App\Enums\CommentTargetType;
use App\Models\Comment;
use App\Models\User;

final class CreateReply
{
    public function __construct(private readonly CreateComment $comments) {}

    public function handle(
        User $user,
        CommentTargetType|string $targetType,
        int $targetId,
        int $replyToId,
        mixed $body,
        bool $isSpoiler,
        string $submissionToken,
    ): Comment {
        return $this->comments->handle(
            $user,
            $targetType,
            $targetId,
            $body,
            $isSpoiler,
            $submissionToken,
            $replyToId,
        );
    }
}
