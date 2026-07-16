<?php

declare(strict_types=1);

namespace App\Services\Seasonvar;

use App\DTOs\Seasonvar\SeasonvarQueueStatusData;
use App\Enums\SeasonvarImportStatus;
use App\Models\SeasonvarImportRun;
use App\Models\SourcePage;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Queue\QueueManager;

class SeasonvarQueueStatus
{
    public function __construct(
        private readonly QueueManager $queues,
        private readonly SeasonvarGlobalImportRunCoordinator $globalRuns,
    ) {}

    public function read(): SeasonvarQueueStatusData
    {
        $connectionName = (string) config('seasonvar.queue.connection', 'redis');
        $queueName = (string) config('seasonvar.queue.queue', 'seasonvar-import');
        $queue = $this->queues->connection($connectionName);
        $activeRuns = SeasonvarImportRun::query()
            ->where('execution_mode', 'queue')
            ->whereIn('status', [SeasonvarImportStatus::Running->value, SeasonvarImportStatus::Queued->value])
            ->withCount([
                'claimedSourcePages as live_claims_count' => fn (Builder $query): Builder => $query
                    ->whereNotNull('import_claim_token')
                    ->where('import_claim_expires_at', '>', now()),
            ])
            ->orderByDesc('live_claims_count')
            ->orderByDesc('id')
            ->get();
        $run = $this->globalRuns->activeRun()
            ?? SeasonvarImportRun::query()
                ->where('mode', 'sitemap')
                ->latest('id')
                ->first();
        $liveClaims = SourcePage::query()
            ->whereNotNull('import_claim_token')
            ->where('import_claim_expires_at', '>', now())
            ->count();

        return new SeasonvarQueueStatusData(
            connection: $connectionName,
            queue: $queueName,
            pending: (int) $queue->pendingSize($queueName),
            delayed: (int) $queue->delayedSize($queueName),
            reserved: (int) $queue->reservedSize($queueName),
            oldestPendingTimestamp: $queue->creationTimeOfOldestPendingJob($queueName),
            liveClaims: $liveClaims,
            activeRuns: $activeRuns->count(),
            runId: $run?->id,
            runExecutionMode: $run?->execution_mode,
            runStatus: $run?->status,
            lastHeartbeatAt: $run?->last_heartbeat_at,
            selected: (int) $run?->selected,
            parsed: (int) $run?->parsed,
            failed: (int) $run?->failed,
            mediaSizesChecked: (int) $run?->media_sizes_checked,
            mediaSizesKnown: (int) $run?->media_sizes_known,
            mediaSizesUnknown: (int) $run?->media_sizes_unknown,
            mediaSizesUnsupported: (int) $run?->media_sizes_unsupported,
            mediaSizeChecksFailed: (int) $run?->media_size_checks_failed,
            mediaSizeKnownBytes: (int) $run?->media_size_known_bytes,
        );
    }
}
