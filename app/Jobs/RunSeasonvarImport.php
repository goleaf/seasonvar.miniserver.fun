<?php

namespace App\Jobs;

use App\Services\Notifications\SeasonvarImportFailureNotifier;
use App\Services\Seasonvar\SeasonvarImportErrorSanitizer;
use App\Services\Seasonvar\SeasonvarImportPipeline;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class RunSeasonvarImport implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    private const LOCK_KEY = 'seasonvar-import';

    private const LOCK_RELEASE_DELAY = 300;

    public int $tries = 3;

    public int $timeout = 900;

    public int $uniqueFor = 3600;

    public function __construct(
        public readonly ?string $argument = null,
        public readonly bool $force = false,
        public readonly bool $discover = true,
    ) {}

    public function handle(SeasonvarImportPipeline $pipeline): void
    {
        $lockSeconds = (int) config('seasonvar.import.lock_seconds', 604800);
        $lock = Cache::lock(self::LOCK_KEY, $lockSeconds);

        if (! $lock->get()) {
            $this->release(self::LOCK_RELEASE_DELAY);

            return;
        }

        try {
            $run = $pipeline->run(
                argument: $this->argument,
                force: $this->force,
                forever: false,
                sleepSeconds: null,
                discover: $this->discover,
                progress: null,
            );

            if (! in_array($run->status, ['completed', 'partial'], true)) {
                throw new RuntimeException('Импорт Seasonvar завершился со статусом '.$run->status.'.');
            }
        } finally {
            $lock->release();
        }
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [60, 300, 900];
    }

    public function uniqueId(): string
    {
        return self::LOCK_KEY;
    }

    public function failed(?Throwable $exception): void
    {
        Log::error('Очередной импорт Seasonvar завершился ошибкой.', [
            'argument' => $this->argument,
            'force' => $this->force,
            'discover' => $this->discover,
            'exception' => $exception ? get_class($exception) : null,
            'error' => app(SeasonvarImportErrorSanitizer::class)->fromException($exception),
        ]);

        app(SeasonvarImportFailureNotifier::class)->notify(
            argument: $this->argument,
            force: $this->force,
            discover: $this->discover,
            exception: $exception,
        );
    }
}
