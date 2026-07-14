<?php

declare(strict_types=1);

namespace App\Services\Api\V1\Sync;

use App\Models\ApiSyncChange;
use App\Models\CatalogTitle;
use App\Models\User;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Throwable;

final class UserSyncChangePublisher
{
    public function publishTitleState(User $user, CatalogTitle $title): void
    {
        $this->publish($user, 'title_state', (string) $title->slug, ApiSyncChange::OPERATION_UPSERT);
    }

    public function publishProgress(User $user, CatalogTitle $title, int $episodeId): void
    {
        if ($episodeId < 1) {
            return;
        }

        $this->publish(
            $user,
            'progress',
            (string) $title->slug.':'.$episodeId,
            ApiSyncChange::OPERATION_UPSERT,
        );
    }

    public function publishHistoryDelete(User $user, int $progressId): void
    {
        if ($progressId < 1) {
            return;
        }

        $this->publish($user, 'history', (string) $progressId, ApiSyncChange::OPERATION_DELETE);
    }

    public function publishHistoryClear(User $user): void
    {
        $this->publish($user, 'history', null, ApiSyncChange::OPERATION_CLEAR);
    }

    private function publish(User $user, string $type, ?string $key, string $operation): void
    {
        if ($user->id === null || ($key !== null && mb_strlen($key) > 191)) {
            return;
        }

        $this->afterCommit(function () use ($user, $type, $key, $operation): void {
            if (! Schema::hasTable('api_sync_changes')) {
                return;
            }

            try {
                ApiSyncChange::query()->create([
                    'scope' => ApiSyncChange::SCOPE_USER,
                    'user_id' => $user->id,
                    'resource_type' => $type,
                    'resource_key' => $key,
                    'operation' => $operation,
                    'changed_at' => now(),
                ]);
            } catch (Throwable $exception) {
                Log::warning('Пользовательское изменение сохранено, но событие мобильной синхронизации не записано.', [
                    'exception' => $exception::class,
                ]);
            }
        });
    }

    private function afterCommit(Closure $callback): void
    {
        if (DB::transactionLevel() > 0) {
            DB::afterCommit($callback);

            return;
        }

        $callback();
    }
}
