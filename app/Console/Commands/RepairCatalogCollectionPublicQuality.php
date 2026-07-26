<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Collections\CatalogCollectionPublicQualityRepairer;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('catalog-collections:repair-public-quality
    {--dry-run : Только показать агрегатное состояние без записи}
    {--force : Разрешить exact provenance quarantine}
    {--backup-confirmed : Подтвердить проверенный backup перед production write}
    {--writers-paused : Подтвердить остановку production writers}
    {--json : Вывести машинно-читаемый JSON}')]
#[Description('Проверяет и изолирует exact demo/source шум публичных подборок')]
final class RepairCatalogCollectionPublicQuality extends Command
{
    public function handle(CatalogCollectionPublicQualityRepairer $repairer): int
    {
        $dryRun = (bool) $this->option('dry-run') || ! (bool) $this->option('force');

        if (! $dryRun
            && app()->isProduction()
            && (! (bool) $this->option('backup-confirmed') || ! (bool) $this->option('writers-paused'))) {
            $this->error('Production repair требует --backup-confirmed и --writers-paused.');

            return self::FAILURE;
        }

        try {
            $result = $dryRun
                ? ['mode' => 'dry-run', 'state' => $repairer->inspect()]
                : ['mode' => 'repair', ...$repairer->repair()];
        } catch (Throwable $exception) {
            report($exception);
            $this->error('Repair качества подборок остановлен. Подробности записаны в закрытый журнал.');

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
            ? 'Dry-run качества подборок завершён; данные не изменялись.'
            : 'Exact collection quarantine завершён.');

        foreach (($dryRun ? $result['state'] : $result['after']) as $name => $value) {
            $this->line("{$name}: {$value}");
        }

        return self::SUCCESS;
    }
}
