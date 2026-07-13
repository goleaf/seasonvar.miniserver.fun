<?php

declare(strict_types=1);

namespace App\Jobs;

use App\DTOs\Seasonvar\SeasonvarPreparedCatalogPage;
use App\Enums\SeasonvarImportStatus;
use App\Enums\SeasonvarImportTitleGroupStatus;
use App\Enums\SeasonvarPreparedPageStatus;
use App\Models\CatalogTitle;
use App\Models\SeasonvarImportPreparedPage;
use App\Models\SeasonvarImportRun;
use App\Models\SeasonvarImportTitleGroup;
use App\Services\Catalog\CatalogCacheInvalidator;
use App\Services\Seasonvar\CatalogTitleRefreshStateStore;
use App\Services\Seasonvar\SeasonvarCatalogImporter;
use App\Services\Seasonvar\SeasonvarImportErrorSanitizer;
use App\Services\Seasonvar\SeasonvarImportRunRecorder;
use App\Services\Seasonvar\SeasonvarTitleManifestBuilder;
use App\Services\Seasonvar\SeasonvarTitleMerger;
use DateTimeInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

final class FinalizeSeasonvarImportTitleGroup implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 0;

    public int $timeout;

    public int $uniqueFor;

    private readonly int $retryUntilTimestamp;

    public function __construct(public readonly int $groupId)
    {
        $this->timeout = max(60, (int) config('seasonvar.queue.worker_timeout', 900));
        $retryWindow = max(
            300,
            (int) config('seasonvar.queue.retry_window_seconds', 21_600),
            (int) config('seasonvar.queue.claim_seconds', 86_400),
        );
        $this->uniqueFor = $retryWindow + 300;
        $this->retryUntilTimestamp = now()->addSeconds($retryWindow)->getTimestamp();
        $this->onConnection((string) config('seasonvar.queue.connection', 'redis'));
    }

    public function handle(
        SeasonvarCatalogImporter $importer,
        SeasonvarTitleManifestBuilder $manifests,
        SeasonvarTitleMerger $titleMerger,
        CatalogTitleRefreshStateStore $refreshStates,
        SeasonvarImportRunRecorder $runs,
        CatalogCacheInvalidator $cacheInvalidator,
    ): void {
        $group = $this->group();

        if ($group === null || $group->status->isTerminal()) {
            return;
        }

        if (! $this->allPagesTerminal($group)) {
            $runs->heartbeat($group->seasonvar_import_run_id);
            $this->release($this->releaseDelay());

            return;
        }

        $group->update(['status' => SeasonvarImportTitleGroupStatus::Finalizing]);
        $lock = $this->uniqueVia()->lock(
            'seasonvar-title-group-apply:'.$group->group_key_hash,
            $this->timeout + 300,
        );

        if (! $lock->get()) {
            $runs->heartbeat($group->seasonvar_import_run_id);
            $this->release($this->releaseDelay());

            return;
        }

        try {
            $group = $this->group();

            if ($group === null || $group->status->isTerminal()) {
                return;
            }

            if (! $this->allPagesTerminal($group)) {
                $runs->heartbeat($group->seasonvar_import_run_id);
                $this->release($this->releaseDelay());

                return;
            }

            [$validRows, $invalidPages] = $this->validatedRows(
                $group->preparedPages->filter(fn (SeasonvarImportPreparedPage $row): bool => in_array(
                    $row->status,
                    [SeasonvarPreparedPageStatus::Prepared, SeasonvarPreparedPageStatus::Applied],
                    true,
                )),
            );
            $validRows = $this->sortRows($validRows, $group->catalogTitle);

            if ($validRows->isEmpty()) {
                $this->finishWithoutPreparedPages($group, $invalidPages, $refreshStates);

                return;
            }

            $sourceManifest = $manifests->fromPrepared($validRows->pluck('prepared'));
            $catalogTitle = $group->catalogTitle;
            $media = ['attached' => 0, 'updated' => 0, 'skipped' => 0, 'failed' => 0];

            foreach ($validRows as $item) {
                $result = $importer->applyPreparedPage(
                    $item['row']->sourcePage,
                    $item['prepared'],
                    $catalogTitle,
                    $group->seasonvar_import_run_id,
                );
                $catalogTitle = $result['catalog_title'];
                $media['attached'] += $result['media_attached'];
                $media['updated'] += $result['media_updated'];
                $media['skipped'] += $result['media_skipped'];
                $media['failed'] += $result['media_failed'];

                if ($group->catalog_title_id === null) {
                    $group->update(['catalog_title_id' => $catalogTitle->id]);
                }
            }

            $titleMerger->mergeForCanonicalSlug($catalogTitle->slug);
            $catalogTitle->refresh();
            $comparison = $sourceManifest->comparison($manifests->fromCatalog($catalogTitle));
            $warningCount = $validRows->sum(
                fn (array $item): int => count($item['row']->warnings ?? []),
            );
            $failedPages = $group->preparedPages()
                ->where('status', SeasonvarPreparedPageStatus::Failed->value)
                ->count();
            $status = ($failedPages > 0 || $warningCount > 0 || $media['failed'] > 0)
                ? SeasonvarImportTitleGroupStatus::Partial
                : SeasonvarImportTitleGroupStatus::Completed;

            $this->persistSuccess(
                $group,
                $validRows,
                $status,
                $failedPages,
                $comparison,
                $warningCount,
                $media,
            );
            $cacheInvalidator->catalogChanged([$catalogTitle->id]);

            if ($this->isVisitorRun($group->run)) {
                if ($status === SeasonvarImportTitleGroupStatus::Completed) {
                    $refreshStates->completed($catalogTitle->id, $group->seasonvar_import_run_id);
                } else {
                    $refreshStates->partial($catalogTitle->id, $group->seasonvar_import_run_id);
                }
            }
        } finally {
            $lock->release();
        }
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [30, 60, 300, 900];
    }

    public function retryUntil(): DateTimeInterface
    {
        return now()->setTimestamp($this->retryUntilTimestamp);
    }

    public function uniqueId(): string
    {
        return 'seasonvar-title-group-finalizer:'.$this->groupId;
    }

    public function uniqueVia(): Repository
    {
        return Cache::store((string) config('seasonvar.queue.lock_store', 'redis-locks'));
    }

    public function failed(?Throwable $exception): void
    {
        $error = app(SeasonvarImportErrorSanitizer::class)->fromException($exception);
        $group = SeasonvarImportTitleGroup::query()->with('run')->find($this->groupId);

        if ($group === null || $group->status->isTerminal()) {
            return;
        }

        $group->update([
            'status' => SeasonvarImportTitleGroupStatus::Failed,
            'last_error' => $error,
            'finished_at' => now(),
        ]);

        if ($this->isVisitorRun($group->run)) {
            $group->run->update([
                'status' => SeasonvarImportStatus::Failed->value,
                'last_error' => $error,
                'finished_at' => now(),
                'last_heartbeat_at' => now(),
            ]);
        }

        if ($group->catalog_title_id !== null && $this->isVisitorRun($group->run)) {
            app(CatalogTitleRefreshStateStore::class)->failed($group->catalog_title_id);
        }

        Log::error('Группа сезонов Seasonvar не финализирована.', [
            'group_id' => $this->groupId,
            'exception' => $exception !== null ? $exception::class : null,
            'error' => $error,
        ]);
    }

    private function group(): ?SeasonvarImportTitleGroup
    {
        return SeasonvarImportTitleGroup::query()
            ->with(['run', 'catalogTitle', 'preparedPages.sourcePage.source'])
            ->find($this->groupId);
    }

    private function allPagesTerminal(SeasonvarImportTitleGroup $group): bool
    {
        $terminal = $group->preparedPages->filter(
            fn (SeasonvarImportPreparedPage $row): bool => $row->status->isTerminal(),
        )->count();

        return $group->expected_pages > 0
            && $terminal === $group->expected_pages
            && $group->preparedPages->count() === $group->expected_pages;
    }

    /**
     * @param  Collection<int, SeasonvarImportPreparedPage>  $rows
     * @return array{Collection<int, array{row: SeasonvarImportPreparedPage, prepared: SeasonvarPreparedCatalogPage}>, int}
     */
    private function validatedRows(Collection $rows): array
    {
        $valid = collect();
        $invalid = 0;

        foreach ($rows as $row) {
            try {
                $valid->push([
                    'row' => $row,
                    'prepared' => SeasonvarPreparedCatalogPage::fromPayload($row->payload ?? []),
                ]);
            } catch (Throwable $exception) {
                $invalid++;
                $row->markFailed(app(SeasonvarImportErrorSanitizer::class)->fromException($exception));
            }
        }

        return [$valid, $invalid];
    }

    /**
     * @param  Collection<int, array{row: SeasonvarImportPreparedPage, prepared: SeasonvarPreparedCatalogPage}>  $rows
     * @return Collection<int, array{row: SeasonvarImportPreparedPage, prepared: SeasonvarPreparedCatalogPage}>
     */
    private function sortRows(Collection $rows, ?CatalogTitle $catalogTitle): Collection
    {
        return $rows->sortBy(fn (array $item): array => [
            $catalogTitle !== null && (int) $catalogTitle->source_page_id === (int) $item['row']->source_page_id ? 0 : 1,
            collect($item['prepared']->catalogData->seasons)->min('number') ?? PHP_INT_MAX,
            $item['row']->source_page_id,
        ])->values();
    }

    /**
     * @param  Collection<int, array{row: SeasonvarImportPreparedPage, prepared: SeasonvarPreparedCatalogPage}>  $validRows
     * @param  array<string, int>  $comparison
     * @param  array{attached: int, updated: int, skipped: int, failed: int}  $media
     */
    private function persistSuccess(
        SeasonvarImportTitleGroup $group,
        Collection $validRows,
        SeasonvarImportTitleGroupStatus $status,
        int $failedPages,
        array $comparison,
        int $warningCount,
        array $media,
    ): void {
        DB::transaction(function () use ($group, $validRows, $status, $failedPages, $comparison, $warningCount, $media): void {
            foreach ($validRows as $item) {
                $item['row']->markApplied();
            }

            $group->update([
                'status' => $status,
                'applied_pages' => $validRows->count(),
                'failed_pages' => $failedPages,
                'finished_at' => now(),
                'last_error' => null,
            ]);
            $run = SeasonvarImportRun::query()->lockForUpdate()->findOrFail($group->seasonvar_import_run_id);
            $run->summary = array_merge($run->summary ?? [], [
                'title_group_id' => $group->id,
                'title_group_status' => $status->value,
                'title_manifest' => $comparison,
                'preparation_warnings' => $warningCount,
            ]);
            $run->media_attached += $media['attached'];
            $run->media_updated += $media['updated'];
            $run->media_skipped += $media['skipped'];
            $run->media_failed += $media['failed'];
            $run->cycles = max(1, (int) $run->cycles);
            $run->last_heartbeat_at = now();

            if ($this->isVisitorRun($run)) {
                $run->status = $status === SeasonvarImportTitleGroupStatus::Completed
                    ? SeasonvarImportStatus::Completed->value
                    : SeasonvarImportStatus::Partial->value;
                $run->finished_at = now();
            }

            $run->save();
        });
    }

    private function finishWithoutPreparedPages(
        SeasonvarImportTitleGroup $group,
        int $invalidPages,
        CatalogTitleRefreshStateStore $refreshStates,
    ): void {
        $message = 'Ни одна страница сезона не подготовлена.';
        $group->update([
            'status' => SeasonvarImportTitleGroupStatus::Failed,
            'failed_pages' => max($group->failed_pages, $invalidPages),
            'last_error' => $message,
            'finished_at' => now(),
        ]);

        if ($this->isVisitorRun($group->run)) {
            $group->run->update([
                'status' => SeasonvarImportStatus::Failed->value,
                'last_error' => $message,
                'finished_at' => now(),
                'last_heartbeat_at' => now(),
            ]);
        }

        if ($group->catalog_title_id !== null && $this->isVisitorRun($group->run)) {
            $refreshStates->failed($group->catalog_title_id);
        }
    }

    private function isVisitorRun(SeasonvarImportRun $run): bool
    {
        return is_numeric(data_get($run->summary, 'catalog_title_id'));
    }

    private function releaseDelay(): int
    {
        return max(1, (int) config(
            'seasonvar.title_refresh.finalizer_delay_seconds',
            config('seasonvar.queue.finalizer_delay_seconds', 60),
        ));
    }
}
