<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\SeasonvarImportFinalizationStage;
use App\Models\SeasonvarImportRun;
use App\Services\Seasonvar\SeasonvarDatabaseTransaction;
use App\Services\Seasonvar\SeasonvarImportFinalizationCoordinator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

final class SeasonvarImporterFailureInjectionTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('finalizationStages')]
    public function test_every_finalization_stage_resumes_after_a_failure_checkpoint(string $stageValue): void
    {
        $stage = SeasonvarImportFinalizationStage::from($stageValue);
        $run = $this->importRun();
        $coordinator = app(SeasonvarImportFinalizationCoordinator::class);

        foreach (SeasonvarImportFinalizationStage::ordered() as $preceding) {
            if ($preceding === $stage) {
                break;
            }

            $this->assertTrue($coordinator->beginStage($run, $preceding));
            $coordinator->completeStage($run, $preceding, ['checkpoint' => $preceding->value]);
        }

        $this->assertSame($stage, $coordinator->nextStage($run));
        $this->assertTrue($coordinator->beginStage($run, $stage));
        $coordinator->failStage(
            $run,
            $stage,
            new RuntimeException('provider https://seasonvar.ru/private?token=secret'),
        );

        $fresh = $run->fresh();
        $this->assertSame($stage, $coordinator->nextStage($fresh));
        $failure = (string) data_get(
            $fresh->summary,
            "queued_finalization.stages.{$stage->value}.failure",
        );
        $this->assertStringNotContainsString('seasonvar.ru', $failure);
        $this->assertStringNotContainsString('secret', $failure);

        $this->assertTrue($coordinator->beginStage($fresh, $stage));
        $coordinator->completeStage($fresh, $stage, ['checkpoint' => $stage->value]);
        $ordered = SeasonvarImportFinalizationStage::ordered();
        $position = array_search($stage, $ordered, true);
        $next = is_int($position) ? ($ordered[$position + 1] ?? null) : null;

        $this->assertSame($next, $coordinator->nextStage($fresh->fresh()));
    }

    public function test_catalog_transaction_rolls_back_a_crash_before_the_durable_checkpoint(): void
    {
        $run = $this->importRun();
        config(['seasonvar.test.inject_transaction_crash' => true]);

        try {
            app(SeasonvarDatabaseTransaction::class)->run(
                function () use ($run): void {
                    $run->update(['selected' => 99]);

                    if (config('seasonvar.test.inject_transaction_crash') === true) {
                        throw new RuntimeException('crash before checkpoint');
                    }
                },
                attempts: 1,
                baseDelayMilliseconds: 0,
            );
            $this->fail('Injected transaction crash was swallowed.');
        } catch (RuntimeException $exception) {
            $this->assertSame('crash before checkpoint', $exception->getMessage());
        }

        $this->assertSame(0, $run->fresh()->selected);
    }

    public function test_sqlite_busy_failure_retries_the_whole_write_stage_once(): void
    {
        $run = $this->importRun();
        $attempts = 0;
        $events = [];

        app(SeasonvarDatabaseTransaction::class)->run(
            function () use ($run, &$attempts): void {
                $attempts++;

                if ($attempts === 1) {
                    throw new RuntimeException('database is locked');
                }

                $run->update(['selected' => 1]);
            },
            attempts: 2,
            baseDelayMilliseconds: 0,
            progress: function (string $event, array $context) use (&$events): void {
                $events[] = [$event, $context];
            },
        );

        $this->assertSame(2, $attempts);
        $this->assertSame(1, $run->fresh()->selected);
        $this->assertSame('seasonvar-database-transaction-retrying', $events[0][0]);
        $this->assertSame(1, $events[0][1]['attempt']);
    }

    /** @return iterable<string, array{string}> */
    public static function finalizationStages(): iterable
    {
        foreach (SeasonvarImportFinalizationStage::ordered() as $stage) {
            yield $stage->value => [$stage->value];
        }
    }

    private function importRun(): SeasonvarImportRun
    {
        return SeasonvarImportRun::query()->create([
            'mode' => 'sitemap',
            'execution_mode' => 'queue',
            'status' => 'running',
            'selected' => 0,
            'started_at' => now(),
            'last_progress_at' => now(),
            'last_heartbeat_at' => now(),
        ]);
    }
}
