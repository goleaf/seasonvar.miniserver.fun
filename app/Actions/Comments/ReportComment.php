<?php

declare(strict_types=1);

namespace App\Actions\Comments;

use App\Enums\CommentReportCategory;
use App\Exceptions\Comments\CommentActionException;
use App\Models\Comment;
use App\Models\CommentReport;
use App\Models\User;
use App\Services\Comments\CommentRateLimiter;
use App\Services\Comments\CommentRelationshipService;
use App\Services\Comments\CommentTargetResolver;
use App\Support\UserPlainText;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

final class ReportComment
{
    public function __construct(
        private readonly CommentTargetResolver $targets,
        private readonly CommentRelationshipService $relationships,
        private readonly CommentRateLimiter $rateLimiter,
    ) {}

    public function handle(
        User $user,
        int $commentId,
        CommentReportCategory|string $category,
        mixed $details,
    ): CommentReport {
        $comment = Comment::query()->withTrashed()->findOrFail($commentId);
        Gate::forUser($user)->authorize('report', $comment);
        $target = $this->targets->fromComment($comment, $user);
        $this->relationships->assertCanInteract($user, $comment->user_id);
        $category = is_string($category) ? CommentReportCategory::tryFrom($category) : $category;

        if (! $category instanceof CommentReportCategory) {
            throw new CommentActionException('comments.errors.invalid_report_category');
        }

        $details = UserPlainText::description($details);
        $maximum = 2_000;

        if ($details !== null && Str::length($details) > $maximum) {
            throw new CommentActionException('comments.errors.report_details_too_long', ['maximum' => $maximum]);
        }

        $this->rateLimiter->hit('report', $user, $target->key());
        $deduplicationKey = hash('sha256', $user->id.':'.$comment->id.':'.$category->value);

        $report = CommentReport::query()->firstOrCreate([
            'deduplication_key' => $deduplicationKey,
        ], [
            'comment_id' => $comment->id,
            'reporter_id' => $user->id,
            'category' => $category,
            'details' => $details,
        ]);

        if (! $report->wasRecentlyCreated) {
            throw new CommentActionException('comments.errors.duplicate_report');
        }

        return $report;
    }
}
