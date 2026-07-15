<?php

declare(strict_types=1);

namespace App\Actions\Comments;

use App\Enums\AdminAuditAction;
use App\Enums\CommentDeletionReason;
use App\Enums\CommentModerationReason;
use App\Enums\CommentReportStatus;
use App\Enums\CommentStatus;
use App\Exceptions\Comments\CommentActionException;
use App\Models\Comment;
use App\Models\CommentReport;
use App\Models\User;
use App\Services\Admin\AdminAuditRecorder;
use App\Services\Comments\CommentCacheInvalidator;
use App\Services\Comments\CommentModerationAudit;
use App\Services\Comments\CommentNotificationService;
use App\Support\UserPlainText;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

final class ModerateComment
{
    public function __construct(
        private readonly CommentCacheInvalidator $cache,
        private readonly CommentNotificationService $notifications,
        private readonly AdminAuditRecorder $auditRecorder,
        private readonly CommentModerationAudit $audit,
    ) {}

    public function handle(
        User $moderator,
        int $commentId,
        CommentStatus|string $status,
        CommentModerationReason|string $reason,
        mixed $privateNote = null,
        bool $resolveOpenReports = true,
    ): Comment {
        $comment = Comment::query()->withTrashed()->findOrFail($commentId);
        Gate::forUser($moderator)->authorize('moderate', $comment);
        $status = is_string($status) ? CommentStatus::tryFrom($status) : $status;
        $reason = is_string($reason) ? CommentModerationReason::tryFrom($reason) : $reason;

        if (! $status instanceof CommentStatus || ! $reason instanceof CommentModerationReason) {
            throw new CommentActionException('comments.errors.invalid_moderation');
        }

        $privateNote = UserPlainText::description($privateNote);

        if ($privateNote !== null && Str::length($privateNote) > 2_000) {
            throw new CommentActionException('comments.errors.moderator_note_too_long', ['maximum' => 2_000]);
        }

        [$comment, $resolvedReports, $visibilityChanged] = DB::transaction(function () use (
            $comment,
            $moderator,
            $status,
            $reason,
            $privateNote,
            $resolveOpenReports,
        ): array {
            $locked = Comment::query()
                ->withTrashed()
                ->lockForUpdate()
                ->findOrFail($comment->id);
            Gate::forUser($moderator)->authorize('moderate', $locked);

            if ($locked->deletion_reason === CommentDeletionReason::Privacy
                && $status !== CommentStatus::Removed) {
                throw new CommentActionException('comments.errors.invalid_moderation');
            }

            if ($status === CommentStatus::Published && $locked->parent_id !== null) {
                $root = Comment::query()
                    ->withTrashed()
                    ->lockForUpdate()
                    ->find($locked->parent_id);

                if ($root === null
                    || $root->parent_id !== null
                    || $root->target_type !== $locked->target_type
                    || (int) $root->target_id !== (int) $locked->target_id
                    || $root->status !== CommentStatus::Published
                    || ($root->deleted_at !== null && $root->deletion_reason !== CommentDeletionReason::Author)) {
                    throw new CommentActionException('comments.errors.invalid_moderation');
                }
            }

            $beforeVersion = $this->audit->comment($locked);
            $protectedDeletion = $locked->deleted_at !== null
                && in_array(
                    $locked->deletion_reason,
                    [CommentDeletionReason::Author, CommentDeletionReason::Privacy],
                    true,
                );
            $deletionStateChanges = $status === CommentStatus::Removed
                ? $locked->deleted_at === null
                : $locked->deleted_at !== null
                    && $locked->deletion_reason === CommentDeletionReason::Moderator;
            $statusChanged = $locked->status !== $status;
            $visibilityChanged = $statusChanged || $deletionStateChanges;
            $commentChanged = $statusChanged
                || $locked->moderation_reason !== $reason
                || $locked->moderator_note !== $privateNote
                || $deletionStateChanges;

            if ($commentChanged) {
                $locked->forceFill([
                    'status' => $status,
                    'moderated_by_id' => $moderator->id,
                    'moderation_reason' => $reason,
                    'moderator_note' => $privateNote,
                    'moderated_at' => now(),
                    'version' => (int) $locked->version + 1,
                ]);

                if ($status === CommentStatus::Removed) {
                    if (! $protectedDeletion) {
                        $locked->deletion_reason = CommentDeletionReason::Moderator;
                        $locked->deleted_by_id = $moderator->id;
                    }

                    $locked->save();

                    if ($locked->deleted_at === null) {
                        $locked->delete();
                    }
                } else {
                    if (! $protectedDeletion
                        && $locked->deleted_at !== null
                        && $locked->deletion_reason === CommentDeletionReason::Moderator) {
                        $locked->restore();
                        $locked->deletion_reason = null;
                        $locked->deleted_by_id = null;
                    }

                    $locked->save();
                }
            }

            $reports = collect();

            if ($resolveOpenReports) {
                $reports = CommentReport::query()
                    ->where('comment_id', $locked->id)
                    ->whereIn('status', [CommentReportStatus::Open->value, CommentReportStatus::Reviewed->value])
                    ->lockForUpdate()
                    ->get();

                if ($reports->isNotEmpty()) {
                    $resolvedAt = now();

                    foreach ($reports as $report) {
                        $beforeReportVersion = $this->audit->report($report);
                        $report->forceFill([
                            'status' => CommentReportStatus::Resolved,
                            'moderator_id' => $moderator->id,
                            'private_note' => $privateNote,
                            'deduplication_key' => null,
                            'resolved_at' => $resolvedAt,
                        ]);
                        $this->auditRecorder->record(
                            $moderator,
                            AdminAuditAction::CommentReportResolved,
                            $report,
                            $beforeReportVersion,
                            $this->audit->report($report),
                            ['report_status', 'moderator_note'],
                        );
                    }

                    $reports->toQuery()->update([
                        'status' => CommentReportStatus::Resolved->value,
                        'moderator_id' => $moderator->id,
                        'private_note' => $privateNote,
                        'deduplication_key' => null,
                        'resolved_at' => $resolvedAt,
                        'updated_at' => $resolvedAt,
                    ]);
                }
            }

            if ($commentChanged) {
                $this->auditRecorder->record(
                    $moderator,
                    AdminAuditAction::CommentModerated,
                    $locked,
                    $beforeVersion,
                    $this->audit->comment($locked),
                    ['comment_status', 'moderation_reason', 'moderator_note', 'deleted_at'],
                );
            }

            return [$locked, $reports, $visibilityChanged];
        }, attempts: 3);

        $comment->refresh();

        if ($visibilityChanged) {
            $this->cache->commentChanged($comment);
            $this->notifications->moderationChanged($comment);

            if ($comment->status === CommentStatus::Published && $comment->isReply()) {
                $comment->loadMissing('author:id,name');
                $author = $comment->author;

                if ($author instanceof User) {
                    $this->notifications->replyCreated($comment, $author);
                }
            }
        }

        foreach ($resolvedReports as $report) {
            $report->refresh();
            $this->notifications->reportResolved($report);
        }

        return $comment;
    }
}
