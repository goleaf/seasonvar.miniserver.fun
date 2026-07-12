<?php

namespace Tests\Unit;

use App\Services\Seasonvar\SeasonvarDatabaseTransaction;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\QueryException;
use Mockery;
use PDOException;
use RuntimeException;
use Tests\TestCase;

class SeasonvarDatabaseTransactionTest extends TestCase
{
    public function test_it_retries_sqlite_lock_without_reexecuting_work_before_transaction(): void
    {
        $database = Mockery::mock(DatabaseManager::class);
        $transactionCalls = 0;
        $callbackRuns = 0;
        $callback = function () use (&$callbackRuns): string {
            $callbackRuns++;

            return 'stored';
        };
        $database->shouldReceive('transaction')
            ->twice()
            ->with($callback, 1)
            ->andReturnUsing(function ($receivedCallback) use (&$transactionCalls): string {
                $transactionCalls++;

                if ($transactionCalls === 1) {
                    throw $this->lockedException();
                }

                return $receivedCallback();
            });
        $events = [];

        $result = (new SeasonvarDatabaseTransaction($database))->run(
            $callback,
            attempts: 2,
            baseDelayMilliseconds: 0,
            progress: function (string $event, array $context) use (&$events): void {
                $events[] = compact('event', 'context');
            },
        );

        $this->assertSame('stored', $result);
        $this->assertSame(1, $callbackRuns);
        $this->assertSame('seasonvar-database-transaction-retrying', $events[0]['event']);
        $this->assertSame(1, $events[0]['context']['attempt']);
    }

    public function test_it_does_not_retry_non_lock_failures(): void
    {
        $database = Mockery::mock(DatabaseManager::class);
        $callback = fn (): string => 'unused';
        $database->shouldReceive('transaction')
            ->once()
            ->with($callback, 1)
            ->andThrow(new RuntimeException('invalid catalog data'));

        $this->expectExceptionMessage('invalid catalog data');

        (new SeasonvarDatabaseTransaction($database))->run(
            $callback,
            attempts: 5,
            baseDelayMilliseconds: 0,
        );
    }

    private function lockedException(): QueryException
    {
        return new QueryException(
            'sqlite',
            'update catalog_titles set title = ?',
            ['Тест'],
            new PDOException('SQLSTATE[HY000]: General error: 5 database is locked', 5),
        );
    }
}
