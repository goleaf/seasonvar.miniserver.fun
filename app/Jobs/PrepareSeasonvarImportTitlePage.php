<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Actions\Seasonvar\RecordSeasonvarPageFailure;
use App\Enums\SeasonvarImportFailureType;
use App\Enums\SeasonvarImportStatus;
use App\Enums\SeasonvarPreparedPageStatus;
use App\Models\SeasonvarImportPreparedPage;
use App\Models\SeasonvarImportRun;
use App\Models\SeasonvarImportTitleGroup;
use App\Services\Seasonvar\SeasonvarCatalogPagePreparer;
use App\Services\Seasonvar\SeasonvarImportErrorSanitizer;
use App\Services\Seasonvar\SeasonvarImportTitleGroupDispatcher;
use App\Services\Seasonvar\SeasonvarPageClaimManager;
use DateTimeInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

final class PrepareSeasonvarImportTitlePage implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 0;

    public int $timeout;

    public int $uniqueFor;

    private readonly int $retryUntilTimestamp;

    public function __construct(public readonly int $preparedPageId)
    {
        $this->timeout = max(60, (int) config('seasonvar.queue.worker_timeout', 900));
        $this->uniqueFor = max(
            300,
            (int) config('seasonvar.queue.retry_window_seconds', 21_600),
            (int) config('seasonvar.queue.claim_seconds', 86_400),
        );
        $this->retryUntilTimestamp = now()->addSeconds($this->uniqueFor)->getTimestamp();
        $this->onConnection((string) config('seasonvar.queue.connection', 'redis'));
    }

    public function handle(
        SeasonvarCatalogPagePreparer $preparer,
        SeasonvarImportTitleGroupDispatcher $dispatcher,
        SeasonvarPageClaimManager $claims,
        RecordSeasonvarPageFailure $pageFailures,
    ): void {
        $preparedRow = SeasonvarImportPreparedPage::query()
            ->with(['group.run', 'sourcePage.source'])
            ->findOrFail($this->preparedPageId);

        if (in_array($preparedRow->status, [
            SeasonvarPreparedPageStatus::Prepared,
            SeasonvarPreparedPageStatus::Applied,
        ], true)) {
            return;
        }

        $run = $preparedRow->group->run;

        if ($run->status !== SeasonvarImportStatus::Running->value) {
            return;
        }

        $token = $this->existingClaimToken($preparedRow, $claims)
            ?? $claims->claim($preparedRow->sourcePage, $preparedRow->seasonvar_import_run_id);

        if ($token === null) {
            $this->release(30);

            return;
        }

        $preparedRow->markPreparing();

        try {
            $prepared = $preparer->prepare(
                $preparedRow->sourcePage,
                $preparedRow->seasonvar_import_run_id,
            );
            $dispatcher->addUrls($preparedRow->group, $prepared->discoveredSeasonUrls);
            DB::transaction(function () use ($preparedRow, $prepared): void {
                $preparedRow->markPrepared(
                    $prepared->toPayload(),
                    $prepared->warnings,
                    $prepared->contentHash,
                    $prepared->parserVersion,
                );
                SeasonvarImportTitleGroup::query()->whereKey($preparedRow->group->id)->increment('prepared_pages');
                SeasonvarImportRun::query()->whereKey($preparedRow->seasonvar_import_run_id)->increment('parsed');
            });
        } catch (Throwable $exception) {
            $failureType = $pageFailures->handle(
                $preparedRow->sourcePage,
                $exception,
                $preparedRow->seasonvar_import_run_id,
            );

            if ($failureType === SeasonvarImportFailureType::Permanent) {
                $this->markTerminalFailure($preparedRow, $exception);

                return;
            }

            throw $exception;
        } finally {
            $claims->release(
                $preparedRow->source_page_id,
                $preparedRow->seasonvar_import_run_id,
                $token,
            );
        }
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [60, 300, 900];
    }

    public function retryUntil(): DateTimeInterface
    {
        return Carbon::createFromTimestamp($this->retryUntilTimestamp);
    }

    public function uniqueId(): string
    {
        return 'seasonvar-prepared-page:'.$this->preparedPageId;
    }

    public function uniqueVia(): Repository
    {
        return Cache::store((string) config('seasonvar.queue.lock_store', 'redis-locks'));
    }

    private function existingClaimToken(
        SeasonvarImportPreparedPage $preparedRow,
        SeasonvarPageClaimManager $claims,
    ): ?string {
        $page = $preparedRow->sourcePage;
        $token = is_string($page->import_claim_token) ? $page->import_claim_token : null;

        if ($token === null
            || (int) $page->import_claim_run_id !== (int) $preparedRow->seasonvar_import_run_id
            || ! $claims->owns($page->id, $preparedRow->seasonvar_import_run_id, $token)
        ) {
            return null;
        }

        return $claims->extend(
            $page->id,
            $preparedRow->seasonvar_import_run_id,
            $token,
            $this->timeout + 300,
        ) ? $token : null;
    }

    public function failed(?Throwable $exception): void
    {
        $preparedRow = SeasonvarImportPreparedPage::query()->find($this->preparedPageId);

        if ($preparedRow === null) {
            return;
        }

        $this->markTerminalFailure($preparedRow, $exception);

        Log::error('Страница Seasonvar не подготовлена для групповой финализации.', [
            'prepared_page_id' => $this->preparedPageId,
            'source_page_id' => $preparedRow->source_page_id,
            'import_run_id' => $preparedRow->seasonvar_import_run_id,
            'exception' => $exception !== null ? $exception::class : null,
        ]);
    }

    private function markTerminalFailure(SeasonvarImportPreparedPage $preparedRow, ?Throwable $exception): void
    {
        DB::transaction(function () use ($preparedRow, $exception): void {
            $lockedRow = SeasonvarImportPreparedPage::query()
                ->lockForUpdate()
                ->find($preparedRow->id);

            if ($lockedRow === null || $lockedRow->status?->isTerminal()) {
                return;
            }

            $lockedRow->markFailed(app(SeasonvarImportErrorSanitizer::class)->fromException($exception));
            SeasonvarImportTitleGroup::query()->whereKey($lockedRow->seasonvar_import_title_group_id)->increment('failed_pages');
            SeasonvarImportRun::query()->whereKey($lockedRow->seasonvar_import_run_id)->increment('failed');
        });
    }
}
