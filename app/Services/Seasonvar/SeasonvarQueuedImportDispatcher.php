<?php

namespace App\Services\Seasonvar;

use App\Enums\SeasonvarImportStatus;
use App\Enums\SeasonvarPageType;
use App\Jobs\FinalizeSeasonvarQueuedImport;
use App\Jobs\ImportSeasonvarSourcePage;
use App\Models\CatalogTitle;
use App\Models\SeasonvarImportRun;
use App\Models\SourcePage;
use App\Services\Catalog\CatalogCacheInvalidator;
use Throwable;

class SeasonvarQueuedImportDispatcher
{
    public function __construct(
        private readonly SeasonvarCatalogImporter $importer,
        private readonly SeasonvarSitemapMirror $sitemapMirror,
        private readonly SeasonvarRefreshPlanner $refreshPlanner,
        private readonly SeasonvarPageClaimManager $claims,
        private readonly SeasonvarImportRunRecorder $runs,
        private readonly SeasonvarImportTitleGroupDispatcher $titleGroups,
        private readonly SeasonvarImportGroupKey $groupKeys,
        private readonly SeasonvarImportErrorSanitizer $errors,
        private readonly CatalogCacheInvalidator $cacheInvalidator,
    ) {}

    /** @param list<string>|null $pageTypes */
    public function dispatch(bool $force = false, bool $discover = true, ?array $pageTypes = null): SeasonvarImportRun
    {
        $run = SeasonvarImportRun::query()->create([
            'mode' => 'sitemap',
            'execution_mode' => 'queue',
            'status' => SeasonvarImportStatus::Queued->value,
            'force' => $force,
            'forever' => false,
            'last_heartbeat_at' => now(),
            'summary' => [
                'discover' => $discover,
                'provider' => 'seasonvar',
                'page_types' => $pageTypes,
            ],
        ]);

        try {
            return $this->dispatchRun($run);
        } catch (Throwable $exception) {
            $run->fill([
                'status' => SeasonvarImportStatus::Failed->value,
                'last_error' => $this->errors->fromException($exception),
                'finished_at' => now(),
                'last_heartbeat_at' => now(),
            ])->save();

            throw $exception;
        }
    }

    public function dispatchRun(SeasonvarImportRun $run): SeasonvarImportRun
    {
        $started = SeasonvarImportRun::query()
            ->whereKey($run->id)
            ->where('execution_mode', 'queue')
            ->where('status', SeasonvarImportStatus::Queued->value)
            ->update([
                'status' => SeasonvarImportStatus::Running->value,
                'started_at' => $run->started_at ?? now(),
                'finished_at' => null,
                'last_error' => null,
                'last_heartbeat_at' => now(),
                'updated_at' => now(),
            ]);

        if ($started !== 1) {
            return $run->fresh();
        }

        $run->refresh();
        $discover = (bool) data_get($run->summary, 'discover', true);
        $recovered = $this->claims->recoverExpired();
        $discovered = 0;
        $stored = 0;

        if ($discover) {
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
            $this->runs->heartbeat($run->id);
        }

        if ($run->fresh()->status !== SeasonvarImportStatus::Running->value) {
            return $run->fresh();
        }

        $selected = $this->dispatchEligiblePages(
            $run,
            (bool) $run->force,
            is_array(data_get($run->summary, 'page_types')) ? data_get($run->summary, 'page_types') : null,
        );
        $this->runs->addCounters($run->id, [
            'discovered' => $discovered,
            'stored' => $stored,
        ]);

        $run->refresh();
        $run->summary = array_merge($run->summary ?? [], [
            'expired_claims_recovered' => $recovered,
            'queued_pages' => $selected,
        ]);

        if ($run->status !== SeasonvarImportStatus::Running->value) {
            $run->save();

            return $run->refresh();
        }

        if ($selected === 0) {
            $run->fill([
                'status' => $run->completionStatus(),
                'cycles' => 1,
                'finished_at' => now(),
                'last_heartbeat_at' => now(),
            ])->save();
            $this->cacheInvalidator->catalogChanged();

            return $run->refresh();
        }

        $run->save();

        FinalizeSeasonvarQueuedImport::dispatch($run->id)
            ->onConnection((string) config('seasonvar.queue.connection', 'redis'))
            ->onQueue((string) config('seasonvar.queue.queue', 'seasonvar-import'))
            ->delay(now()->addSeconds(max(1, (int) config('seasonvar.queue.finalizer_delay_seconds', 60))))
            ->afterCommit();

        return $run->refresh();
    }

    /** @param list<string>|null $pageTypes */
    private function dispatchEligiblePages(SeasonvarImportRun $run, bool $force, ?array $pageTypes): int
    {
        $chunkSize = max(1, (int) config('seasonvar.import.chunk_size', 100));
        $refreshAfter = now()->subHours(max(1, (int) config('seasonvar.import.refresh_after_hours', 24)));
        $chunks = $force
            ? $this->refreshPlanner->forcedPageChunks($chunkSize, $run->id, pageTypes: $pageTypes)
            : $this->refreshPlanner->pageChunksForImportCycle($chunkSize, $refreshAfter, $run->id, pageTypes: $pageTypes);
        $selected = 0;

        foreach ($chunks as $pages) {
            foreach ($pages as $page) {
                if ($selected % 25 === 0 && SeasonvarImportRun::query()->whereKey($run->id)->value('status') !== SeasonvarImportStatus::Running->value) {
                    break 2;
                }

                $claimToken = $this->claims->claim($page, $run->id);

                if ($claimToken === null) {
                    continue;
                }

                try {
                    if ($page->page_type !== SeasonvarPageType::Serial->value) {
                        ImportSeasonvarSourcePage::dispatch(
                            sourcePageId: (int) $page->id,
                            importRunId: (int) $run->id,
                            claimToken: $claimToken,
                            groupKey: $this->groupKeys->forUrl($page->url, $page->url_hash),
                            force: $force,
                        )
                            ->onConnection((string) config('seasonvar.queue.connection', 'redis'))
                            ->onQueue((string) config('seasonvar.queue.queue', 'seasonvar-import'))
                            ->afterCommit();
                        SeasonvarImportRun::query()->whereKey($run->id)->increment('selected');
                        $selected++;

                        continue;
                    }

                    $alreadyAdopted = $run->preparedPages()
                        ->where('source_page_id', $page->id)
                        ->exists();
                    $this->titleGroups->adoptPage(
                        $run,
                        $page,
                        (string) config('seasonvar.queue.queue', 'seasonvar-import'),
                        $this->catalogTitleFor($page),
                    );

                    if (! $alreadyAdopted) {
                        $selected++;
                    }
                } catch (Throwable) {
                    $this->claims->release($page->id, $run->id, $claimToken);
                    $this->runs->addCounters($run->id, ['failed' => 1]);
                }
            }
        }

        return $selected;
    }

    private function catalogTitleFor(SourcePage $page): ?CatalogTitle
    {
        return CatalogTitle::query()
            ->where('source_id', $page->source_id)
            ->where(function ($query) use ($page): void {
                $query->where('source_page_id', $page->id)
                    ->orWhere('source_url_hash', $page->url_hash)
                    ->orWhereHas('seasons', fn ($query) => $query->where('source_url_hash', $page->url_hash));
            })
            ->orderBy('id')
            ->first();
    }
}
