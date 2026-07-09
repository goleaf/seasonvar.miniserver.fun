<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\OutputsSeasonvarProgress;
use App\Models\SeasonvarImportRun;
use App\Services\Catalog\CatalogStatsSnapshotCache;
use App\Services\Seasonvar\SeasonvarImportPipeline;
use App\Services\Seasonvar\SeasonvarImportProcessInspector;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Throwable;

#[Signature('seasonvar:import {url? : Ссылка страницы seasonvar.ru для обновления одного сериала} {--force : Обновить данные даже если страница не изменилась} {--forever : Работать циклами без остановки} {--sleep= : Пауза между циклами в секундах} {--no-discovery : Не обновлять карту сайта в этом запуске}')]
#[Description('Находит страницы seasonvar.ru, обновляет каталог, сезоны, серии и видео одной командой')]
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
        CatalogStatsSnapshotCache $statsSnapshots,
    ): int {
        $this->pipeline = $pipeline;
        $this->registerSignalHandlers();
        $lockSeconds = (int) config('seasonvar.import.lock_seconds', 604800);
        $process = $processInspector->currentProcess();
        $lock = Cache::lock(self::LOCK_KEY, $lockSeconds);

        if (! $lock->get()) {
            if ($this->recoverUnconfirmedLock($processInspector, $lockSeconds)) {
                $lock = Cache::lock(self::LOCK_KEY, $lockSeconds);
            }

            if (! $lock->get()) {
                $this->warn('Обновление уже запущено. Этот запуск пропущен.');

                return self::SUCCESS;
            }
        }

        try {
            Cache::put(self::LOCK_PROCESS_KEY, $process, $lockSeconds);

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
            );

            $statsSnapshots->refresh();

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

            return $run->status === 'completed' ? self::SUCCESS : self::FAILURE;
        } catch (Throwable $exception) {
            $statsSnapshots->refresh();
            $this->error($exception->getMessage());

            return self::FAILURE;
        } finally {
            Cache::forget(self::LOCK_PROCESS_KEY);
            $lock->release();
        }
    }

    private function recoverUnconfirmedLock(SeasonvarImportProcessInspector $processInspector, int $lockSeconds): bool
    {
        $runningRuns = SeasonvarImportRun::query()
            ->where('status', 'running')
            ->latest('updated_at')
            ->get();
        $lockProcess = Cache::get(self::LOCK_PROCESS_KEY);
        $inspection = $processInspector->inspect(is_array($lockProcess) ? $lockProcess : null, $runningRuns);

        if ($inspection['running']) {
            $this->warn($this->runningProcessMessage($inspection));

            return false;
        }

        Cache::lock(self::LOCK_KEY, $lockSeconds)->forceRelease();
        Cache::forget(self::LOCK_PROCESS_KEY);

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
