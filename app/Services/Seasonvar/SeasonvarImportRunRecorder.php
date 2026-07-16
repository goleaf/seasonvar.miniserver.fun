<?php

namespace App\Services\Seasonvar;

use App\Models\SeasonvarImportRun;
use Illuminate\Support\Facades\DB;

class SeasonvarImportRunRecorder
{
    private const COUNTER_COLUMNS = [
        'cycles',
        'discovered',
        'stored',
        'selected',
        'parsed',
        'failed',
        'media_attached',
        'media_updated',
        'media_skipped',
        'media_failed',
        'media_sizes_checked',
        'media_sizes_known',
        'media_sizes_unknown',
        'media_sizes_unsupported',
        'media_size_checks_failed',
        'media_size_known_bytes',
    ];

    /**
     * @param  array<string, int>  $counters
     */
    public function addCounters(int $runId, array $counters): void
    {
        $updates = [];

        foreach (self::COUNTER_COLUMNS as $column) {
            $value = max(0, (int) ($counters[$column] ?? 0));

            if ($value > 0) {
                $updates[$column] = DB::raw($column.' + '.$value);
            }
        }

        if ($updates === []) {
            return;
        }

        $updates['last_heartbeat_at'] = now();
        $updates['updated_at'] = now();

        SeasonvarImportRun::query()
            ->whereKey($runId)
            ->whereIn('status', ['queued', 'running'])
            ->update($updates);
    }

    public function heartbeat(int $runId): void
    {
        SeasonvarImportRun::query()
            ->whereKey($runId)
            ->whereIn('status', ['queued', 'running'])
            ->update([
                'last_heartbeat_at' => now(),
                'updated_at' => now(),
            ]);
    }
}
