<?php

declare(strict_types=1);

namespace App\Services\Seasonvar;

use App\DTOs\Seasonvar\SeasonvarQueueStatusData;
use App\Models\SeasonvarImportRun;
use App\Models\SourcePage;
use Illuminate\Queue\QueueManager;

class SeasonvarQueueStatus
{
    public function __construct(private readonly QueueManager $queues) {}

    public function read(): SeasonvarQueueStatusData
    {
        $connectionName = (string) config('seasonvar.queue.connection', 'redis');
        $queueName = (string) config('seasonvar.queue.queue', 'seasonvar-import');
        $queue = $this->queues->connection($connectionName);
        $run = SeasonvarImportRun::query()
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
            runId: $run?->id,
            runStatus: $run?->status,
            selected: (int) ($run?->selected ?? 0),
            parsed: (int) ($run?->parsed ?? 0),
            failed: (int) ($run?->failed ?? 0),
        );
    }
}
