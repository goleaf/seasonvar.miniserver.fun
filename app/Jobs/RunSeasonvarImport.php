<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\Notifications\SeasonvarImportFailureNotifier;
use App\Services\Seasonvar\SeasonvarGlobalImportRunCoordinator;
use App\Services\Seasonvar\SeasonvarImportErrorSanitizer;
use App\Services\Seasonvar\SeasonvarImportPipeline;
use Illuminate\Bus\Queueable;
use Illuminate\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Cache\Store;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use LogicException;
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

    public function handle(
        SeasonvarImportPipeline $pipeline,
        SeasonvarGlobalImportRunCoordinator $globalRuns,
    ): void {
        $lockSeconds = (int) config('seasonvar.import.lock_seconds', 604800);
        $lock = $this->lockStore()->lock(self::LOCK_KEY, $lockSeconds);

        if (! $lock->get()) {
            $this->release(self::LOCK_RELEASE_DELAY);

            return;
        }

        try {
            $reservedRun = null;

            if ($this->argument === null) {
                $reservation = $globalRuns->acquireSync(
                    force: $this->force,
                    forever: false,
                );

                if (! $reservation->created) {
                    return;
                }

                $reservedRun = $reservation->run;
            }

            $run = $pipeline->run(
                argument: $this->argument,
                force: $this->force,
                forever: false,
                sleepSeconds: null,
                discover: $this->discover,
                progress: null,
                reservedRun: $reservedRun,
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

    public function uniqueVia(): Repository
    {
        return Cache::store((string) config('seasonvar.queue.lock_store', 'redis-locks'));
    }

    private function lockStore(): Store&LockProvider
    {
        $repository = $this->uniqueVia();

        if (! $repository instanceof CacheRepository) {
            throw new LogicException('Seasonvar lock cache repository is unavailable.');
        }

        $store = $repository->getStore();

        if (! $store instanceof LockProvider) {
            throw new LogicException('Seasonvar lock cache store does not support atomic locks.');
        }

        return $store;
    }

    public function failed(?Throwable $exception): void
    {
        Log::error('Очередной импорт Seasonvar завершился ошибкой.', [
            'mode' => $this->argument === null ? 'sitemap' : 'url',
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
