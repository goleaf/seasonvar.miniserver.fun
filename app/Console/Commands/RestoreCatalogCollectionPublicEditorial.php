<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Collections\Import\HdRezkaPublicCollectionRestorer;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('catalog-collections:restore-public-editorial
    {--dry-run : Только показать агрегатное состояние без записи}
    {--force : Разрешить точное восстановление проверенного набора}
    {--backup-confirmed : Подтвердить проверенную резервную копию перед записью в рабочей среде}
    {--writers-paused : Подтвердить остановку процессов записи в рабочей среде}
    {--json : Вывести машинно-читаемый JSON}')]
#[Description('Возвращает точно проверенные редакционные подборки в публичный каталог')]
final class RestoreCatalogCollectionPublicEditorial extends Command
{
    public function handle(HdRezkaPublicCollectionRestorer $restorer): int
    {
        $dryRun = (bool) $this->option('dry-run') || ! (bool) $this->option('force');

        if (! $dryRun
            && app()->isProduction()
            && (! (bool) $this->option('backup-confirmed') || ! (bool) $this->option('writers-paused'))) {
            $this->error('Для восстановления в рабочей среде требуются --backup-confirmed и --writers-paused.');

            return self::FAILURE;
        }

        try {
            $result = $dryRun
                ? ['mode' => 'dry-run', 'state' => $restorer->inspect()]
                : ['mode' => 'recovery', ...$restorer->repair()];
        } catch (Throwable $exception) {
            report($exception);
            $this->error('Восстановление редакционных подборок остановлено. Подробности записаны в закрытый журнал.');

            return self::FAILURE;
        }

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode(
                $result,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT,
            ));

            return self::SUCCESS;
        }

        $this->info($dryRun
            ? 'Проверка восстановления подборок завершена; данные не изменялись.'
            : 'Проверенные редакционные подборки восстановлены.');

        foreach (($dryRun ? $result['state'] : $result['after']) as $name => $value) {
            $this->line("{$name}: {$value}");
        }

        return self::SUCCESS;
    }
}
