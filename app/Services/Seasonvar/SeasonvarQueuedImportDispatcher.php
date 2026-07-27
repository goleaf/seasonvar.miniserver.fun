<?php

declare(strict_types=1);

namespace App\Services\Seasonvar;

use App\DTOs\Seasonvar\SeasonvarImportStartResultData;
use App\Enums\SeasonvarImportStatus;
use App\Enums\SeasonvarPageType;
use App\Jobs\WakeSeasonvarImportFinalizers;
use App\Models\SeasonvarImportRun;
use App\Models\SourcePage;
use App\Services\Catalog\CatalogCacheInvalidator;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;
use Throwable;

class SeasonvarQueuedImportDispatcher
{
    public function __construct(
        private readonly SeasonvarCatalogImporter $importer,
        private readonly SeasonvarSitemapMirror $sitemapMirror,
        private readonly SeasonvarUrl $seasonvarUrl,
        private readonly SeasonvarPageClaimManager $claims,
        private readonly SeasonvarImportRunRecorder $runs,
        private readonly SeasonvarImportDispatchBatcher $batcher,
        private readonly SeasonvarImportErrorSanitizer $errors,
        private readonly CatalogCacheInvalidator $cacheInvalidator,
        private readonly SeasonvarGlobalImportRunCoordinator $globalRuns,
        private readonly SeasonvarImportFinalizationDispatcher $finalizers,
    ) {}

    /** @param list<string>|null $pageTypes */
    public function dispatch(
        bool $force = false,
        bool $discover = true,
        ?array $pageTypes = null,
        ?int $sitemapTailLimit = null,
    ): SeasonvarImportStartResultData {
        $this->validateSitemapTail($force, $discover, $pageTypes, $sitemapTailLimit);

        $result = $this->globalRuns->acquire(
            $force,
            $discover,
            $pageTypes,
            sitemapTailLimit: $sitemapTailLimit,
        );

        if (! $result->created) {
            WakeSeasonvarImportFinalizers::dispatch()->afterCommit();

            return $result;
        }

        $run = $result->run;

        try {
            return new SeasonvarImportStartResultData($this->dispatchRun($run), true);
        } catch (Throwable $exception) {
            $run->fill([
                'status' => SeasonvarImportStatus::Failed->value,
                'last_error' => $this->errors->fromException($exception),
                'finished_at' => now(),
                'last_progress_at' => now(),
                'last_heartbeat_at' => now(),
            ])->save();

            throw $exception;
        }
    }

    public function dispatchRun(SeasonvarImportRun $run): SeasonvarImportRun
    {
        $run = $run->fresh();

        if ($run->execution_mode !== 'queue') {
            return $run;
        }

        if ($run->status === SeasonvarImportStatus::Queued->value) {
            $started = SeasonvarImportRun::query()
                ->whereKey($run->id)
                ->where('execution_mode', 'queue')
                ->where('status', SeasonvarImportStatus::Queued->value)
                ->update([
                    'status' => SeasonvarImportStatus::Running->value,
                    'started_at' => $run->started_at ?? now(),
                    'finished_at' => null,
                    'last_error' => null,
                    'last_progress_at' => now(),
                    'last_heartbeat_at' => now(),
                    'updated_at' => now(),
                ]);

            if ($started !== 1) {
                return $run->fresh();
            }

            $run->refresh();
        } elseif ($run->status !== SeasonvarImportStatus::Running->value
            || data_get($run->summary, 'dispatch_completed') !== false
        ) {
            return $run;
        }

        $summary = $run->summary ?? [];
        $discover = (bool) ($summary['discover'] ?? true);
        $sitemapTailLimit = data_get($run->summary, 'sitemap_tail_limit');

        if ($sitemapTailLimit !== null && (! is_int($sitemapTailLimit) || $sitemapTailLimit < 1 || $sitemapTailLimit > 1000)) {
            throw new LogicException('Запуск содержит некорректный лимит хвоста XML-карты сайта.');
        }

        if ($sitemapTailLimit !== null && (! $discover || ! $run->force)) {
            throw new LogicException('Хвост XML-карты сайта требует discovery и принудительный режим.');
        }

        $recovered = $this->claims->recoverExpired();
        $discovered = 0;
        $stored = 0;
        $discoveryCompleted = array_key_exists('discovery_completed', $summary)
            ? $summary['discovery_completed'] === true
            : ! $discover;

        if (! $discoveryCompleted) {
            if (! $discover) {
                throw new LogicException('Запуск без discovery содержит незавершённый discovery barrier.');
            }

            $lastHeartbeatAt = now();
            $heartbeat = function (string $_event, array $_context) use ($run, &$lastHeartbeatAt): void {
                $now = now();

                if ($lastHeartbeatAt->greaterThan($now->copy()->subSeconds(30))) {
                    return;
                }

                $this->runs->heartbeat($run->id);
                $lastHeartbeatAt = $now;
            };
            $mirror = $this->sitemapMirror->mirror($heartbeat);
            $discovered = count($mirror['urls']);
            $stored = $this->importer->storeDiscoveredUrls($mirror['urls'], $heartbeat);
            $sitemapTailUrls = $sitemapTailLimit !== null
                ? $this->lastSerialUrls($mirror['urls'], $sitemapTailLimit)
                : null;
            $sitemapTailPageIds = $sitemapTailUrls !== null
                ? $this->sourcePageIdsForUrls($sitemapTailUrls)
                : null;
            $this->runs->addCounters($run->id, [
                'discovered' => $discovered,
                'stored' => $stored,
            ]);
            $run = $this->runs->mergeSummary($run->id, [
                'discovery_completed' => true,
                'expired_claims_recovered' => $recovered,
                'sitemap_tail_page_ids' => $sitemapTailPageIds,
                'sitemap_tail_selected' => $sitemapTailPageIds !== null
                    ? count($sitemapTailPageIds)
                    : null,
            ], markProgress: true) ?? $run->fresh();
        }

        if ($run->fresh()->status !== SeasonvarImportStatus::Running->value
            || data_get($run->fresh()->summary, 'discovery_completed') !== true
        ) {
            return $run->fresh();
        }

        $batch = $this->batcher->dispatchNext($run->id);
        $run = $run->fresh();
        $run = $this->runs->mergeSummary($run->id, [
            'expired_claims_recovered' => $recovered,
            'queued_pages' => (int) $run->selected,
        ]) ?? $run;

        if ($batch->dispatchCompleted && (int) $run->selected === 0) {
            $run = DB::transaction(function () use ($run): SeasonvarImportRun {
                $lockedRun = SeasonvarImportRun::query()->lockForUpdate()->findOrFail($run->id);

                if ($lockedRun->status === SeasonvarImportStatus::Running->value) {
                    $lockedRun->fill([
                        'status' => $lockedRun->completionStatus(),
                        'cycles' => 1,
                        'finished_at' => now(),
                        'last_progress_at' => now(),
                        'last_heartbeat_at' => now(),
                    ]);
                }

                $lockedRun->save();

                return $lockedRun;
            }, 3);
            $this->cacheInvalidator->catalogChanged();

            return $run->refresh();
        }

        if (! $batch->dispatchCompleted
            || $run->status !== SeasonvarImportStatus::Running->value
        ) {
            return $run->refresh();
        }

        $this->finalizers->globalRun(
            $run,
            max(1, (int) config('seasonvar.queue.finalizer_delay_seconds', 60)),
        );

        return $run->refresh();
    }

    /** @param list<string>|null $pageTypes */
    private function validateSitemapTail(
        bool $force,
        bool $discover,
        ?array $pageTypes,
        ?int $sitemapTailLimit,
    ): void {
        if ($sitemapTailLimit === null) {
            return;
        }

        if ($sitemapTailLimit < 1 || $sitemapTailLimit > 1000) {
            throw new InvalidArgumentException('Лимит хвоста XML-карты сайта должен быть от 1 до 1000.');
        }

        if (! $force || ! $discover) {
            throw new InvalidArgumentException('Хвост XML-карты сайта требует discovery и принудительный режим.');
        }

        if ($pageTypes !== null && array_values(array_unique($pageTypes)) !== [SeasonvarPageType::Serial->value]) {
            throw new InvalidArgumentException('Хвост XML-карты сайта поддерживает только serial-страницы.');
        }
    }

    /**
     * @param  list<string>  $urls
     * @return list<string>
     */
    private function lastSerialUrls(array $urls, int $limit): array
    {
        $selected = [];

        for ($index = count($urls) - 1; $index >= 0 && count($selected) < $limit; $index--) {
            $url = $urls[$index];

            if ($this->seasonvarUrl->pageType($url) !== SeasonvarPageType::Serial || isset($selected[$url])) {
                continue;
            }

            $selected[$url] = $url;
        }

        return array_reverse(array_values($selected));
    }

    /**
     * @param  list<string>  $urls
     * @return list<int>
     */
    private function sourcePageIdsForUrls(array $urls): array
    {
        $pages = SourcePage::query()
            ->select(['id', 'url'])
            ->whereIn('url', $urls)
            ->whereHas('source', fn ($query) => $query->where('code', 'seasonvar'))
            ->orderBy('id')
            ->get()
            ->keyBy('url');

        return collect($urls)
            ->map(
                static fn (string $url): ?int => $pages->get($url)?->id,
            )
            ->filter(static fn (?int $id): bool => $id !== null)
            ->values()
            ->all();
    }
}
