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

        $updates['updated_at'] = now();

        SeasonvarImportRun::query()->whereKey($runId)->update($updates);
    }
}
