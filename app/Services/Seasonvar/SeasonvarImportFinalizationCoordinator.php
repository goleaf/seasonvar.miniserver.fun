<?php

declare(strict_types=1);

namespace App\Services\Seasonvar;

use App\Enums\SeasonvarImportFinalizationStage;
use App\Enums\SeasonvarImportStatus;
use App\Models\SeasonvarImportRun;
use Illuminate\Support\Facades\DB;
use Throwable;

final class SeasonvarImportFinalizationCoordinator
{
    public const STATE_KEY = 'queued_finalization';

    public const LEGACY_CHECKPOINT_KEY = 'queued_finalization_checkpoint';

    private const VERSION = 2;

    public function __construct(
        private readonly SeasonvarImportErrorSanitizer $errors,
    ) {}

    public function nextStage(SeasonvarImportRun $run): ?SeasonvarImportFinalizationStage
    {
        return $this->nextStageFromState($this->state($run->fresh()));
    }

    public function beginStage(
        SeasonvarImportRun $run,
        SeasonvarImportFinalizationStage $stage,
    ): bool {
        return DB::transaction(function () use ($run, $stage): bool {
            $lockedRun = SeasonvarImportRun::query()
                ->lockForUpdate()
                ->find($run->id);

            if (! $lockedRun instanceof SeasonvarImportRun
                || $lockedRun->mode !== 'sitemap'
                || $lockedRun->execution_mode !== 'queue'
                || $lockedRun->status !== SeasonvarImportStatus::Running->value
            ) {
                return false;
            }

            $state = $this->state($lockedRun);

            if ($this->nextStageFromState($state) !== $stage) {
                return false;
            }

            $entry = is_array($state['stages'][$stage->value] ?? null)
                ? $state['stages'][$stage->value]
                : [];
            $state['current_stage'] = $stage->value;
            $state['stages'][$stage->value] = [
                ...$entry,
                'status' => 'running',
                'attempts' => max(0, (int) ($entry['attempts'] ?? 0)) + 1,
                'started_at' => now()->toIso8601String(),
                'finished_at' => null,
                'failure' => null,
            ];
            $this->persist($lockedRun, $state);

            return true;
        }, 3);
    }

    /** @param array<string, mixed> $result */
    public function completeStage(
        SeasonvarImportRun $run,
        SeasonvarImportFinalizationStage $stage,
        array $result,
    ): void {
        DB::transaction(function () use ($result, $run, $stage): void {
            $lockedRun = SeasonvarImportRun::query()
                ->lockForUpdate()
                ->find($run->id);

            if (! $lockedRun instanceof SeasonvarImportRun) {
                return;
            }

            $state = $this->state($lockedRun);
            $entry = is_array($state['stages'][$stage->value] ?? null)
                ? $state['stages'][$stage->value]
                : [];

            if (($entry['status'] ?? null) === 'completed') {
                return;
            }

            $state['stages'][$stage->value] = [
                ...$entry,
                'status' => 'completed',
                'attempts' => max(1, (int) ($entry['attempts'] ?? 0)),
                'finished_at' => now()->toIso8601String(),
                'failure' => null,
                'result' => $result,
            ];
            $state['current_stage'] = $this->nextStageFromState($state)?->value;

            if ($stage === SeasonvarImportFinalizationStage::Merge) {
                $results = $this->resultsFromState($state);
                $maintenanceKeys = collect(
                    SeasonvarImportFinalizationStage::ordered(),
                )
                    ->takeUntil(
                        static fn (SeasonvarImportFinalizationStage $candidate): bool => $candidate === SeasonvarImportFinalizationStage::Recommendations,
                    )
                    ->map(
                        static fn (SeasonvarImportFinalizationStage $candidate): ?string => $candidate->resultKey(),
                    )
                    ->filter()
                    ->values()
                    ->all();

                if (collect($maintenanceKeys)->every(
                    static fn (string $key): bool => isset($results[$key]),
                )) {
                    $summary = $lockedRun->summary ?? [];
                    $summary[self::LEGACY_CHECKPOINT_KEY] = [
                        'version' => 1,
                        ...array_intersect_key(
                            $results,
                            array_flip($maintenanceKeys),
                        ),
                    ];
                    $lockedRun->summary = $summary;
                }
            }

            $this->persist($lockedRun, $state);
        }, 3);
    }

    public function failStage(
        SeasonvarImportRun $run,
        SeasonvarImportFinalizationStage $stage,
        Throwable $exception,
    ): void {
        DB::transaction(function () use ($exception, $run, $stage): void {
            $lockedRun = SeasonvarImportRun::query()
                ->lockForUpdate()
                ->find($run->id);

            if (! $lockedRun instanceof SeasonvarImportRun) {
                return;
            }

            $state = $this->state($lockedRun);
            $entry = is_array($state['stages'][$stage->value] ?? null)
                ? $state['stages'][$stage->value]
                : [];

            if (($entry['status'] ?? null) === 'completed') {
                return;
            }

            $state['current_stage'] = $stage->value;
            $state['stages'][$stage->value] = [
                ...$entry,
                'status' => 'failed',
                'attempts' => max(1, (int) ($entry['attempts'] ?? 0)),
                'finished_at' => now()->toIso8601String(),
                'failure' => $this->errors->fromException($exception),
            ];
            $this->persist($lockedRun, $state);
        }, 3);
    }

    /** @return array<string, array<string, mixed>> */
    public function stageResults(SeasonvarImportRun $run): array
    {
        return $this->resultsFromState($this->state($run->fresh()));
    }

    /**
     * @param  array{version: int, current_stage: string|null, stages: array<string, array<string, mixed>>}  $state
     * @return array<string, array<string, mixed>>
     */
    private function resultsFromState(array $state): array
    {
        $results = [];

        foreach (SeasonvarImportFinalizationStage::ordered() as $stage) {
            $key = $stage->resultKey();
            $entry = $state['stages'][$stage->value] ?? null;

            if ($key === null
                || ! is_array($entry)
                || ($entry['status'] ?? null) !== 'completed'
                || ! is_array($entry['result'] ?? null)
            ) {
                continue;
            }

            $results[$key] = $entry['result'];
        }

        return $results;
    }

    /** @return array{version: int, current_stage: string|null, stages: array<string, array<string, mixed>>} */
    private function state(SeasonvarImportRun $run): array
    {
        $stored = data_get($run->summary, self::STATE_KEY);

        if (is_array($stored)
            && ($stored['version'] ?? null) === self::VERSION
            && is_array($stored['stages'] ?? null)
        ) {
            return [
                'version' => self::VERSION,
                'current_stage' => is_string($stored['current_stage'] ?? null)
                    ? $stored['current_stage']
                    : null,
                'stages' => $stored['stages'],
            ];
        }

        $state = [
            'version' => self::VERSION,
            'current_stage' => null,
            'stages' => [],
        ];
        $legacy = data_get($run->summary, self::LEGACY_CHECKPOINT_KEY);

        if (! is_array($legacy) || ($legacy['version'] ?? null) !== 1) {
            $state['current_stage'] = SeasonvarImportFinalizationStage::StorageMaintenance->value;

            return $state;
        }

        foreach (SeasonvarImportFinalizationStage::ordered() as $stage) {
            $key = $stage->resultKey();

            if ($key === null || ! is_array($legacy[$key] ?? null)) {
                break;
            }

            $state['stages'][$stage->value] = [
                'status' => 'completed',
                'attempts' => 1,
                'started_at' => null,
                'finished_at' => null,
                'failure' => null,
                'result' => $legacy[$key],
            ];
        }

        $state['current_stage'] = $this->nextStageFromState($state)?->value;

        return $state;
    }

    /**
     * @param  array{version: int, current_stage: string|null, stages: array<string, array<string, mixed>>}  $state
     */
    private function nextStageFromState(array $state): ?SeasonvarImportFinalizationStage
    {
        foreach (SeasonvarImportFinalizationStage::ordered() as $stage) {
            $entry = $state['stages'][$stage->value] ?? null;

            if (! is_array($entry) || ($entry['status'] ?? null) !== 'completed') {
                return $stage;
            }
        }

        return null;
    }

    /**
     * @param  array{version: int, current_stage: string|null, stages: array<string, array<string, mixed>>}  $state
     */
    private function persist(SeasonvarImportRun $run, array $state): void
    {
        $summary = $run->summary ?? [];
        $summary[self::STATE_KEY] = $state;
        $run->summary = $summary;
        $run->last_progress_at = now();
        $run->last_heartbeat_at = now();
        $run->save();
    }
}
