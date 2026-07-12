<?php

namespace App\Services\Seasonvar;

use App\Jobs\FinalizeSeasonvarQueuedImport;
use App\Jobs\ImportSeasonvarSourcePage;
use App\Models\SeasonvarImportRun;
use Throwable;

class SeasonvarQueuedImportDispatcher
{
    public function __construct(
        private readonly SeasonvarCatalogImporter $importer,
        private readonly SeasonvarSitemapMirror $sitemapMirror,
        private readonly SeasonvarRefreshPlanner $refreshPlanner,
        private readonly SeasonvarPageClaimManager $claims,
        private readonly SeasonvarImportRunRecorder $runs,
        private readonly SeasonvarImportGroupKey $groupKeys,
    ) {}

    public function dispatch(bool $force = false, bool $discover = true): SeasonvarImportRun
    {
        $run = SeasonvarImportRun::query()->create([
            'mode' => 'sitemap',
            'execution_mode' => 'queue',
            'status' => 'running',
            'force' => $force,
            'forever' => false,
            'started_at' => now(),
        ]);

        try {
            $recovered = $this->claims->recoverExpired();
            $discovered = 0;
            $stored = 0;

            if ($discover) {
                $mirror = $this->sitemapMirror->mirror();
                $discovered = count($mirror['urls']);
                $stored = $this->importer->storeDiscoveredUrls($mirror['urls']);
            }

            $selected = $this->dispatchEligiblePages($run, $force);
            $this->runs->addCounters($run->id, [
                'discovered' => $discovered,
                'stored' => $stored,
                'selected' => $selected,
            ]);

            $run->refresh();
            $run->summary = array_merge($run->summary ?? [], [
                'expired_claims_recovered' => $recovered,
                'queued_pages' => $selected,
            ]);

            if ($selected === 0) {
                $run->fill([
                    'status' => 'completed',
                    'cycles' => 1,
                    'finished_at' => now(),
                ])->save();

                return $run->refresh();
            }

            $run->save();

            FinalizeSeasonvarQueuedImport::dispatch($run->id)
                ->onConnection((string) config('seasonvar.queue.connection', 'redis'))
                ->onQueue((string) config('seasonvar.queue.queue', 'seasonvar-import'))
                ->delay(now()->addSeconds(max(1, (int) config('seasonvar.queue.finalizer_delay_seconds', 60))));

            return $run->refresh();
        } catch (Throwable $exception) {
            $run->fill([
                'status' => 'failed',
                'last_error' => $exception->getMessage(),
                'finished_at' => now(),
            ])->save();

            throw $exception;
        }
    }

    private function dispatchEligiblePages(SeasonvarImportRun $run, bool $force): int
    {
        $chunkSize = max(1, (int) config('seasonvar.import.chunk_size', 100));
        $refreshAfter = now()->subHours(max(1, (int) config('seasonvar.import.refresh_after_hours', 24)));
        $chunks = $force
            ? $this->refreshPlanner->forcedPageChunks($chunkSize, $run->id)
            : $this->refreshPlanner->pageChunksForImportCycle($chunkSize, $refreshAfter, $run->id);
        $selected = 0;

        foreach ($chunks as $pages) {
            foreach ($pages as $page) {
                $token = $this->claims->claim($page, $run->id);

                if ($token === null) {
                    continue;
                }

                try {
                    ImportSeasonvarSourcePage::dispatch(
                        sourcePageId: (int) $page->id,
                        importRunId: (int) $run->id,
                        claimToken: $token,
                        groupKey: $this->groupKeys->forUrl($page->url, $page->url_hash),
                        force: $force,
                    )
                        ->onConnection((string) config('seasonvar.queue.connection', 'redis'))
                        ->onQueue((string) config('seasonvar.queue.queue', 'seasonvar-import'));
                    $selected++;
                } catch (Throwable) {
                    $this->claims->release((int) $page->id, (int) $run->id, $token);
                    $this->runs->addCounters($run->id, ['failed' => 1]);
                }
            }
        }

        return $selected;
    }
}
