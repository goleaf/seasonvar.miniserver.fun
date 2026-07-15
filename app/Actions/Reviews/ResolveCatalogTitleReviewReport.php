<?php

declare(strict_types=1);

namespace App\Actions\Reviews;

use App\Enums\AdminAuditAction;
use App\Enums\ReviewReportStatus;
use App\Exceptions\Reviews\ReviewActionException;
use App\Models\CatalogTitleReviewReport;
use App\Models\User;
use App\Services\Admin\AdminAuditRecorder;
use App\Services\Reviews\ReviewModerationAudit;
use App\Services\Reviews\ReviewNotificationService;
use App\Services\Reviews\ReviewRateLimiter;
use App\Services\Reviews\ReviewSchema;
use App\Support\UserPlainText;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

final class ResolveCatalogTitleReviewReport
{
    public function __construct(
        private readonly ReviewSchema $schema,
        private readonly ReviewNotificationService $notifications,
        private readonly ReviewRateLimiter $rateLimiter,
        private readonly AdminAuditRecorder $auditRecorder,
        private readonly ReviewModerationAudit $audit,
    ) {}

    public function handle(
        User $moderator,
        int $reportId,
        ReviewReportStatus|string $status,
        mixed $privateNote,
    ): CatalogTitleReviewReport {
        if (! $this->schema->writable()) {
            throw new ReviewActionException('reviews.errors.action_unavailable');
        }

        $status = is_string($status) ? ReviewReportStatus::tryFrom($status) : $status;

        if (! $status instanceof ReviewReportStatus
            || ! in_array($status, [ReviewReportStatus::Resolved, ReviewReportStatus::Dismissed], true)) {
            throw new ReviewActionException('reviews.errors.invalid_report_status');
        }

        $report = CatalogTitleReviewReport::query()->with('review')->findOrFail($reportId);
        Gate::forUser($moderator)->authorize('moderate', $report->review);
        $privateNote = UserPlainText::description($privateNote);

        if ($privateNote !== null && Str::length($privateNote) > 2_000) {
            throw new ReviewActionException('reviews.errors.private_note_too_long', ['maximum' => 2_000]);
        }

        $this->assertTransitionAllowed($report, $status);
        $this->rateLimiter->hit('moderate', $moderator, 'report:'.$report->id);

        /** @var array{report: CatalogTitleReviewReport, changed: bool, final: bool} $result */
        $result = DB::transaction(function () use ($report, $moderator, $status, $privateNote): array {
            $locked = CatalogTitleReviewReport::query()->lockForUpdate()->findOrFail($report->id);
            $locked->loadMissing('review');
            Gate::forUser($moderator)->authorize('moderate', $locked->review);

            if ($locked->status === $status && $locked->private_note === $privateNote) {
                return [
                    'report' => $locked,
                    'changed' => false,
                    'final' => $this->isFinal($status),
                ];
            }

            $this->assertTransitionAllowed($locked, $status);
            $before = $this->audit->report($locked);
            $isFinal = $this->isFinal($status);
            $statusChanged = $locked->status !== $status;
            $noteChanged = $locked->private_note !== $privateNote;
            $locked->forceFill([
                'moderator_id' => $moderator->id,
                'status' => $status,
                'private_note' => $privateNote,
                'deduplication_key' => $isFinal ? null : $locked->deduplication_key,
                'resolved_at' => $isFinal ? ($locked->resolved_at ?? now()) : null,
            ])->save();
            $this->auditRecorder->record(
                $moderator,
                AdminAuditAction::ReviewReportResolved,
                $locked,
                $before,
                $this->audit->report($locked),
                [
                    ...($statusChanged ? ['report_status'] : []),
                    ...($noteChanged ? ['moderator_note'] : []),
                ],
            );

            return [
                'report' => $locked,
                'changed' => true,
                'final' => $isFinal,
            ];
        }, attempts: 3);
        $report = $result['report'];

        if (! $result['changed']) {
            return $report;
        }

        if ($result['final']) {
            $this->notifications->reportResolved($report, $moderator);
        }

        return $report;
    }

    private function assertTransitionAllowed(
        CatalogTitleReviewReport $report,
        ReviewReportStatus $status,
    ): void {
        if ($this->isFinal($report->status) && $report->status !== $status) {
            throw new ReviewActionException('reviews.errors.invalid_report_status');
        }
    }

    private function isFinal(ReviewReportStatus $status): bool
    {
        return in_array($status, [ReviewReportStatus::Resolved, ReviewReportStatus::Dismissed], true);
    }
}
