<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Collections\CatalogCollectionCoverPurgeService;
use Illuminate\Console\Command;

final class PurgeCatalogCollectionCovers extends Command
{
    protected $signature = 'catalog-collections:purge-covers {--execute : Безвозвратно удалить точный каталог обложек и очистить legacy metadata}';

    protected $description = 'Проверить или безвозвратно очистить legacy-обложки подборок без раскрытия приватных путей';

    public function handle(CatalogCollectionCoverPurgeService $purge): int
    {
        $result = $purge->run((bool) $this->option('execute'));

        $this->line('Режим: '.($result->executed ? 'execute' : 'dry-run'));
        $this->line("Файлов: {$result->files}");
        $this->line("Байт: {$result->bytes}");
        $this->line("Строк подборок: {$result->collectionRows}");
        $this->line("Строк источников: {$result->sourceRows}");
        $this->line('Готовность к удалению колонок: '.($result->readyForSchemaDrop ? 'да' : 'нет'));

        if ($result->failures > 0) {
            $this->error("Очистка не завершена. Ошибок: {$result->failures}.");

            return self::FAILURE;
        }

        if (! $result->executed && ! $result->readyForSchemaDrop) {
            $this->warn('Найдены legacy-данные. Для удаления требуется явный флаг --execute.');
        }

        return self::SUCCESS;
    }
}
