<?php

declare(strict_types=1);

namespace App\Services\Seasonvar;

use App\Enums\SeasonvarImportStatus;
use App\Enums\SeasonvarImportTitleGroupStatus;
use App\Enums\SeasonvarImportTitleGroupTerminalReason;
use App\Enums\SeasonvarPreparedPageStatus;
use App\Models\SeasonvarImportPreparedPage;
use App\Models\SeasonvarImportRun;
use App\Models\SeasonvarImportTitleGroup;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

final class SeasonvarImportTitleGroupReconciler
{
    public function __construct(
        private readonly CatalogTitleRefreshStateStore $refreshStates,
    ) {}

    public function staleBefore(): Carbon
    {
        return now()->subSeconds($this->staleAfterSeconds());
    }

    public function reconcile(SeasonvarImportTitleGroup $group): void
    {
        $refreshTitleId = DB::transaction(function () use ($group): ?int {
            $lockedGroup = SeasonvarImportTitleGroup::query()
                ->lockForUpdate()
                ->find($group->id);

            if ($lockedGroup === null
                || $lockedGroup->status->isTerminal()
                || $lockedGroup->updated_at === null
                || $lockedGroup->updated_at->greaterThan($this->staleBefore())
            ) {
                return null;
            }

            $run = SeasonvarImportRun::query()
                ->lockForUpdate()
                ->find($lockedGroup->seasonvar_import_run_id);

            if ($run === null || $run->statusValue() !== SeasonvarImportStatus::Running) {
                return null;
            }

            $pages = SeasonvarImportPreparedPage::query()
                ->with('sourcePage')
                ->where('seasonvar_import_title_group_id', $lockedGroup->id)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            if ($pages->contains(fn (SeasonvarImportPreparedPage $page): bool => $this->hasLiveClaim($page))) {
                return null;
            }

            if ($lockedGroup->expected_pages < 1) {
                return $this->failGroup(
                    $lockedGroup,
                    $run,
                    SeasonvarImportTitleGroupTerminalReason::EmptyPageSet,
                );
            }

            if ($pages->count() !== $lockedGroup->expected_pages) {
                return $this->failGroup(
                    $lockedGroup,
                    $run,
                    SeasonvarImportTitleGroupTerminalReason::PageSetMismatch,
                );
            }

            $nonterminalPages = $pages->filter(
                fn (SeasonvarImportPreparedPage $page): bool => ! $page->status->isTerminal(),
            );

            if ($nonterminalPages->isEmpty()
                || $nonterminalPages->contains(
                    fn (SeasonvarImportPreparedPage $page): bool => $page->updated_at === null
                        || $page->updated_at->greaterThan($this->staleBefore()),
                )
            ) {
                return null;
            }

            $reason = SeasonvarImportTitleGroupTerminalReason::PreparationDeadlineExceeded;
            $affected = SeasonvarImportPreparedPage::query()
                ->whereKey($nonterminalPages->modelKeys())
                ->whereIn('status', [
                    SeasonvarPreparedPageStatus::Queued->value,
                    SeasonvarPreparedPageStatus::Preparing->value,
                ])
                ->update([
                    'status' => SeasonvarPreparedPageStatus::Failed->value,
                    'last_error' => $reason->message(),
                    'updated_at' => now(),
                ]);

            if ($affected === 0) {
                return null;
            }

            $failedPages = SeasonvarImportPreparedPage::query()
                ->where('seasonvar_import_title_group_id', $lockedGroup->id)
                ->where('status', SeasonvarPreparedPageStatus::Failed->value)
                ->count();
            $hasPreparedPage = SeasonvarImportPreparedPage::query()
                ->where('seasonvar_import_title_group_id', $lockedGroup->id)
                ->whereIn('status', [
                    SeasonvarPreparedPageStatus::Prepared->value,
                    SeasonvarPreparedPageStatus::Applied->value,
                ])
                ->exists();
            $groupChanges = [
                'failed_pages' => $failedPages,
                'terminal_reason_code' => $reason->value,
            ];

            if (! $hasPreparedPage) {
                $groupChanges = array_merge($groupChanges, [
                    'status' => SeasonvarImportTitleGroupStatus::Failed->value,
                    'last_error' => $reason->message(),
                    'finished_at' => now(),
                ]);
            }

            $lockedGroup->update($groupChanges);
            $this->recordRunFailure($run, $lockedGroup, $reason, $affected, ! $hasPreparedPage);

            return ! $hasPreparedPage && $this->isVisitorRun($run)
                ? $lockedGroup->catalog_title_id
                : null;
        }, 3);

        if ($refreshTitleId !== null) {
            $this->refreshStates->failed($refreshTitleId);
        }
    }

    private function failGroup(
        SeasonvarImportTitleGroup $group,
        SeasonvarImportRun $run,
        SeasonvarImportTitleGroupTerminalReason $reason,
    ): ?int {
        $group->update([
            'status' => SeasonvarImportTitleGroupStatus::Failed->value,
            'terminal_reason_code' => $reason->value,
            'last_error' => $reason->message(),
            'finished_at' => now(),
        ]);
        $this->recordRunFailure($run, $group, $reason, 1, true);

        return $this->isVisitorRun($run) ? $group->catalog_title_id : null;
    }

    private function recordRunFailure(
        SeasonvarImportRun $run,
        SeasonvarImportTitleGroup $group,
        SeasonvarImportTitleGroupTerminalReason $reason,
        int $failures,
        bool $groupFailed,
    ): void {
        $run->failed += $failures;
        $run->last_heartbeat_at = now();
        $run->summary = array_merge($run->summary ?? [], [
            'title_group_id' => $group->id,
            'title_group_terminal_reason_code' => $reason->value,
            'title_group_terminal_reason' => $reason->message(),
        ]);

        if ($groupFailed && $this->isVisitorRun($run)) {
            $run->status = SeasonvarImportStatus::Failed->value;
            $run->last_error = $reason->message();
            $run->finished_at = now();
        }

        $run->save();
    }

    private function hasLiveClaim(SeasonvarImportPreparedPage $page): bool
    {
        return $page->sourcePage->import_claim_token !== null
            && $page->sourcePage->import_claim_expires_at !== null
            && $page->sourcePage->import_claim_expires_at->greaterThan(now());
    }

    private function isVisitorRun(SeasonvarImportRun $run): bool
    {
        return is_numeric(data_get($run->summary, 'catalog_title_id'));
    }

    private function staleAfterSeconds(): int
    {
        return max(
            300,
            (int) config('seasonvar.queue.retry_window_seconds', 21_600),
            (int) config('seasonvar.queue.claim_seconds', 86_400),
        ) + 300;
    }
}
