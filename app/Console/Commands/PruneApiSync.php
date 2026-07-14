<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Api\V1\Sync\ApiSyncRetentionPruner;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('api:sync-prune')]
#[Description('Удаляет устаревшие изменения и квитанции мобильной синхронизации')]
final class PruneApiSync extends Command
{
    public function handle(ApiSyncRetentionPruner $pruner): int
    {
        $result = $pruner->prune();

        if (! $result['available']) {
            $this->components->info('Схема синхронизации ещё не установлена. Очистка пропущена.');

            return self::SUCCESS;
        }

        $this->components->info("Изменения синхронизации: {$result['changes_deleted']} удалено.");
        $this->components->info("Квитанции операций: {$result['mutations_deleted']} удалено.");

        return self::SUCCESS;
    }
}
