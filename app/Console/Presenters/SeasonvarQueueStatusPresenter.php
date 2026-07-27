<?php

declare(strict_types=1);

namespace App\Console\Presenters;

use App\DTOs\LicensedMediaFileSizeBacklogStatusData;
use App\DTOs\Seasonvar\SeasonvarQueueStatusData;
use App\Services\Media\LicensedMediaFileSizeBackfillSchedule;
use App\Support\HumanFileSizeFormatter;

final readonly class SeasonvarQueueStatusPresenter
{
    public function __construct(private HumanFileSizeFormatter $fileSizes) {}

    /**
     * @return array{
     *     queue: list<array{0: string, 1: string|int}>,
     *     file_sizes: list<array{0: string, 1: string|int}>
     * }
     */
    public function present(
        SeasonvarQueueStatusData $status,
        LicensedMediaFileSizeBacklogStatusData $backlog,
        LicensedMediaFileSizeBackfillSchedule $schedule,
    ): array {
        $oldestAge = $status->oldestPendingAgeSeconds();

        return [
            'queue' => [
                ['Подключение', $status->connection],
                ['Очередь', $status->queue],
                ['Ожидают обработки', $status->pending],
                ['Отложены', $status->delayed],
                ['Зарезервированы', $status->reserved],
                ['Возраст старейшей job', $oldestAge === null ? 'нет' : $oldestAge.' сек.'],
                ['Живые claims', $status->liveClaims],
                ['Активных queued runs', $status->activeRuns],
                ['Активный/последний run', $status->runId === null ? 'нет' : '#'.$status->runId],
                ['Режим run', $status->runExecutionMode ?? 'нет'],
                ['Статус run', $status->runStatus ?? 'нет'],
                ['Фаза импорта', $status->phase],
                ['Состояние транспорта', $status->transportState],
                ['Причина остановки', $status->staleReason ?? 'нет'],
                ['Состояние worker', $status->workerStatus],
                ['Heartbeat worker', $status->workerHeartbeatAt?->format('d.m.Y H:i:s') ?? 'нет'],
                ['Последняя обработка worker', $status->workerProcessedAt?->format('d.m.Y H:i:s') ?? 'нет'],
                ['Heartbeat run', $status->lastHeartbeatAt?->format('d.m.Y H:i:s') ?? 'нет'],
                ['Последний реальный прогресс', $status->lastProgressAt?->format('d.m.Y H:i:s') ?? 'нет'],
                [
                    'Dispatch завершён',
                    $status->dispatchCompleted === null
                        ? 'нет данных'
                        : ($status->dispatchCompleted ? 'да' : 'нет'),
                ],
                ['Номер dispatch-пачки', $status->dispatchCursor],
                ['Staging ожидается', $status->expectedPages],
                ['Staging подготовлено', $status->preparedPages],
                ['Staging применено', $status->appliedPages],
                ['Staging ошибок', $status->failedPages],
                ['Этап финализации', $status->currentFinalizationStage ?? 'нет'],
                ['Ближайшее истечение claim', $status->earliestClaimExpiryAt?->format('d.m.Y H:i:s') ?? 'нет'],
                ['Последнее истечение claim', $status->latestClaimExpiryAt?->format('d.m.Y H:i:s') ?? 'нет'],
                ['Последняя terminal-причина', $status->lastTerminalReasonCode ?? 'нет'],
                ['Выбрано страниц', $status->selected],
                ['Обработано страниц', $status->parsed],
                ['Ошибок страниц', $status->failed],
                ['Размеров проверено в run', $status->mediaSizesChecked],
                ['Размер известен в run', $status->mediaSizesKnown],
                ['Размер неизвестен в run', $status->mediaSizesUnknown],
                ['Формат не поддерживается в run', $status->mediaSizesUnsupported],
                ['Ошибок размера в run', $status->mediaSizeChecksFailed],
                [
                    'Известный объём в run',
                    sprintf(
                        '%s (%d байт)',
                        $this->fileSizes->format($status->mediaSizeKnownBytes, 'ru') ?? '0 B',
                        $status->mediaSizeKnownBytes,
                    ),
                ],
            ],
            'file_sizes' => [
                ['Подходят для проверки', $backlog->eligible],
                ['Проверены', $backlog->checked],
                ['Ожидают первой проверки', $backlog->pending],
                ['Требуют проверки сейчас', $backlog->due],
                ['Размер известен', $backlog->known],
                ['Размер неизвестен', $backlog->unknown],
                ['Формат не поддерживается', $backlog->unsupported],
                ['Ошибок проверки', $backlog->failed],
                [
                    'Покрытие метаданных',
                    number_format(
                        $backlog->inspectionCoveragePercentage(),
                        2,
                        ',',
                        ' ',
                    ).'%',
                ],
                [
                    'Сумма известных размеров',
                    sprintf(
                        '%s (%d байт)',
                        $this->fileSizes->format($backlog->knownBytes, 'ru') ?? '0 B',
                        $backlog->knownBytes,
                    ),
                ],
                [
                    'Снимок построен',
                    $backlog->capturedAt->format('d.m.Y H:i:s')
                        .($backlog->isStale() ? ' (устарел)' : ''),
                ],
                ['Плановая пачка', $schedule->limit],
                ['Плановый бюджет времени', $schedule->timeBudgetSeconds.' сек.'],
            ],
        ];
    }
}
