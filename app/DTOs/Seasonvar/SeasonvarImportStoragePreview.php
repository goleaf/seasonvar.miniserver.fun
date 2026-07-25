<?php

declare(strict_types=1);

namespace App\DTOs\Seasonvar;

use Carbon\CarbonInterface;

final readonly class SeasonvarImportStoragePreview
{
    public function __construct(
        public bool $enabled,
        public int $eventRetentionDays,
        public int $snapshotRetentionDays,
        public int $preparedRetentionDays,
        public int $expiredEvents,
        public int $eventContextBytes,
        public ?CarbonInterface $oldestExpiredEventAt,
        public int $expiredSnapshots,
        public int $snapshotBodyBytes,
        public ?CarbonInterface $oldestExpiredSnapshotAt,
        public int $expiredTitleGroups,
        public int $expiredPreparedPages,
        public int $preparedPayloadBytes,
        public ?CarbonInterface $oldestExpiredTitleGroupAt,
        public int $activeRuns,
        public int $activeTitleGroups,
    ) {}

    public function totalExpiredRows(): int
    {
        return $this->expiredEvents
            + $this->expiredSnapshots
            + $this->expiredTitleGroups
            + $this->expiredPreparedPages;
    }

    public function totalEstimatedBytes(): int
    {
        return $this->eventContextBytes
            + $this->snapshotBodyBytes
            + $this->preparedPayloadBytes;
    }

    /**
     * @return array<string, bool|int|string|null>
     */
    public function toArray(): array
    {
        return [
            'enabled' => $this->enabled,
            'event_retention_days' => $this->eventRetentionDays,
            'snapshot_retention_days' => $this->snapshotRetentionDays,
            'prepared_retention_days' => $this->preparedRetentionDays,
            'expired_events' => $this->expiredEvents,
            'event_context_bytes' => $this->eventContextBytes,
            'oldest_expired_event_at' => $this->oldestExpiredEventAt?->toIso8601String(),
            'expired_snapshots' => $this->expiredSnapshots,
            'snapshot_body_bytes' => $this->snapshotBodyBytes,
            'oldest_expired_snapshot_at' => $this->oldestExpiredSnapshotAt?->toIso8601String(),
            'expired_title_groups' => $this->expiredTitleGroups,
            'expired_prepared_pages' => $this->expiredPreparedPages,
            'prepared_payload_bytes' => $this->preparedPayloadBytes,
            'oldest_expired_title_group_at' => $this->oldestExpiredTitleGroupAt?->toIso8601String(),
            'active_runs' => $this->activeRuns,
            'active_title_groups' => $this->activeTitleGroups,
            'total_expired_rows' => $this->totalExpiredRows(),
            'total_estimated_bytes' => $this->totalEstimatedBytes(),
        ];
    }
}
