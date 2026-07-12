<?php

namespace App\Services\Seasonvar;

use Closure;
use Illuminate\Database\DatabaseManager;
use Throwable;

class SeasonvarDatabaseTransaction
{
    public function __construct(private readonly DatabaseManager $database) {}

    /**
     * @template TResult
     *
     * @param  Closure(): TResult  $callback
     * @param  (callable(string, array<string, mixed>): void)|null  $progress
     * @return TResult
     */
    public function run(
        Closure $callback,
        int $attempts,
        int $baseDelayMilliseconds,
        ?callable $progress = null,
    ): mixed {
        $attempts = max(1, $attempts);
        $baseDelayMilliseconds = max(0, $baseDelayMilliseconds);

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            try {
                return $this->database->transaction($callback, 1);
            } catch (Throwable $exception) {
                if (! $this->isDatabaseLocked($exception) || $attempt === $attempts) {
                    throw $exception;
                }

                $delayMilliseconds = min(2000, $baseDelayMilliseconds * (2 ** ($attempt - 1)));

                if ($progress !== null) {
                    $progress('seasonvar-database-transaction-retrying', [
                        'attempt' => $attempt,
                        'max_attempts' => $attempts,
                        'delay_milliseconds' => $delayMilliseconds,
                    ]);
                }

                if ($delayMilliseconds > 0) {
                    usleep($delayMilliseconds * 1000);
                }
            }
        }

        throw new \LogicException('Seasonvar database transaction retry loop ended unexpectedly.');
    }

    private function isDatabaseLocked(Throwable $exception): bool
    {
        do {
            $message = mb_strtolower($exception->getMessage());

            if (str_contains($message, 'database is locked')
                || str_contains($message, 'database table is locked')
                || str_contains($message, 'a table in the database is locked')) {
                return true;
            }

            $exception = $exception->getPrevious();
        } while ($exception instanceof Throwable);

        return false;
    }
}
