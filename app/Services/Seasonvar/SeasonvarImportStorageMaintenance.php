<?php

declare(strict_types=1);

namespace App\Services\Seasonvar;

use App\DTOs\Seasonvar\SeasonvarImportStoragePreview;
use App\Enums\SeasonvarImportStatus;
use App\Enums\SeasonvarImportTitleGroupStatus;
use App\Models\SeasonvarImportEvent;
use App\Models\SeasonvarImportPreparedPage;
use App\Models\SeasonvarImportRun;
use App\Models\SeasonvarImportTitleGroup;
use App\Models\SourcePageSnapshot;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

final class SeasonvarImportStorageMaintenance
{
    private const REDACTED_URL = '[redacted-url]';

    private const NANOSECONDS_PER_SECOND = 1_000_000_000;

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public function sanitizeEventContext(array $context): array
    {
        return $this->sanitizeArray($context);
    }

    public function preview(): SeasonvarImportStoragePreview
    {
        $eventRetentionDays = max(0, (int) config('seasonvar.import.event_retention_days', 7));
        $snapshotRetentionDays = max(0, (int) config('seasonvar.import.snapshot_retention_days', 14));
        $preparedRetentionDays = max(0, (int) config('seasonvar.import.prepared_retention_days', 7));
        $eventMetrics = $eventRetentionDays > 0
            ? $this->aggregateMetrics(
                $this->eligibleImportEvents(now()->subDays($eventRetentionDays)),
                'LENGTH(context)',
                'created_at',
            )
            : $this->emptyMetrics();
        $snapshotMetrics = $snapshotRetentionDays > 0
            ? $this->aggregateMetrics(
                $this->eligibleSourcePageSnapshots(now()->subDays($snapshotRetentionDays)),
                'body_bytes',
                'captured_at',
            )
            : $this->emptyMetrics();
        $titleGroupCutoff = now()->subDays($preparedRetentionDays);
        $titleGroupMetrics = $preparedRetentionDays > 0
            ? $this->aggregateMetrics(
                $this->eligibleTitleGroups($titleGroupCutoff),
                '0',
                'finished_at',
            )
            : $this->emptyMetrics();
        $preparedPageMetrics = $preparedRetentionDays > 0
            ? $this->aggregateMetrics(
                SeasonvarImportPreparedPage::query()->whereIn(
                    'seasonvar_import_title_group_id',
                    $this->eligibleTitleGroups($titleGroupCutoff)->select('id'),
                ),
                'COALESCE(LENGTH(payload), 0) + COALESCE(LENGTH(payload_blob), 0) + COALESCE(LENGTH(application_result), 0) + COALESCE(LENGTH(warnings), 0)',
                'created_at',
            )
            : $this->emptyMetrics();

        return new SeasonvarImportStoragePreview(
            enabled: filter_var(config('seasonvar.import.storage_maintenance_enabled', true), FILTER_VALIDATE_BOOL),
            eventRetentionDays: $eventRetentionDays,
            snapshotRetentionDays: $snapshotRetentionDays,
            preparedRetentionDays: $preparedRetentionDays,
            expiredEvents: $eventMetrics['rows'],
            eventContextBytes: $eventMetrics['bytes'],
            oldestExpiredEventAt: $eventMetrics['oldest'],
            expiredSnapshots: $snapshotMetrics['rows'],
            snapshotBodyBytes: $snapshotMetrics['bytes'],
            oldestExpiredSnapshotAt: $snapshotMetrics['oldest'],
            expiredTitleGroups: $titleGroupMetrics['rows'],
            expiredPreparedPages: $preparedPageMetrics['rows'],
            preparedPayloadBytes: $preparedPageMetrics['bytes'],
            oldestExpiredTitleGroupAt: $titleGroupMetrics['oldest'],
            activeRuns: SeasonvarImportRun::query()->whereIn('status', $this->activeRunStatuses())->count(),
            activeTitleGroups: SeasonvarImportTitleGroup::query()
                ->whereIn('status', $this->activeTitleGroupStatuses())
                ->count(),
        );
    }

    /**
     * @return array{enabled: bool, event_retention_days: int, snapshot_retention_days: int, prepared_retention_days: int, chunk_size: int, max_chunks: int, max_rows: int, time_budget_seconds: int, elapsed_milliseconds: int, chunks_processed: int, rows_deleted: int, stopped_reason: string, events_deleted: int, snapshots_deleted: int, title_groups_deleted: int, prepared_pages_deleted: int}
     */
    public function prune(): array
    {
        $enabled = filter_var(config('seasonvar.import.storage_maintenance_enabled', true), FILTER_VALIDATE_BOOL);
        $eventRetentionDays = max(0, (int) config('seasonvar.import.event_retention_days', 7));
        $snapshotRetentionDays = max(0, (int) config('seasonvar.import.snapshot_retention_days', 14));
        $preparedRetentionDays = max(0, (int) config('seasonvar.import.prepared_retention_days', 7));
        $chunkSize = max(1, min(1_000, (int) config('seasonvar.import.maintenance_chunk_size', 500)));
        $maxChunks = max(1, min(100, (int) config('seasonvar.import.maintenance_max_chunks', 10)));
        $maxRows = max(1, min(100_000, (int) config('seasonvar.import.maintenance_max_rows', 5_000)));
        $timeBudgetSeconds = max(
            1,
            min(600, (int) config('seasonvar.import.maintenance_time_budget_seconds', 30)),
        );
        $startedAt = hrtime(true);

        if (! $enabled) {
            return [
                'enabled' => false,
                'event_retention_days' => $eventRetentionDays,
                'snapshot_retention_days' => $snapshotRetentionDays,
                'prepared_retention_days' => $preparedRetentionDays,
                'chunk_size' => $chunkSize,
                'max_chunks' => $maxChunks,
                'max_rows' => $maxRows,
                'time_budget_seconds' => $timeBudgetSeconds,
                'elapsed_milliseconds' => 0,
                'chunks_processed' => 0,
                'rows_deleted' => 0,
                'stopped_reason' => 'disabled',
                'events_deleted' => 0,
                'snapshots_deleted' => 0,
                'title_groups_deleted' => 0,
                'prepared_pages_deleted' => 0,
            ];
        }

        /** @var array{started_at: int, deadline: int, chunk_size: int, max_chunks: int, max_rows: int, chunks_processed: int, rows_deleted: int, stopped_reason: string|null} $budget */
        $budget = [
            'started_at' => $startedAt,
            'deadline' => $startedAt + ($timeBudgetSeconds * self::NANOSECONDS_PER_SECOND),
            'chunk_size' => $chunkSize,
            'max_chunks' => $maxChunks,
            'max_rows' => $maxRows,
            'chunks_processed' => 0,
            'rows_deleted' => 0,
            'stopped_reason' => null,
        ];

        $eventsResult = $eventRetentionDays > 0
            ? $this->pruneImportEvents(now()->subDays($eventRetentionDays), $budget)
            : 0;
        $snapshotsDeleted = $snapshotRetentionDays > 0 && $budget['stopped_reason'] === null
            ? $this->pruneSourcePageSnapshots(now()->subDays($snapshotRetentionDays), $budget)
            : 0;
        $titleGroupResult = $preparedRetentionDays > 0 && $budget['stopped_reason'] === null
            ? $this->pruneTitleGroups(now()->subDays($preparedRetentionDays), $budget)
            : ['groups' => 0, 'prepared_pages' => 0];
        $elapsedMilliseconds = max(0, intdiv(hrtime(true) - $startedAt, 1_000_000));

        return [
            'enabled' => true,
            'event_retention_days' => $eventRetentionDays,
            'snapshot_retention_days' => $snapshotRetentionDays,
            'prepared_retention_days' => $preparedRetentionDays,
            'chunk_size' => $chunkSize,
            'max_chunks' => $maxChunks,
            'max_rows' => $maxRows,
            'time_budget_seconds' => $timeBudgetSeconds,
            'elapsed_milliseconds' => $elapsedMilliseconds,
            'chunks_processed' => $budget['chunks_processed'],
            'rows_deleted' => $budget['rows_deleted'],
            'stopped_reason' => $budget['stopped_reason'] ?? 'complete',
            'events_deleted' => $eventsResult,
            'snapshots_deleted' => $snapshotsDeleted,
            'title_groups_deleted' => $titleGroupResult['groups'],
            'prepared_pages_deleted' => $titleGroupResult['prepared_pages'],
        ];
    }

    /**
     * @param  array<array-key, mixed>  $values
     * @return array<array-key, mixed>
     */
    private function sanitizeArray(array $values): array
    {
        $sanitized = [];

        foreach ($values as $key => $value) {
            $sanitized[$key] = $this->sanitizeValue($value, is_string($key) ? $key : null);
        }

        return $sanitized;
    }

    private function sanitizeValue(mixed $value, ?string $key): mixed
    {
        if (is_array($value)) {
            return $this->sanitizeArray($value);
        }

        if (is_string($value) && ($this->isUrlContextKey($key) || $this->containsUrl($value))) {
            return self::REDACTED_URL;
        }

        return $value;
    }

    private function isUrlContextKey(?string $key): bool
    {
        if ($key === null) {
            return false;
        }

        $key = Str::of($key)->lower()->toString();

        return in_array($key, ['url', 'uri', 'href', 'link'], true)
            || Str::endsWith($key, ['_url', '_uri', '_href', '_link']);
    }

    private function containsUrl(string $value): bool
    {
        return preg_match('~https?://[^\s<>"\']+~i', $value) === 1;
    }

    /**
     * @param  array{started_at: int, deadline: int, chunk_size: int, max_chunks: int, max_rows: int, chunks_processed: int, rows_deleted: int, stopped_reason: string|null}  $budget
     */
    private function pruneImportEvents(Carbon $cutoff, array &$budget): int
    {
        return $this->deleteBatches(
            fn (int $limit): Collection => $this->eligibleImportEvents($cutoff)
                ->orderBy('id')
                ->limit($limit)
                ->pluck('id'),
            fn (array $ids): int => SeasonvarImportEvent::query()->whereKey($ids)->delete(),
            $budget,
        );
    }

    /**
     * @param  array{started_at: int, deadline: int, chunk_size: int, max_chunks: int, max_rows: int, chunks_processed: int, rows_deleted: int, stopped_reason: string|null}  $budget
     */
    private function pruneSourcePageSnapshots(Carbon $cutoff, array &$budget): int
    {
        return $this->deleteBatches(
            fn (int $limit): Collection => $this->eligibleSourcePageSnapshots($cutoff)
                ->orderBy('id')
                ->limit($limit)
                ->pluck('id'),
            fn (array $ids): int => SourcePageSnapshot::query()->whereKey($ids)->delete(),
            $budget,
        );
    }

    /**
     * @param  array{started_at: int, deadline: int, chunk_size: int, max_chunks: int, max_rows: int, chunks_processed: int, rows_deleted: int, stopped_reason: string|null}  $budget
     * @return array{groups: int, prepared_pages: int}
     */
    private function pruneTitleGroups(Carbon $cutoff, array &$budget): array
    {
        $groupsDeleted = 0;
        $preparedPagesDeleted = 0;

        while ($this->mayStartChunk($budget)) {
            $remainingRows = $budget['max_rows'] - $budget['rows_deleted'];
            $groups = $this->eligibleTitleGroups($cutoff)
                ->withCount('preparedPages')
                ->orderBy('id')
                ->limit(min($budget['chunk_size'], $remainingRows))
                ->get(['id']);

            if ($groups->isEmpty()) {
                break;
            }

            $ids = [];
            $batchRows = 0;
            $batchPreparedPages = 0;

            foreach ($groups as $group) {
                $groupRows = 1 + (int) $group->prepared_pages_count;

                if ($batchRows + $groupRows > $remainingRows) {
                    break;
                }

                $ids[] = (int) $group->id;
                $batchRows += $groupRows;
                $batchPreparedPages += (int) $group->prepared_pages_count;
            }

            if ($ids === []) {
                $budget['stopped_reason'] = 'max_rows';

                break;
            }

            if ($this->timeBudgetExpired($budget)) {
                break;
            }

            $deleted = max(0, (int) SeasonvarImportTitleGroup::query()->whereKey($ids)->delete());
            $groupsDeleted += $deleted;
            $preparedPagesDeleted += $batchPreparedPages;
            $budget['chunks_processed']++;
            $budget['rows_deleted'] += $deleted + $batchPreparedPages;
        }

        return [
            'groups' => $groupsDeleted,
            'prepared_pages' => $preparedPagesDeleted,
        ];
    }

    /** @return Builder<SeasonvarImportEvent> */
    private function eligibleImportEvents(Carbon $cutoff): Builder
    {
        return SeasonvarImportEvent::query()
            ->where('created_at', '<', $cutoff)
            ->whereHas('run', fn ($query) => $query->whereIn('status', $this->terminalRunStatuses()));
    }

    /** @return Builder<SourcePageSnapshot> */
    private function eligibleSourcePageSnapshots(Carbon $cutoff): Builder
    {
        $table = (new SourcePageSnapshot)->getTable();

        return SourcePageSnapshot::query()
            ->where('captured_at', '<', $cutoff)
            ->whereHas('run', fn ($query) => $query->whereIn('status', $this->terminalRunStatuses()))
            ->whereExists(function ($query) use ($table): void {
                $query->selectRaw('1')
                    ->from($table.' as newer_snapshot')
                    ->whereColumn('newer_snapshot.source_page_id', $table.'.source_page_id')
                    ->where(function ($query) use ($table): void {
                        $query->whereColumn('newer_snapshot.captured_at', '>', $table.'.captured_at')
                            ->orWhere(function ($query) use ($table): void {
                                $query->whereColumn('newer_snapshot.captured_at', $table.'.captured_at')
                                    ->whereColumn('newer_snapshot.id', '>', $table.'.id');
                            });
                    });
            });
    }

    /** @return Builder<SeasonvarImportTitleGroup> */
    private function eligibleTitleGroups(Carbon $cutoff): Builder
    {
        return SeasonvarImportTitleGroup::query()
            ->whereIn('status', array_map(
                static fn (SeasonvarImportTitleGroupStatus $status): string => $status->value,
                array_filter(
                    SeasonvarImportTitleGroupStatus::cases(),
                    static fn (SeasonvarImportTitleGroupStatus $status): bool => $status->isTerminal(),
                ),
            ))
            ->where('finished_at', '<', $cutoff)
            ->whereHas('run', fn ($query) => $query->whereIn('status', $this->terminalRunStatuses()));
    }

    /** @return list<string> */
    private function terminalRunStatuses(): array
    {
        return array_values(array_map(
            static fn (SeasonvarImportStatus $status): string => $status->value,
            array_filter(
                SeasonvarImportStatus::cases(),
                static fn (SeasonvarImportStatus $status): bool => ! $status->isActive(),
            ),
        ));
    }

    /** @return list<string> */
    private function activeRunStatuses(): array
    {
        return array_values(array_map(
            static fn (SeasonvarImportStatus $status): string => $status->value,
            array_filter(
                SeasonvarImportStatus::cases(),
                static fn (SeasonvarImportStatus $status): bool => $status->isActive(),
            ),
        ));
    }

    /** @return list<string> */
    private function activeTitleGroupStatuses(): array
    {
        return array_values(array_map(
            static fn (SeasonvarImportTitleGroupStatus $status): string => $status->value,
            array_filter(
                SeasonvarImportTitleGroupStatus::cases(),
                static fn (SeasonvarImportTitleGroupStatus $status): bool => ! $status->isTerminal(),
            ),
        ));
    }

    /**
     * @param  Builder<*>  $query
     * @return array{rows: int, bytes: int, oldest: Carbon|null}
     */
    private function aggregateMetrics(Builder $query, string $bytesExpression, string $oldestColumn): array
    {
        $row = $query
            ->toBase()
            ->selectRaw(
                sprintf(
                    'COUNT(*) AS aggregate_rows, COALESCE(SUM(%s), 0) AS aggregate_bytes, MIN(%s) AS aggregate_oldest',
                    $bytesExpression,
                    $oldestColumn,
                ),
            )
            ->first();
        $oldest = data_get($row, 'aggregate_oldest');

        return [
            'rows' => (int) data_get($row, 'aggregate_rows', 0),
            'bytes' => (int) data_get($row, 'aggregate_bytes', 0),
            'oldest' => is_string($oldest) ? Carbon::parse($oldest) : null,
        ];
    }

    /** @return array{rows: int, bytes: int, oldest: null} */
    private function emptyMetrics(): array
    {
        return [
            'rows' => 0,
            'bytes' => 0,
            'oldest' => null,
        ];
    }

    /**
     * @param  Closure(int): Collection<int, int>  $candidateIds
     * @param  Closure(list<int>): int  $delete
     * @param  array{started_at: int, deadline: int, chunk_size: int, max_chunks: int, max_rows: int, chunks_processed: int, rows_deleted: int, stopped_reason: string|null}  $budget
     */
    private function deleteBatches(Closure $candidateIds, Closure $delete, array &$budget): int
    {
        $deleted = 0;

        while ($this->mayStartChunk($budget)) {
            $limit = min(
                $budget['chunk_size'],
                $budget['max_rows'] - $budget['rows_deleted'],
            );
            $ids = $candidateIds($limit);

            if ($ids->isEmpty()) {
                break;
            }

            if ($this->timeBudgetExpired($budget)) {
                break;
            }

            $chunkDeleted = $delete($ids->all());
            $deleted += $chunkDeleted;
            $budget['chunks_processed']++;
            $budget['rows_deleted'] += $chunkDeleted;
        }

        return $deleted;
    }

    /**
     * @param  array{started_at: int, deadline: int, chunk_size: int, max_chunks: int, max_rows: int, chunks_processed: int, rows_deleted: int, stopped_reason: string|null}  $budget
     */
    private function mayStartChunk(array &$budget): bool
    {
        if ($budget['chunks_processed'] >= $budget['max_chunks']) {
            $budget['stopped_reason'] = 'max_chunks';

            return false;
        }

        if ($budget['rows_deleted'] >= $budget['max_rows']) {
            $budget['stopped_reason'] = 'max_rows';

            return false;
        }

        return ! $this->timeBudgetExpired($budget);
    }

    /**
     * @param  array{started_at: int, deadline: int, chunk_size: int, max_chunks: int, max_rows: int, chunks_processed: int, rows_deleted: int, stopped_reason: string|null}  $budget
     */
    private function timeBudgetExpired(array &$budget): bool
    {
        if (hrtime(true) < $budget['deadline']) {
            return false;
        }

        $budget['stopped_reason'] = 'time_budget';

        return true;
    }
}
