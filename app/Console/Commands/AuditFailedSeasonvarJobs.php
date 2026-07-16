<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Operations\FailedFinalizerAuditBuilder;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('app:failed-job-audit {--json : Вывести машинно-читаемый JSON} {--samples=3 : Число безопасных примеров на одно состояние, от 0 до 10}')]
#[Description('Сопоставляет historical failed finalizers с текущим состоянием импорта без retry, forget, clear или dispatch')]
final class AuditFailedSeasonvarJobs extends Command
{
    public function handle(FailedFinalizerAuditBuilder $audit): int
    {
        $sampleLimit = filter_var($this->option('samples'), FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 0, 'max_range' => 10],
        ]);

        if (! is_int($sampleLimit)) {
            $this->error('Количество примеров должно быть целым числом от 0 до 10.');

            return self::FAILURE;
        }

        try {
            $report = $audit->build($sampleLimit);
        } catch (Throwable) {
            return $this->failedOutput();
        }

        if ((bool) $this->option('json')) {
            $this->line(json_encode(
                $report,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT,
            ));

            return self::SUCCESS;
        }

        $this->components->info('Read-only аудит failed jobs');
        $this->table(['Показатель', 'Значение'], [
            ['Всего failed jobs', $report['failed_jobs']['total']],
            ['Finalizer rows', $report['finalizers']['total']],
            ['Target ID подтверждён', $report['finalizers']['parsed']],
            ['Payload не разрешён', $report['finalizers']['unresolved']],
        ]);

        if ($report['finalizers']['states'] !== []) {
            $this->table(
                ['Текущее состояние', 'Disposition', 'Количество'],
                collect($report['finalizers']['states'])
                    ->map(fn (int $count, string $state): array => [
                        $state,
                        FailedFinalizerAuditBuilder::dispositionFor($state),
                        $count,
                    ])
                    ->values()
                    ->all(),
            );
        }

        if ($report['finalizers']['samples'] !== []) {
            $this->table(
                ['Failed ID', 'Тип', 'Target ID', 'Причина', 'Возраст', 'Состояние', 'Disposition'],
                array_map(fn (array $sample): array => [
                    $sample['failed_job_id'],
                    $sample['kind'],
                    $sample['target_id'] ?? '—',
                    $sample['reason'],
                    $sample['age'],
                    $sample['state'],
                    $sample['disposition'],
                ], $report['finalizers']['samples']),
            );
        }

        $this->warn('Retry, forget, clear, dispatch и import state mutation не выполнялись.');

        return self::SUCCESS;
    }

    private function failedOutput(): int
    {
        $message = 'Не удалось безопасно выполнить read-only аудит failed jobs.';

        if ((bool) $this->option('json')) {
            $this->line(json_encode([
                'status' => 'failed',
                'read_only' => true,
                'message' => $message,
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        } else {
            $this->error($message);
        }

        return self::FAILURE;
    }
}
