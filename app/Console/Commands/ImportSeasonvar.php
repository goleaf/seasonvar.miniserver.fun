<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Commands\Concerns\OutputsSeasonvarProgress;
use App\DTOs\Seasonvar\SeasonvarSourceInventoryResult;
use App\Enums\SeasonvarPageType;
use App\Models\SeasonvarImportRun;
use App\Services\Catalog\CatalogCacheInvalidator;
use App\Services\Media\LicensedMediaFileSizeBackfillBudget;
use App\Services\Media\LicensedMediaFileSizeBackfillSchedule;
use App\Services\Media\LicensedMediaFileSizeBacklog;
use App\Services\Seasonvar\SeasonvarGlobalImportRunCoordinator;
use App\Services\Seasonvar\SeasonvarImportPipeline;
use App\Services\Seasonvar\SeasonvarImportProcessInspector;
use App\Services\Seasonvar\SeasonvarPageHandlerRegistry;
use App\Services\Seasonvar\SeasonvarQueuedImportDispatcher;
use App\Services\Seasonvar\SeasonvarQueueStatus;
use App\Services\Seasonvar\SeasonvarSourceInventory;
use App\Support\HumanFileSizeFormatter;
use Illuminate\Cache\Repository as CacheRepository;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\Store;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use LogicException;
use Throwable;

#[Signature('seasonvar:import {url? : Ссылка страницы seasonvar.ru для обновления одной страницы} {--force : Обновить данные даже если страница не изменилась} {--forever : Работать циклами без остановки} {--sleep= : Пауза между циклами в секундах} {--no-discovery : Не обновлять карту сайта в этом запуске} {--queued : Поставить подходящие страницы в Redis-очередь для параллельной обработки} {--sitemap-tail= : Принудительно обработать последние 1–1000 serial URL из актуального XML в queued-режиме} {--status : Показать состояние Redis-очереди и последнего запуска без импорта} {--inventory-only : Инвентаризировать типы URL из карты сайта без разбора и изменения каталога} {--page-type=* : Обрабатывать только явно включённые типы страниц, например serial или rss} {--refresh-media-sizes : Проверить размеры подходящих существующих прямых видеофайлов} {--force-media-sizes : Повторно проверить размеры независимо от срока свежести} {--media-size-limit= : Ограничить число проверок размера в этом запуске} {--media-size-time-budget= : Остановить size-only цикл перед следующим файлом после заданного числа секунд}')]
#[Description('Инвентаризирует страницы seasonvar.ru или обновляет каталог, сезоны, серии и видео одной командой')]
class ImportSeasonvar extends Command
{
    use OutputsSeasonvarProgress;

    private const LOCK_KEY = 'seasonvar-import';

    private const LOCK_PROCESS_KEY = 'seasonvar-import-process';

    private ?SeasonvarImportPipeline $pipeline = null;

    /**
     * Execute the console command.
     */
    public function handle(
        SeasonvarImportPipeline $pipeline,
        SeasonvarImportProcessInspector $processInspector,
        SeasonvarGlobalImportRunCoordinator $globalRuns,
        SeasonvarQueuedImportDispatcher $queuedDispatcher,
        SeasonvarQueueStatus $queueStatus,
        SeasonvarSourceInventory $sourceInventory,
        SeasonvarPageHandlerRegistry $pageHandlers,
        CatalogCacheInvalidator $cacheInvalidator,
        HumanFileSizeFormatter $fileSizes,
        LicensedMediaFileSizeBacklog $fileSizeBacklog,
    ): int {
        $pageTypes = $this->validatedPageTypes($pageHandlers);

        if ($pageTypes === false) {
            return self::FAILURE;
        }

        $sitemapTailLimit = $this->validatedSitemapTailLimit($pageTypes);

        if ($sitemapTailLimit === false) {
            return self::FAILURE;
        }

        if (! $this->mediaSizeOptionsAreValid()) {
            return self::FAILURE;
        }

        if ((bool) $this->option('inventory-only') && ! $this->inventoryOptionsAreValid()) {
            return self::FAILURE;
        }

        if ((bool) $this->option('status')) {
            return $this->handleStatus($queueStatus, $fileSizeBacklog, $fileSizes);
        }

        if ((bool) $this->option('queued')) {
            return $this->handleQueued($queuedDispatcher, $pageTypes, $sitemapTailLimit);
        }

        $inventoryOnly = (bool) $this->option('inventory-only');
        $refreshMediaSizes = (bool) $this->option('refresh-media-sizes');

        if (! $inventoryOnly) {
            $this->pipeline = $pipeline;
            $this->registerSignalHandlers();
        }
        $lockSeconds = (int) config('seasonvar.import.lock_seconds', 604800);
        $process = $processInspector->currentProcess();
        $lockStore = $this->lockStore();
        $lock = $lockStore->lock(self::LOCK_KEY, $lockSeconds);

        if (! $lock->get()) {
            if ($this->recoverUnconfirmedLock($processInspector, $lockStore, $lockSeconds)) {
                $lock = $lockStore->lock(self::LOCK_KEY, $lockSeconds);
            }

            if (! $lock->get()) {
                $this->warn('Обновление уже запущено. Этот запуск пропущен.');

                return self::SUCCESS;
            }
        }

        try {
            $lockStore->put(self::LOCK_PROCESS_KEY, $process, $lockSeconds);
            $reservedRun = null;

            if (! $inventoryOnly && $this->argument('url') === null) {
                $reservation = $globalRuns->acquireSync(
                    force: (bool) $this->option('force'),
                    forever: (bool) $this->option('forever'),
                    processId: $process['pid'],
                    processHost: $process['host'],
                    processCommand: $process['command'],
                );

                if (! $reservation->created) {
                    $this->warn(sprintf(
                        'Активный глобальный запуск #%d уже выполняется. Синхронный запуск не создан.',
                        $reservation->run->id,
                    ));

                    return self::SUCCESS;
                }

                $reservedRun = $reservation->run;
            }

            if ($inventoryOnly) {
                $result = $sourceInventory->run(
                    processId: $process['pid'],
                    processHost: $process['host'],
                    processCommand: $process['command'],
                    progress: $this->seasonvarProgress(),
                );

                return $this->outputInventoryResult($result);
            }

            $run = $pipeline->run(
                argument: $this->argument('url') ? trim((string) $this->argument('url')) : null,
                force: (bool) $this->option('force'),
                forever: (bool) $this->option('forever'),
                sleepSeconds: $this->sleepSeconds(),
                discover: ! (bool) $this->option('no-discovery'),
                processId: $process['pid'],
                processHost: $process['host'],
                processCommand: $process['command'],
                progress: $this->seasonvarProgress(),
                pageTypes: $pageTypes,
                reservedRun: $reservedRun,
                refreshMediaSizes: $refreshMediaSizes,
                forceMediaSizes: (bool) $this->option('force-media-sizes'),
                mediaSizeLimit: $this->mediaSizeLimit(),
                mediaSizeTimeBudgetSeconds: $this->mediaSizeTimeBudgetSeconds(),
            );

            if (! $refreshMediaSizes) {
                $cacheInvalidator->catalogChanged();
            }

            $this->info(sprintf(
                '%s: запуск #%d, циклов %d, страниц выбрано %d, обновлено %d, ошибок %d, видео добавлено %d, видео обновлено %d, размеров проверено %d, известно %d, неизвестно %d, не поддерживается %d, ошибок размера %d, найдено %s (%d байт).',
                $run->status === 'cancelled' ? 'Остановлено' : 'Готово',
                $run->id,
                $run->cycles,
                $run->selected,
                $run->parsed,
                $run->failed,
                $run->media_attached,
                $run->media_updated,
                $run->media_sizes_checked,
                $run->media_sizes_known,
                $run->media_sizes_unknown,
                $run->media_sizes_unsupported,
                $run->media_size_checks_failed,
                $fileSizes->format((int) $run->media_size_known_bytes, 'ru') ?? '0 B',
                $run->media_size_known_bytes,
            ));

            return in_array($run->status, ['completed', 'partial', 'cancelled'], true) ? self::SUCCESS : self::FAILURE;
        } catch (Throwable $exception) {
            if (! $inventoryOnly && ! $refreshMediaSizes) {
                $cacheInvalidator->catalogChanged();
            }
            $this->error($exception->getMessage());

            return self::FAILURE;
        } finally {
            $lockStore->forget(self::LOCK_PROCESS_KEY);
            $lock->release();
        }
    }

    private function recoverUnconfirmedLock(
        SeasonvarImportProcessInspector $processInspector,
        Store&LockProvider $lockStore,
        int $lockSeconds,
    ): bool {
        $runningRuns = SeasonvarImportRun::query()
            ->where('status', 'running')
            ->where('execution_mode', 'sync')
            ->latest('updated_at')
            ->get();
        $lockProcess = $lockStore->get(self::LOCK_PROCESS_KEY);
        $inspection = $processInspector->inspect(is_array($lockProcess) ? $lockProcess : null, $runningRuns);

        if ($inspection['running']) {
            $this->warn($this->runningProcessMessage($inspection));

            return false;
        }

        $lockStore->lock(self::LOCK_KEY, $lockSeconds)->forceRelease();
        $lockStore->forget(self::LOCK_PROCESS_KEY);

        $this->markUnconfirmedRunsFailed($runningRuns);

        $this->warn($runningRuns->isEmpty()
            ? 'Найдена блокировка импорта, но активный Linux-процесс не подтвержден. Старая блокировка снята, можно продолжать обновление.'
            : 'Найден зависший запуск импорта: активный Linux-процесс не подтвержден. Старая блокировка снята, можно продолжать обновление.');

        if ($inspection['checks'] !== []) {
            $this->line('Проверки процесса: '.$this->formatProcessChecks($inspection['checks']));
        }

        return true;
    }

    /**
     * @param  Collection<int, SeasonvarImportRun>  $runningRuns
     */
    private function markUnconfirmedRunsFailed(Collection $runningRuns): void
    {
        if ($runningRuns->isEmpty()) {
            return;
        }

        SeasonvarImportRun::query()
            ->whereKey($runningRuns->pluck('id'))
            ->update([
                'status' => 'failed',
                'last_error' => 'Предыдущий запуск не имеет подтвержденного активного Linux-процесса и был закрыт автоматически.',
                'finished_at' => now(),
                'updated_at' => now(),
            ]);
    }

    /**
     * @param  array{running: bool, verified: bool, pid: int|null, run_id: int|null, source: string|null, checks: array<int, string>}  $inspection
     */
    private function runningProcessMessage(array $inspection): string
    {
        $details = [];

        if ($inspection['run_id'] !== null) {
            $details[] = 'запуск #'.$inspection['run_id'];
        }

        if ($inspection['pid'] !== null) {
            $details[] = 'PID '.$inspection['pid'];
        }

        $message = 'Активный процесс обновления подтвержден';

        if ($details !== []) {
            $message .= ' ('.implode(', ', $details).')';
        }

        return $message.'. Проверки: '.$this->formatProcessChecks($inspection['checks']).'.';
    }

    /**
     * @param  array<int, string>  $checks
     */
    private function formatProcessChecks(array $checks): string
    {
        return implode(', ', array_slice($checks, -8));
    }

    private function sleepSeconds(): ?int
    {
        $value = $this->option('sleep');

        if ($value === null || $value === '') {
            return null;
        }

        return max(1, (int) $value);
    }

    /** @param list<string>|null $pageTypes */
    private function handleQueued(
        SeasonvarQueuedImportDispatcher $dispatcher,
        ?array $pageTypes,
        ?int $sitemapTailLimit,
    ): int {
        if ($this->argument('url')) {
            $this->error('Опция --queued предназначена только для полного импорта без URL.');

            return self::FAILURE;
        }

        if ((bool) $this->option('forever')) {
            $this->error('Опции --queued и --forever нельзя использовать одновременно.');

            return self::FAILURE;
        }

        if ($this->option('sleep') !== null && $this->option('sleep') !== '') {
            $this->error('Опция --sleep доступна только для синхронного режима --forever.');

            return self::FAILURE;
        }

        $lock = $this->lockStore()
            ->lock('seasonvar-import-coordinator', 300);

        if (! $lock->get()) {
            $this->warn('Диспетчер уже запущен. Этот cron-запуск пропущен.');

            return self::SUCCESS;
        }

        try {
            if ($sitemapTailLimit !== null) {
                $result = $dispatcher->dispatch(
                    force: true,
                    discover: true,
                    pageTypes: $pageTypes,
                    sitemapTailLimit: $sitemapTailLimit,
                );
            } else {
                $result = $pageTypes === null
                    ? $dispatcher->dispatch(
                        force: (bool) $this->option('force'),
                        discover: ! (bool) $this->option('no-discovery'),
                    )
                    : $dispatcher->dispatch(
                        force: (bool) $this->option('force'),
                        discover: ! (bool) $this->option('no-discovery'),
                        pageTypes: $pageTypes,
                    );
            }

            $run = $result->run;

            if (! $result->created) {
                $this->warn(sprintf(
                    'Активный глобальный запуск #%d уже имеет статус «%s». Новый запуск не создан.',
                    $run->id,
                    $run->statusValue()->label(),
                ));

                return self::SUCCESS;
            }

            $this->info(sprintf(
                'Запуск #%d: поставлено в очередь: %d страниц.',
                $run->id,
                $run->selected,
            ));

            return $run->status === 'failed' ? self::FAILURE : self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        } finally {
            $lock->release();
        }
    }

    private function lockStore(): Store&LockProvider
    {
        $repository = Cache::store((string) config('seasonvar.queue.lock_store', 'redis-locks'));

        if (! $repository instanceof CacheRepository) {
            throw new LogicException('Seasonvar lock cache repository is unavailable.');
        }

        $store = $repository->getStore();

        if (! $store instanceof LockProvider) {
            throw new LogicException('Seasonvar lock cache store does not support atomic locks.');
        }

        return $store;
    }

    private function handleStatus(
        SeasonvarQueueStatus $queueStatus,
        LicensedMediaFileSizeBacklog $fileSizeBacklog,
        HumanFileSizeFormatter $fileSizes,
    ): int {
        $status = $queueStatus->read();
        $oldestAge = $status->oldestPendingAgeSeconds();

        $this->components->info('Очередь Seasonvar');
        $this->table(['Показатель', 'Значение'], [
            ['Подключение', $status->connection],
            ['Очередь', $status->queue],
            ['Ожидают обработки', $status->pending],
            ['Отложены', $status->delayed],
            ['Зарезервированы', $status->reserved],
            ['Возраст старейшей job', $oldestAge === null ? 'нет' : $oldestAge.' сек.'],
            ['Живые claims', $status->liveClaims],
            ['Активных queued runs', $status->activeRuns],
            ['Глобальный active/last run', $status->runId === null ? 'нет' : '#'.$status->runId],
            ['Режим run', $status->runExecutionMode ?? 'нет'],
            ['Статус run', $status->runStatus ?? 'нет'],
            ['Heartbeat run', $status->lastHeartbeatAt?->format('d.m.Y H:i:s') ?? 'нет'],
            ['Выбрано страниц', $status->selected],
            ['Обработано страниц', $status->parsed],
            ['Ошибок страниц', $status->failed],
            ['Размеров проверено в run', $status->mediaSizesChecked],
            ['Размер известен в run', $status->mediaSizesKnown],
            ['Размер неизвестен в run', $status->mediaSizesUnknown],
            ['Формат не поддерживается в run', $status->mediaSizesUnsupported],
            ['Ошибок размера в run', $status->mediaSizeChecksFailed],
            ['Известный объём в run', sprintf(
                '%s (%d байт)',
                $fileSizes->format($status->mediaSizeKnownBytes, 'ru') ?? '0 B',
                $status->mediaSizeKnownBytes,
            )],
        ]);

        $backlog = $fileSizeBacklog->status();
        $backfillSchedule = LicensedMediaFileSizeBackfillSchedule::fromConfig();

        $this->components->info('Размеры прямых видеофайлов');
        $this->table(['Показатель', 'Значение'], [
            ['Подходят для проверки', $backlog->eligible],
            ['Проверены', $backlog->checked],
            ['Ожидают первой проверки', $backlog->pending],
            ['Требуют проверки сейчас', $backlog->due],
            ['Размер известен', $backlog->known],
            ['Размер неизвестен', $backlog->unknown],
            ['Формат не поддерживается', $backlog->unsupported],
            ['Ошибок проверки', $backlog->failed],
            ['Покрытие метаданных', number_format($backlog->inspectionCoveragePercentage(), 2, ',', ' ').'%'],
            ['Сумма известных размеров', sprintf(
                '%s (%d байт)',
                $fileSizes->format($backlog->knownBytes, 'ru') ?? '0 B',
                $backlog->knownBytes,
            )],
            ['Снимок построен', $backlog->capturedAt->format('d.m.Y H:i:s')],
            ['Плановая пачка', $backfillSchedule->limit],
            ['Плановый бюджет времени', $backfillSchedule->timeBudgetSeconds.' сек.'],
        ]);

        return self::SUCCESS;
    }

    private function inventoryOptionsAreValid(): bool
    {
        $conflicts = [];

        if ($this->argument('url')) {
            $conflicts[] = 'URL';
        }

        foreach (['force', 'forever', 'no-discovery', 'queued', 'status', 'refresh-media-sizes', 'force-media-sizes'] as $option) {
            if ((bool) $this->option($option)) {
                $conflicts[] = '--'.$option;
            }
        }

        if ($this->option('sleep') !== null && $this->option('sleep') !== '') {
            $conflicts[] = '--sleep';
        }

        if ((array) $this->option('page-type') !== []) {
            $conflicts[] = '--page-type';
        }

        if ($conflicts === []) {
            return true;
        }

        $this->error('Опцию --inventory-only нельзя сочетать с: '.implode(', ', $conflicts).'.');

        return false;
    }

    private function mediaSizeOptionsAreValid(): bool
    {
        $refresh = (bool) $this->option('refresh-media-sizes');
        $force = (bool) $this->option('force-media-sizes');
        $limit = $this->option('media-size-limit');
        $timeBudget = $this->option('media-size-time-budget');
        $timeBudgetProvided = $this->input->hasParameterOption('--media-size-time-budget');

        if ($force && ! $refresh) {
            $this->error('Опция --force-media-sizes требует --refresh-media-sizes.');

            return false;
        }

        if ($limit !== null && $limit !== '' && (! ctype_digit((string) $limit) || (int) $limit < 1)) {
            $this->error('Опция --media-size-limit должна быть положительным целым числом.');

            return false;
        }

        if ($limit !== null && $limit !== '' && ! $refresh) {
            $this->error('Опция --media-size-limit требует --refresh-media-sizes.');

            return false;
        }

        if ($timeBudgetProvided && (
            $timeBudget === null
            || $timeBudget === ''
            || ! ctype_digit((string) $timeBudget)
            || (int) $timeBudget < 1
            || (int) $timeBudget > LicensedMediaFileSizeBackfillBudget::MAX_SECONDS
        )) {
            $this->error(sprintf(
                'Опция --media-size-time-budget должна быть целым числом от 1 до %d секунд.',
                LicensedMediaFileSizeBackfillBudget::MAX_SECONDS,
            ));

            return false;
        }

        if ($timeBudgetProvided && ! $refresh) {
            $this->error('Опция --media-size-time-budget требует --refresh-media-sizes.');

            return false;
        }

        if (! $refresh) {
            return true;
        }

        $conflicts = [];

        if ($this->argument('url')) {
            $conflicts[] = 'URL';
        }

        foreach (['force', 'forever', 'no-discovery', 'queued', 'status', 'inventory-only'] as $option) {
            if ((bool) $this->option($option)) {
                $conflicts[] = '--'.$option;
            }
        }

        if ($this->option('sleep') !== null && $this->option('sleep') !== '') {
            $conflicts[] = '--sleep';
        }

        if ((array) $this->option('page-type') !== []) {
            $conflicts[] = '--page-type';
        }

        if ($conflicts !== []) {
            $this->error('Опцию --refresh-media-sizes нельзя сочетать с: '.implode(', ', $conflicts).'.');

            return false;
        }

        return true;
    }

    private function mediaSizeLimit(): ?int
    {
        $value = $this->option('media-size-limit');

        return $value === null || $value === '' ? null : (int) $value;
    }

    /** @param list<string>|null $pageTypes */
    private function validatedSitemapTailLimit(?array $pageTypes): int|false|null
    {
        $value = $this->option('sitemap-tail');

        if ($value === null || $value === '') {
            return null;
        }

        if (! ctype_digit((string) $value) || (int) $value < 1 || (int) $value > 1000) {
            $this->error('Опция --sitemap-tail должна быть целым числом от 1 до 1000.');

            return false;
        }

        $conflicts = [];

        if (! (bool) $this->option('queued')) {
            $conflicts[] = 'без --queued';
        }

        if (! (bool) $this->option('force')) {
            $conflicts[] = 'без --force';
        }

        if ((bool) $this->option('no-discovery')) {
            $conflicts[] = '--no-discovery';
        }

        if ($this->argument('url')) {
            $conflicts[] = 'URL';
        }

        foreach (['forever', 'status', 'inventory-only', 'refresh-media-sizes', 'force-media-sizes'] as $option) {
            if ((bool) $this->option($option)) {
                $conflicts[] = '--'.$option;
            }
        }

        if ($this->option('sleep') !== null && $this->option('sleep') !== '') {
            $conflicts[] = '--sleep';
        }

        if ($this->option('media-size-limit') !== null && $this->option('media-size-limit') !== '') {
            $conflicts[] = '--media-size-limit';
        }

        if ($this->option('media-size-time-budget') !== null && $this->option('media-size-time-budget') !== '') {
            $conflicts[] = '--media-size-time-budget';
        }

        if ($pageTypes !== null && $pageTypes !== [SeasonvarPageType::Serial->value]) {
            $conflicts[] = '--page-type';
        }

        if ($conflicts !== []) {
            $this->error('Опцию --sitemap-tail нельзя использовать: '.implode(', ', $conflicts).'.');

            return false;
        }

        return (int) $value;
    }

    private function mediaSizeTimeBudgetSeconds(): ?int
    {
        $value = $this->option('media-size-time-budget');

        return $value === null || $value === '' ? null : (int) $value;
    }

    /** @return list<string>|false|null */
    private function validatedPageTypes(SeasonvarPageHandlerRegistry $handlers): array|false|null
    {
        $values = collect((array) $this->option('page-type'))
            ->map(fn (mixed $value): string => trim((string) $value))
            ->filter()
            ->unique()
            ->values();

        if ($values->isEmpty()) {
            return null;
        }

        foreach ($values as $value) {
            $type = SeasonvarPageType::tryFrom($value);

            if ($type === null) {
                $this->error("Неизвестный тип страницы Seasonvar: {$value}.");

                return false;
            }

            $definition = $handlers->definition($type);

            if ($definition->parserClass === null || $definition->importerClass === null) {
                $this->error("Тип {$value} можно хранить в inventory, но для него нет разрешённого parser/importer.");

                return false;
            }

            if (! $handlers->isEnabled($type)) {
                $this->error("Тип {$value} отключён конфигурацией Seasonvar и не будет обработан.");

                return false;
            }
        }

        return $values->all();
    }

    private function outputInventoryResult(SeasonvarSourceInventoryResult $result): int
    {
        if (! $result->successful()) {
            $this->error('Инвентаризация страниц Seasonvar не завершена. Успешный снимок не создан.');

            foreach ($result->failureDetails as $failure) {
                $this->line('Ошибка: '.$failure);
            }

            return self::FAILURE;
        }

        $this->components->info('Инвентаризация страниц Seasonvar завершена');
        $this->table(
            ['Тип страницы', 'Количество'],
            collect($result->countsByPageType)
                ->map(function (int $count, string $type): array {
                    $label = SeasonvarPageType::tryFrom($type)?->label() ?? 'неизвестный тип';

                    return ["{$label} ({$type})", $count];
                })
                ->values()
                ->all(),
        );
        $this->line('Карт сайта: '.$result->sitemapCount);
        $this->line('Всего нормализованных URL: '.$result->totalUrlCount);
        $this->line('Новых страниц источника: '.$result->storedUrlCount);
        $this->line('Неизвестных URL: '.$result->unknownUrlCount);
        $this->line('Некорректных URL: '.$result->malformedUrlCount);
        $this->line('Заблокированных URL: '.$result->blockedUrlCount);

        if ($result->discoveredButUnsupportedTypes !== []) {
            $this->warn('Нет полного локального parity: '.implode(', ', $result->discoveredButUnsupportedTypes).'.');
        }

        return self::SUCCESS;
    }

    private function registerSignalHandlers(): void
    {
        $this->trap([SIGINT, SIGTERM], function (int $_signal): void {
            $this->pipeline?->stop();
        });
    }
}
