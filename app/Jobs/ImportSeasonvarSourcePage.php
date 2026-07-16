<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\SeasonvarImportRun;
use App\Models\SourcePage;
use App\Services\Seasonvar\SeasonvarCatalogImporter;
use App\Services\Seasonvar\SeasonvarImportErrorSanitizer;
use App\Services\Seasonvar\SeasonvarImportFinalizationDispatcher;
use App\Services\Seasonvar\SeasonvarImportGroupKey;
use App\Services\Seasonvar\SeasonvarImportRunRecorder;
use App\Services\Seasonvar\SeasonvarImportTitleGroupDispatcher;
use App\Services\Seasonvar\SeasonvarPageClaimManager;
use DateTimeInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\Store;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use LogicException;
use Throwable;

class ImportSeasonvarSourcePage implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 0;

    public int $timeout;

    public readonly int $retryUntilTimestamp;

    public function __construct(
        public readonly int $sourcePageId,
        public readonly int $importRunId,
        public readonly string $claimToken,
        public readonly string $groupKey,
        public readonly bool $force = false,
    ) {
        $this->timeout = max(60, (int) config('seasonvar.queue.worker_timeout', 900));
        $retryWindowSeconds = max(
            300,
            (int) config('seasonvar.queue.retry_window_seconds', 21600),
            (int) config('seasonvar.queue.claim_seconds', 86400),
        );
        $this->retryUntilTimestamp = now()
            ->addSeconds($retryWindowSeconds)
            ->getTimestamp();
        $this->onConnection((string) config('seasonvar.queue.connection', 'redis'));
        $this->onQueue((string) config('seasonvar.queue.queue', 'seasonvar-import'));
    }

    public function handle(
        SeasonvarPageClaimManager $claims,
        SeasonvarImportTitleGroupDispatcher $groups,
        ?SeasonvarCatalogImporter $importer = null,
        ?SeasonvarImportRunRecorder $runs = null,
        ?SeasonvarImportGroupKey $groupKeys = null,
        ?SeasonvarImportFinalizationDispatcher $finalizers = null,
    ): void {
        $finalizers ??= app(SeasonvarImportFinalizationDispatcher::class);
        $run = SeasonvarImportRun::query()
            ->whereKey($this->importRunId)
            ->where('execution_mode', 'queue')
            ->where('status', 'running')
            ->first();

        if ($run === null) {
            $claims->release($this->sourcePageId, $this->importRunId, $this->claimToken);

            return;
        }

        if (! $claims->owns($this->sourcePageId, $this->importRunId, $this->claimToken)) {
            return;
        }

        $page = SourcePage::query()->with([
            'source:id,code,base_url,crawl_delay_seconds',
            'catalogTitle:id,source_id,source_page_id,source_url,source_url_hash',
        ])->find($this->sourcePageId);

        if ($page === null) {
            $claims->release($this->sourcePageId, $this->importRunId, $this->claimToken);
            $finalizers->signalGlobalRun($run);

            return;
        }

        if ($page->page_type !== 'serial') {
            $this->handleNonSerialPage(
                $run,
                $page,
                $claims,
                $importer ?? app(SeasonvarCatalogImporter::class),
                $runs ?? app(SeasonvarImportRunRecorder::class),
                $groupKeys ?? app(SeasonvarImportGroupKey::class),
                $finalizers,
            );

            return;
        }

        $groups->adoptPage(
            $run,
            $page,
            (string) config('seasonvar.queue.queue', 'seasonvar-import'),
            $page->catalogTitle,
        );
    }

    private function handleNonSerialPage(
        SeasonvarImportRun $run,
        SourcePage $page,
        SeasonvarPageClaimManager $claims,
        SeasonvarCatalogImporter $importer,
        SeasonvarImportRunRecorder $runs,
        SeasonvarImportGroupKey $groupKeys,
        SeasonvarImportFinalizationDispatcher $finalizers,
    ): void {
        if (! $claims->extend(
            $page->id,
            $this->importRunId,
            $this->claimToken,
            $this->timeout + 300,
        )) {
            return;
        }

        $runs->heartbeat($this->importRunId);
        $lock = $this->lockStore()
            ->lock($groupKeys->forUrl($page->url, $page->url_hash), $this->timeout + 300);

        if (! $lock->get()) {
            $claims->extend($page->id, $this->importRunId, $this->claimToken, 3600);
            $this->release(30);

            return;
        }

        $releaseClaim = false;

        try {
            $result = $importer->parsePages(
                collect([$page]),
                null,
                $this->force,
                $this->importRunId,
                true,
            );
            $runs->addCounters($this->importRunId, [
                'parsed' => $result['parsed'],
                'failed' => $result['failed'],
                'media_attached' => $result['media_attached'],
                'media_updated' => $result['media_updated'],
                'media_skipped' => $result['media_skipped'],
                'media_failed' => $result['media_failed'],
            ]);
            $releaseClaim = true;
        } finally {
            if ($releaseClaim) {
                $claims->release($page->id, $this->importRunId, $this->claimToken);
                $finalizers->signalGlobalRun($run);
            }

            $lock->release();
        }
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [60, 300, 900];
    }

    public function retryUntil(): DateTimeInterface
    {
        return Carbon::createFromTimestamp($this->retryUntilTimestamp);
    }

    public function failed(?Throwable $exception): void
    {
        $released = app(SeasonvarPageClaimManager::class)->release(
            $this->sourcePageId,
            $this->importRunId,
            $this->claimToken,
        );

        if ($released) {
            app(SeasonvarImportRunRecorder::class)->addCounters($this->importRunId, ['failed' => 1]);
        }

        $run = SeasonvarImportRun::query()->find($this->importRunId);

        if ($run !== null) {
            app(SeasonvarImportFinalizationDispatcher::class)->signalGlobalRun($run);
        }

        Log::error('Страница Seasonvar не обработана queue worker.', [
            'source_page_id' => $this->sourcePageId,
            'import_run_id' => $this->importRunId,
            'group_key' => $this->groupKey,
            'exception' => $exception ? get_class($exception) : null,
            'error' => app(SeasonvarImportErrorSanitizer::class)->fromException($exception),
        ]);
    }

    private function lockStore(): Store&LockProvider
    {
        $repository = Cache::store((string) config('seasonvar.queue.lock_store', 'redis-locks'));

        if (! $repository instanceof CacheRepository) {
            throw new LogicException('Seasonvar lock cache repository is unavailable.');
        }

        $store = $repository->getStore();

        if (! $store instanceof LockProvider) {
            throw new LogicException('Seasonvar lock cache store does not support atomic locks.');
        }

        return $store;
    }
}
