<?php

declare(strict_types=1);

namespace App\Services\Comments;

use App\Models\Comment;
use App\Models\CommentReport;
use App\Models\CommentRestriction;

final class CommentModerationAudit
{
    public function comment(Comment $comment): string
    {
        return $this->fingerprint([
            'id' => $comment->id,
            'status' => $comment->status->value,
            'version' => $comment->version,
            'body_hash' => $comment->body_hash,
            'spoiler' => $comment->is_spoiler,
            'deletion_reason' => $comment->deletion_reason?->value,
            'moderation_reason' => $comment->moderation_reason?->value,
            'moderated_at' => $comment->moderated_at?->toAtomString(),
            'deleted_at' => $comment->deleted_at?->toAtomString(),
        ]);
    }

    public function report(CommentReport $report): string
    {
        return $this->fingerprint([
            'id' => $report->id,
            'comment_id' => $report->comment_id,
            'category' => $report->category->value,
            'status' => $report->status->value,
            'resolved_at' => $report->resolved_at?->toAtomString(),
        ]);
    }

    public function restriction(CommentRestriction $restriction): string
    {
        return $this->fingerprint([
            'id' => $restriction->id,
            'user_id' => $restriction->user_id,
            'type' => $restriction->type->value,
            'reason' => $restriction->reason_code->value,
            'starts_at' => $restriction->starts_at->toAtomString(),
            'expires_at' => $restriction->expires_at?->toAtomString(),
            'revoked_at' => $restriction->revoked_at?->toAtomString(),
        ]);
    }

    /** @param array<string, bool|int|string|null> $state */
    private function fingerprint(array $state): string
    {
        return hash('sha256', json_encode($state, JSON_THROW_ON_ERROR));
    }
}
