<?php

declare(strict_types=1);

namespace App\Services\Api\V1\Sync;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class ApiSyncRetentionPruner
{
    private const int CHUNK_SIZE = 500;

    private const int MAXIMUM_RETENTION_DAYS = 3650;

    /** @return array{available: bool, changes_deleted: int, mutations_deleted: int, change_retention_days: int, mutation_retention_days: int} */
    public function prune(): array
    {
        $changeRetentionDays = $this->retentionDays('change_retention_days', 30);
        $mutationRetentionDays = $this->retentionDays('mutation_retention_days', 90);

        if (! Schema::hasTable('api_sync_changes') || ! Schema::hasTable('api_sync_mutations')) {
            return [
                'available' => false,
                'changes_deleted' => 0,
                'mutations_deleted' => 0,
                'change_retention_days' => $changeRetentionDays,
                'mutation_retention_days' => $mutationRetentionDays,
            ];
        }

        return [
            'available' => true,
            'changes_deleted' => $this->deleteExpired(
                'api_sync_changes',
                'changed_at',
                now()->subDays($changeRetentionDays),
            ),
            'mutations_deleted' => $this->deleteExpired(
                'api_sync_mutations',
                'created_at',
                now()->subDays($mutationRetentionDays),
            ),
            'change_retention_days' => $changeRetentionDays,
            'mutation_retention_days' => $mutationRetentionDays,
        ];
    }

    private function retentionDays(string $key, int $default): int
    {
        return max(
            1,
            min(self::MAXIMUM_RETENTION_DAYS, (int) config("mobile-api.sync.{$key}", $default)),
        );
    }

    private function deleteExpired(string $table, string $timestampColumn, Carbon $cutoff): int
    {
        $deleted = 0;

        do {
            $ids = DB::table($table)
                ->where($timestampColumn, '<', $cutoff)
                ->orderBy('id')
                ->limit(self::CHUNK_SIZE)
                ->pluck('id');

            if ($ids->isEmpty()) {
                break;
            }

            $deleted += DB::table($table)->whereIn('id', $ids)->delete();
        } while ($ids->count() === self::CHUNK_SIZE);

        return $deleted;
    }
}
