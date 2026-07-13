<?php

namespace App\Services\Seasonvar;

use App\Models\SeasonvarImportEvent;
use App\Models\SeasonvarImportTitleGroup;
use App\Models\SourcePageSnapshot;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class SeasonvarImportStorageMaintenance
{
    private const REDACTED_URL = '[redacted-url]';

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public function sanitizeEventContext(array $context): array
    {
        return $this->sanitizeArray($context);
    }

    /**
     * @return array{enabled: bool, event_retention_days: int, snapshot_retention_days: int, prepared_retention_days: int, chunk_size: int, events_deleted: int, snapshots_deleted: int, title_groups_deleted: int}
     */
    public function prune(): array
    {
        $enabled = filter_var(config('seasonvar.import.storage_maintenance_enabled', true), FILTER_VALIDATE_BOOL);
        $eventRetentionDays = max(0, (int) config('seasonvar.import.event_retention_days', 7));
        $snapshotRetentionDays = max(0, (int) config('seasonvar.import.snapshot_retention_days', 14));
        $preparedRetentionDays = max(0, (int) config('seasonvar.import.prepared_retention_days', 7));
        $chunkSize = max(1, (int) config('seasonvar.import.maintenance_chunk_size', 500));

        if (! $enabled) {
            return [
                'enabled' => false,
                'event_retention_days' => $eventRetentionDays,
                'snapshot_retention_days' => $snapshotRetentionDays,
                'prepared_retention_days' => $preparedRetentionDays,
                'chunk_size' => $chunkSize,
                'events_deleted' => 0,
                'snapshots_deleted' => 0,
                'title_groups_deleted' => 0,
            ];
        }

        return [
            'enabled' => true,
            'event_retention_days' => $eventRetentionDays,
            'snapshot_retention_days' => $snapshotRetentionDays,
            'prepared_retention_days' => $preparedRetentionDays,
            'chunk_size' => $chunkSize,
            'events_deleted' => $eventRetentionDays > 0
                ? $this->pruneImportEvents(now()->subDays($eventRetentionDays), $chunkSize)
                : 0,
            'snapshots_deleted' => $snapshotRetentionDays > 0
                ? $this->pruneSourcePageSnapshots(now()->subDays($snapshotRetentionDays), $chunkSize)
                : 0,
            'title_groups_deleted' => $preparedRetentionDays > 0
                ? $this->pruneTitleGroups(now()->subDays($preparedRetentionDays), $chunkSize)
                : 0,
        ];
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
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

    private function pruneImportEvents(Carbon $cutoff, int $chunkSize): int
    {
        $deleted = 0;

        SeasonvarImportEvent::query()
            ->where('created_at', '<', $cutoff)
            ->whereDoesntHave('run', fn ($query) => $query->whereIn('status', ['queued', 'running']))
            ->select('id')
            ->chunkById($chunkSize, function ($events) use (&$deleted): void {
                $deleted += SeasonvarImportEvent::query()
                    ->whereKey($events->modelKeys())
                    ->delete();
            });

        return $deleted;
    }

    private function pruneSourcePageSnapshots(Carbon $cutoff, int $chunkSize): int
    {
        $deleted = 0;
        $table = (new SourcePageSnapshot)->getTable();

        SourcePageSnapshot::query()
            ->where('captured_at', '<', $cutoff)
            ->whereDoesntHave('run', fn ($query) => $query->whereIn('status', ['queued', 'running']))
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
            })
            ->select('id')
            ->chunkById($chunkSize, function ($snapshots) use (&$deleted): void {
                $deleted += SourcePageSnapshot::query()
                    ->whereKey($snapshots->modelKeys())
                    ->delete();
            });

        return $deleted;
    }

    private function pruneTitleGroups(Carbon $cutoff, int $chunkSize): int
    {
        $deleted = 0;

        SeasonvarImportTitleGroup::query()
            ->where('finished_at', '<', $cutoff)
            ->whereHas('run', fn ($query) => $query->whereNotIn('status', ['queued', 'running']))
            ->select('id')
            ->chunkById($chunkSize, function ($groups) use (&$deleted): void {
                $deleted += SeasonvarImportTitleGroup::query()
                    ->whereKey($groups->modelKeys())
                    ->delete();
            });

        return $deleted;
    }
}
