<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Commands\Concerns\OutputsSeasonvarProgress;
use App\DTOs\Seasonvar\SeasonvarSourceInventoryResult;
use App\Enums\SeasonvarPageType;
use App\Models\SeasonvarImportRun;
use App\Services\Catalog\CatalogCacheInvalidator;
use App\Services\Seasonvar\SeasonvarGlobalImportRunCoordinator;
use App\Services\Seasonvar\SeasonvarImportPipeline;
use App\Services\Seasonvar\SeasonvarImportProcessInspector;
use App\Services\Seasonvar\SeasonvarPageHandlerRegistry;
use App\Services\Seasonvar\SeasonvarQueuedImportDispatcher;
use App\Services\Seasonvar\SeasonvarQueueStatus;
use App\Services\Seasonvar\SeasonvarSourceInventory;
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

#[Signature('seasonvar:import {url? : Ссылка страницы seasonvar.ru для обновления одной страницы} {--force : Обновить данные даже если страница не изменилась} {--forever : Работать циклами без остановки} {--sleep= : Пауза между циклами в секундах} {--no-discovery : Не обновлять карту сайта в этом запуске} {--queued : Поставить подходящие страницы в Redis-очередь для параллельной обработки} {--status : Показать состояние Redis-очереди и последнего запуска без импорта} {--inventory-only : Инвентаризировать типы URL из карты сайта без разбора и изменения каталога} {--page-type=* : Обрабатывать только явно включённые типы страниц, например serial или rss}')]
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
    ): int {
        $pageTypes = $this->validatedPageTypes($pageHandlers);

        if ($pageTypes === false) {
            return self::FAILURE;
        }

        if ((bool) $this->option('inventory-only') && ! $this->inventoryOptionsAreValid()) {
            return self::FAILURE;
        }

        if ((bool) $this->option('status')) {
            return $this->handleStatus($queueStatus);
        }

        if ((bool) $this->option('queued')) {
            return $this->handleQueued($queuedDispatcher, $pageTypes);
        }

        $inventoryOnly = (bool) $this->option('inventory-only');

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
            );

            $cacheInvalidator->catalogChanged();

            $this->info(sprintf(
                'Готово: запуск #%d, циклов %d, страниц выбрано %d, обновлено %d, ошибок %d, видео добавлено %d, видео обновлено %d.',
                $run->id,
                $run->cycles,
                $run->selected,
                $run->parsed,
                $run->failed,
                $run->media_attached,
                $run->media_updated,
            ));

            return in_array($run->status, ['completed', 'partial'], true) ? self::SUCCESS : self::FAILURE;
        } catch (Throwable $exception) {
            if (! $inventoryOnly) {
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
    private function handleQueued(SeasonvarQueuedImportDispatcher $dispatcher, ?array $pageTypes): int
    {
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

    private function handleStatus(SeasonvarQueueStatus $queueStatus): int
    {
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
            ['Основной active/last run', $status->runId === null ? 'нет' : '#'.$status->runId],
            ['Статус run', $status->runStatus ?? 'нет'],
            ['Выбрано страниц', $status->selected],
            ['Обработано страниц', $status->parsed],
            ['Ошибок страниц', $status->failed],
        ]);

        return self::SUCCESS;
    }

    private function inventoryOptionsAreValid(): bool
    {
        $conflicts = [];

        if ($this->argument('url')) {
            $conflicts[] = 'URL';
        }

        foreach (['force', 'forever', 'no-discovery', 'queued', 'status'] as $option) {
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
        if (! function_exists('pcntl_async_signals') || ! function_exists('pcntl_signal')) {
            return;
        }

        pcntl_async_signals(true);

        foreach ([SIGINT, SIGTERM] as $signal) {
            pcntl_signal($signal, function () use ($signal): void {
                $this->writeSeasonvarProgress('seasonvar-import-stop-requested', [
                    'signal' => $signal,
                ]);
                $this->pipeline?->stop();
            });
        }
    }
}
