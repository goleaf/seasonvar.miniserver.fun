<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Collections\CatalogCollectionSchema;
use App\Services\Collections\Quality\CatalogCollectionQualityAssessor;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('catalog-collections:quality-refresh
    {--limit= : Максимальное число подборок в одном пакете}
    {--all : Проверить все подборки пакетами}
    {--dry-run : Рассчитать результат без записи}
    {--json : Вывести машинно-читаемый JSON}')]
#[Description('Пересчитывает рейтинг качества, тематическое соответствие и review issues подборок')]
final class RefreshCatalogCollectionQuality extends Command
{
    public function handle(
        CatalogCollectionQualityAssessor $assessor,
        CatalogCollectionSchema $schema,
    ): int {
        $limitOption = $this->option('limit');
        $limit = $limitOption !== null
            ? (int) $limitOption
            : (int) config('catalog-collections.quality.refresh_batch_size', 50);

        if ($limit < 1 || $limit > 500) {
            $this->error('Параметр --limit должен быть от 1 до 500.');

            return self::INVALID;
        }

        if (! $schema->qualityAvailable()) {
            $this->error('Схема рейтинга подборок ещё не развернута.');

            return self::FAILURE;
        }

        try {
            $result = $assessor->refresh(
                limit: $limit,
                all: (bool) $this->option('all'),
                dryRun: (bool) $this->option('dry-run'),
            );
        } catch (Throwable $exception) {
            report($exception);
            $this->error('Проверка качества подборок остановлена. Детали записаны в закрытый журнал.');

            return self::FAILURE;
        }

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode(
                $result,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT,
            ));

            return self::SUCCESS;
        }

        $this->info($result['dry_run']
            ? 'Dry-run качества подборок завершён; данные не изменялись.'
            : 'Рейтинг качества подборок обновлён.');
        $this->line('Проверено: '.$result['scanned']);
        $this->line('Оценено: '.$result['assessed']);
        $this->line('Открыто issues: '.$result['issues_opened']);
        $this->line('Закрыто issues: '.$result['issues_resolved']);

        return self::SUCCESS;
    }
}
