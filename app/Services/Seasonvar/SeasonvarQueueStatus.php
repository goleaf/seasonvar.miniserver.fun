<?php

declare(strict_types=1);

namespace App\Services\Seasonvar;

use App\DTOs\Seasonvar\SeasonvarQueueStatusData;
use App\Models\SeasonvarImportRun;
use App\Models\SourcePage;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Queue\QueueManager;

class SeasonvarQueueStatus
{
    public function __construct(private readonly QueueManager $queues) {}

    public function read(): SeasonvarQueueStatusData
    {
        $connectionName = (string) config('seasonvar.queue.connection', 'redis');
        $queueName = (string) config('seasonvar.queue.queue', 'seasonvar-import');
        $queue = $this->queues->connection($connectionName);
        $runningRuns = SeasonvarImportRun::query()
            ->where('execution_mode', 'queue')
            ->where('status', 'running')
            ->withCount([
                'claimedSourcePages as live_claims_count' => fn (Builder $query): Builder => $query
                    ->whereNotNull('import_claim_token')
                    ->where('import_claim_expires_at', '>', now()),
            ])
            ->orderByDesc('live_claims_count')
            ->orderByDesc('id')
            ->get();
        $run = $runningRuns->first() ?? SeasonvarImportRun::query()
            ->where('execution_mode', 'queue')
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
            activeRuns: $runningRuns->count(),
            runId: $run?->id,
            runStatus: $run?->status,
            selected: (int) ($run?->selected ?? 0),
            parsed: (int) ($run?->parsed ?? 0),
            failed: (int) ($run?->failed ?? 0),
        );
    }
}
