<?php

declare(strict_types=1);

namespace App\Actions\Comments;

use App\Enums\AdminAuditAction;
use App\Enums\CommentReportStatus;
use App\Exceptions\Comments\CommentActionException;
use App\Models\CommentReport;
use App\Models\User;
use App\Services\Admin\AdminAuditRecorder;
use App\Services\Comments\CommentModerationAudit;
use App\Services\Comments\CommentNotificationService;
use App\Support\UserPlainText;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

final class ResolveCommentReport
{
    public function __construct(
        private readonly CommentNotificationService $notifications,
        private readonly AdminAuditRecorder $auditRecorder,
        private readonly CommentModerationAudit $audit,
    ) {}

    public function handle(
        User $moderator,
        int $reportId,
        CommentReportStatus|string $status,
        mixed $privateNote = null,
    ): CommentReport {
        Gate::forUser($moderator)->authorize('manage-comments');
        $report = CommentReport::query()->findOrFail($reportId);
        $status = is_string($status) ? CommentReportStatus::tryFrom($status) : $status;

        if (! $status instanceof CommentReportStatus
            || ! in_array($status, [CommentReportStatus::Resolved, CommentReportStatus::Dismissed], true)) {
            throw new CommentActionException('comments.errors.invalid_report_status');
        }

        $privateNote = UserPlainText::description($privateNote);

        if ($privateNote !== null && Str::length($privateNote) > 2_000) {
            throw new CommentActionException('comments.errors.moderator_note_too_long', ['maximum' => 2_000]);
        }

        [$report, $beforeVersion, $changed] = DB::transaction(function () use (
            $report,
            $moderator,
            $status,
            $privateNote,
        ): array {
            $locked = CommentReport::query()->lockForUpdate()->findOrFail($report->id);

            if ($locked->status === $status
                && $locked->private_note === $privateNote
                && $locked->resolved_at !== null) {
                return [$locked, $this->audit->report($locked), false];
            }

            if (! $locked->status->isOpen()) {
                throw new CommentActionException('comments.errors.invalid_report_status');
            }

            $beforeVersion = $this->audit->report($locked);
            $locked->forceFill([
                'status' => $status,
                'moderator_id' => $moderator->id,
                'private_note' => $privateNote,
                'deduplication_key' => null,
                'resolved_at' => now(),
            ])->save();

            return [$locked, $beforeVersion, true];
        }, attempts: 3);

        if ($changed) {
            $this->auditRecorder->record(
                $moderator,
                AdminAuditAction::CommentReportResolved,
                $report,
                $beforeVersion,
                $this->audit->report($report),
                ['report_status', 'moderator_note'],
            );
            $this->notifications->reportResolved($report);
        }

        return $report;
    }
}
