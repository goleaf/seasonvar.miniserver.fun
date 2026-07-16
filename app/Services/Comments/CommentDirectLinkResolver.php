<?php

declare(strict_types=1);

namespace App\Services\Comments;

use App\Enums\CommentStatus;
use App\Models\Comment;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Gate;

final readonly class CommentDirectLinkResolver
{
    public function __construct(
        private CommentTargetResolver $targets,
        private CommentDiscussionQuery $discussion,
    ) {}

    public function resolve(int $commentId, ?User $viewer, ?string $interfaceLocale = null): string
    {
        if ($commentId < 1) {
            throw $this->notFound($commentId);
        }

        $comment = Comment::query()->withTrashed()->findOrFail($commentId);

        if (! Gate::forUser($viewer)->allows('view', $comment)) {
            throw $this->notFound($commentId);
        }

        $isModerator = $viewer !== null && Gate::forUser($viewer)->allows('manage-comments');

        if ($isModerator
            && ($comment->status !== CommentStatus::Published || $comment->deleted_at !== null)) {
            return $this->moderationUrl($comment);
        }

        try {
            $target = $this->targets->fromComment($comment, $viewer, $interfaceLocale);
        } catch (ModelNotFoundException $exception) {
            if (! $isModerator) {
                throw $exception;
            }

            return $this->moderationUrl($comment);
        }

        $root = $this->discussion->rootFor($comment);
        $page = $this->discussion->oldestPageFor($target, $root, $viewer);
        $canonical = explode('#', $target->canonicalUrl, 2)[0];
        $separator = str_contains($canonical, '?') ? '&' : '?';
        $query = http_build_query(array_filter([
            'discussion_scope' => $target->type->value.':'.$target->id,
            'discussion_sort' => 'oldest',
            'comments_page' => $page > 1 ? $page : null,
            'thread' => $comment->parent_id !== null ? (int) $root->id : null,
            'comment' => (int) $comment->id,
        ], static fn (mixed $value): bool => $value !== null));

        return $canonical.$separator.$query.'#comment-'.$comment->id;
    }

    private function moderationUrl(Comment $comment): string
    {
        return route('admin.comments', ['comment' => $comment->id]);
    }

    private function notFound(int $commentId): ModelNotFoundException
    {
        return (new ModelNotFoundException)->setModel(Comment::class, [$commentId]);
    }
}
