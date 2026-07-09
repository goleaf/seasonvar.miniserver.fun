<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\OutputsSeasonvarProgress;
use App\Services\Seasonvar\SeasonvarImportPipeline;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Throwable;

#[Signature('seasonvar:import {url? : Ссылка страницы seasonvar.ru для обновления одного сериала} {--force : Обновить данные даже если страница не изменилась} {--forever : Работать циклами без остановки} {--sleep= : Пауза между циклами в секундах} {--no-discovery : Не обновлять карту сайта в этом запуске}')]
#[Description('Находит страницы seasonvar.ru, обновляет каталог, сезоны, серии и видео одной командой')]
class ImportSeasonvar extends Command
{
    use OutputsSeasonvarProgress;

    private ?SeasonvarImportPipeline $pipeline = null;

    /**
     * Execute the console command.
     */
    public function handle(SeasonvarImportPipeline $pipeline): int
    {
        $this->pipeline = $pipeline;
        $this->registerSignalHandlers();
        $lock = Cache::lock('seasonvar-import', (int) config('seasonvar.import.lock_seconds', 604800));

        if (! $lock->get()) {
            $this->warn('Обновление уже запущено. Дождитесь завершения текущего запуска.');

            return self::FAILURE;
        }

        try {
            $run = $pipeline->run(
                argument: $this->argument('url') ? trim((string) $this->argument('url')) : null,
                force: (bool) $this->option('force'),
                forever: (bool) $this->option('forever'),
                sleepSeconds: $this->sleepSeconds(),
                discover: ! (bool) $this->option('no-discovery'),
                progress: $this->seasonvarProgress(),
            );

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
            $this->error($exception->getMessage());

            return self::FAILURE;
        } finally {
            $lock->release();
        }
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
