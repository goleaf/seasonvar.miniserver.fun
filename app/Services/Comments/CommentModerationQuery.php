<?php

declare(strict_types=1);

namespace App\Services\Comments;

use App\DTOs\Comments\CommentModerationContextData;
use App\DTOs\Comments\CommentModerationData;
use App\DTOs\Comments\CommentModerationThreadData;
use App\DTOs\Comments\CommentReportModerationData;
use App\DTOs\Comments\CommentRestrictionData;
use App\Enums\CommentReportStatus;
use App\Enums\CommentStatus;
use App\Enums\CommentTargetType;
use App\Models\Comment;
use App\Models\CommentReport;
use App\Models\CommentRestriction;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;

final class CommentModerationQuery
{
    /** @return LengthAwarePaginator<int, CommentModerationData> */
    public function paginate(string $status, string $target, string $author): LengthAwarePaginator
    {
        $query = Comment::query()
            ->withTrashed()
            ->with([
                'author:id,name',
                'reports' => fn ($query) => $query
                    ->whereIn('status', [CommentReportStatus::Open->value, CommentReportStatus::Reviewed->value])
                    ->oldest('created_at')
                    ->orderBy('id')
                    ->limit(5)
                    ->select(['id', 'comment_id', 'category', 'details', 'status', 'created_at']),
            ])
            ->withCount([
                'replies as replies_count',
                'reports as open_reports_count' => fn (Builder $query): Builder => $query
                    ->whereIn('status', [CommentReportStatus::Open->value, CommentReportStatus::Reviewed->value]),
            ]);

        if ($status === 'attention') {
            $query->where(function (Builder $query): void {
                $query
                    ->where('status', CommentStatus::Pending->value)
                    ->orWhereHas('reports', fn (Builder $reports): Builder => $reports
                        ->whereIn('status', [CommentReportStatus::Open->value, CommentReportStatus::Reviewed->value]));
            });
        } elseif (($commentStatus = CommentStatus::tryFrom($status)) !== null) {
            $query->where('status', $commentStatus->value);
        }

        if (($targetType = CommentTargetType::tryFrom($target)) !== null) {
            $query->where('target_type', $targetType->value);
        }

        if ($author !== '') {
            $query->whereHas('author', fn (Builder $users): Builder => $users
                ->where('name', 'like', '%'.$author.'%'));
        }

        $paginator = $query
            ->orderByDesc('open_reports_count')
            ->oldest('created_at')
            ->orderBy('id')
            ->paginate(
                max(1, (int) config('comments.pagination.administration_per_page', 25)),
                [
                    'id', 'user_id', 'target_type', 'target_id', 'body', 'is_spoiler', 'status',
                    'moderation_reason', 'moderator_note', 'created_at', 'edited_at', 'deleted_at',
                ],
                'moderation_page',
            );
        $comments = collect($paginator->items());
        $restrictions = CommentRestriction::query()
            ->active()
            ->whereIn('user_id', $comments->pluck('user_id')->filter()->unique())
            ->latest('starts_at')
            ->latest('id')
            ->get()
            ->groupBy('user_id')
            ->map(fn ($items): CommentRestriction => $items->first());

        return $paginator->through(function (Comment $comment) use ($restrictions): CommentModerationData {
            $restriction = $comment->user_id !== null ? $restrictions->get($comment->user_id) : null;

            return new CommentModerationData(
                id: (int) $comment->id,
                authorId: is_int($comment->user_id) ? $comment->user_id : null,
                authorName: $comment->user_id === null
                    ? __('comments.author.unavailable')
                    : $comment->author->name,
                targetLabel: $comment->target_type->label(),
                body: (string) $comment->body,
                isSpoiler: (bool) $comment->is_spoiler,
                isDeleted: $comment->deleted_at !== null,
                statusValue: $comment->status->value,
                statusLabel: $comment->status->label(),
                moderationReasonLabel: $comment->moderation_reason?->label(),
                privateNote: $comment->moderator_note,
                replyCount: max(0, (int) $comment->getAttribute('replies_count')),
                reportCount: max(0, (int) $comment->getAttribute('open_reports_count')),
                reports: $comment->reports
                    ->map(fn (CommentReport $report): CommentReportModerationData => new CommentReportModerationData(
                        id: (int) $report->id,
                        categoryLabel: $report->category->label(),
                        details: $report->details,
                        statusLabel: $report->status->label(),
                        createdAtLabel: $report->created_at?->diffForHumans() ?? '',
                    ))
                    ->all(),
                activeRestriction: $restriction instanceof CommentRestriction
                    ? new CommentRestrictionData(
                        id: (int) $restriction->id,
                        typeLabel: $restriction->type->label(),
                        reasonLabel: $restriction->reason_code->label(),
                        expiresAtLabel: $restriction->expires_at?->translatedFormat('d.m.Y H:i'),
                    )
                    : null,
                createdAtIso: $comment->created_at?->toAtomString() ?? '',
                createdAtLabel: $comment->created_at?->diffForHumans() ?? '',
                editedAtLabel: $comment->edited_at?->diffForHumans(),
                directUrl: route('comments.show', $comment->id),
            );
        });
    }

    public function comment(int $id, User $moderator): Comment
    {
        $comment = Comment::query()->withTrashed()->findOrFail($id);
        abort_unless($moderator->can('moderate', $comment), 403);

        return $comment;
    }

    public function threadContext(int $id, User $moderator): CommentModerationThreadData
    {
        $selected = $this->comment($id, $moderator);
        $rootId = (int) ($selected->parent_id ?? $selected->id);
        $root = Comment::query()
            ->withTrashed()
            ->forTarget($selected->target_type, (int) $selected->target_id)
            ->whereNull('parent_id')
            ->with('author:id,name')
            ->findOrFail($rootId);
        $repliesQuery = Comment::query()
            ->withTrashed()
            ->forTarget($selected->target_type, (int) $selected->target_id)
            ->where('parent_id', $rootId);
        $replyCount = (clone $repliesQuery)->count();
        $replies = $repliesQuery
            ->with('author:id,name')
            ->oldest('created_at')
            ->orderBy('id')
            ->limit(max(1, (int) config('comments.pagination.replies_per_page', 20)))
            ->get([
                'id', 'user_id', 'target_type', 'target_id', 'parent_id', 'body',
                'status', 'created_at', 'deleted_at',
            ]);

        if ($selected->parent_id !== null && ! $replies->contains('id', $selected->id)) {
            $selected->loadMissing('author:id,name');
            $replies->push($selected);
            $replies = $replies
                ->sortBy([
                    ['created_at', 'asc'],
                    ['id', 'asc'],
                ])
                ->values();
        }

        $comments = collect([$root])->concat($replies);

        return new CommentModerationThreadData(
            rootId: $rootId,
            replyCount: $replyCount,
            items: $comments
                ->map(fn (Comment $comment): CommentModerationContextData => new CommentModerationContextData(
                    id: (int) $comment->id,
                    authorName: $comment->user_id === null
                        ? __('comments.author.unavailable')
                        : $comment->author->name,
                    body: (string) $comment->body,
                    statusLabel: $comment->status->label(),
                    isDeleted: $comment->deleted_at !== null,
                    isSelected: (int) $comment->id === (int) $selected->id,
                    createdAtIso: $comment->created_at?->toAtomString() ?? '',
                    createdAtLabel: $comment->created_at?->diffForHumans() ?? '',
                ))
                ->values()
                ->all(),
            hasMore: $replyCount > $replies->count(),
        );
    }
}
