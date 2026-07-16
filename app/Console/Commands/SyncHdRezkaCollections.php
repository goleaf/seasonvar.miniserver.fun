<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\CatalogCollectionSyncStatus;
use App\Services\Collections\Import\HdRezkaCollectionSyncService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('catalog-collections:sync-hdrezka
    {--dry-run : Просканировать и сопоставить без записи данных}
    {--retry-unresolved : Повторно запросить detail metadata для unresolved тайтлов}
    {--limit-collections= : Ограничить число подборок в этом запуске}')]
#[Description('Синхронизирует редакционные подборки HDRezka с локальным каталогом')]
final class SyncHdRezkaCollections extends Command
{
    public function handle(HdRezkaCollectionSyncService $sync): int
    {
        if (! (bool) config('catalog-collection-imports.hdrezka.enabled', false)) {
            $this->error('Синхронизация HDRezka выключена в конфигурации.');

            return self::FAILURE;
        }

        $limit = $this->option('limit-collections');

        $validLimit = is_int($limit)
            ? $limit > 0
            : is_string($limit) && ctype_digit($limit) && (int) $limit > 0;

        if ($limit !== null && ! $validLimit) {
            $this->error('Параметр --limit-collections должен быть положительным целым числом.');

            return self::FAILURE;
        }

        if (is_int($limit) || is_string($limit)) {
            config(['catalog-collection-imports.hdrezka.max_collections' => (int) $limit]);
        }

        try {
            $result = $sync->sync(
                dryRun: (bool) $this->option('dry-run'),
                retryUnresolved: (bool) $this->option('retry-unresolved'),
            );
        } catch (Throwable) {
            $this->error('Синхронизация коллекций аварийно остановлена без раскрытия внутренних данных.');

            return self::FAILURE;
        }

        $this->line('Подборок обнаружено: '.$result->counters['collections_discovered']);
        $this->line('Подборок обработано: '.$result->counters['collections_processed']);
        $this->line('Страниц просмотрено: '.$result->counters['pages']);
        $this->line('Тайтлов просмотрено: '.$result->counters['items']);
        $this->line('Совпало: '.$result->counters['matched']);
        $this->line('Неоднозначно: '.$result->counters['ambiguous']);
        $this->line('Не найдено: '.$result->counters['unmatched']);

        foreach ($result->errors as $error) {
            $this->warn($error);
        }

        if ($result->dryRun && $result->status === CatalogCollectionSyncStatus::Completed) {
            $this->info('Dry-run завершён: данные портала не изменены.');

            return self::SUCCESS;
        }

        if ($result->status === CatalogCollectionSyncStatus::Completed) {
            $this->info('Синхронизация коллекций завершена.');

            return self::SUCCESS;
        }

        $this->error($result->status === CatalogCollectionSyncStatus::Partial
            ? 'Синхронизация завершена частично; прежние данные сохранены.'
            : 'Синхронизация коллекций не выполнена.');

        return self::FAILURE;
    }
}
