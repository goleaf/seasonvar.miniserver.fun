<?php

declare(strict_types=1);

namespace App\Services\Seasonvar;

use App\Enums\SeasonvarImportEventPersistence;
use App\Models\SeasonvarImportEvent;
use Throwable;

final class SeasonvarImportEventRecorder
{
    public const AGGREGATE_EVENT = 'seasonvar-import-events-aggregated';

    /**
     * @var array<string, array{run_id: int|null, counts: array<string, int>, total: int}>
     */
    private array $aggregates = [];

    public function __construct(
        private readonly SeasonvarImportStorageMaintenance $storageMaintenance,
    ) {}

    /**
     * @param  array<string, mixed>  $context
     */
    public function record(
        string $event,
        array $context = [],
        ?int $importRunId = null,
        ?int $sourcePageId = null,
        ?int $catalogTitleId = null,
        ?string $level = null,
    ): void {
        $level ??= $this->levelFor($event);
        $persistence = $this->persistenceFor($event, $level);

        if ($persistence === SeasonvarImportEventPersistence::Transient) {
            return;
        }

        if ($persistence === SeasonvarImportEventPersistence::Aggregate) {
            $this->aggregate($importRunId, $event);

            return;
        }

        if ($persistence === SeasonvarImportEventPersistence::Sampled
            && ! $this->shouldSample($event, $importRunId, $sourcePageId, $catalogTitleId, $context)) {
            return;
        }

        if ($persistence === SeasonvarImportEventPersistence::Always) {
            $this->flushRun($importRunId);
        }

        $this->persist(
            importRunId: $importRunId,
            sourcePageId: $sourcePageId,
            catalogTitleId: $catalogTitleId,
            event: $event,
            level: $level,
            context: $context,
        );
    }

    public function persistenceFor(string $event, string $level): SeasonvarImportEventPersistence
    {
        if ($level !== 'info') {
            return SeasonvarImportEventPersistence::Always;
        }

        foreach ([
            SeasonvarImportEventPersistence::Always,
            SeasonvarImportEventPersistence::Aggregate,
            SeasonvarImportEventPersistence::Sampled,
            SeasonvarImportEventPersistence::Transient,
        ] as $persistence) {
            $events = config('seasonvar.import.event_persistence.'.$persistence->value, []);

            if (is_array($events) && in_array($event, $events, true)) {
                return $persistence;
            }
        }

        return SeasonvarImportEventPersistence::Transient;
    }

    public function flushRun(?int $importRunId): void
    {
        $key = $this->aggregateKey($importRunId);
        $aggregate = $this->aggregates[$key] ?? null;

        if ($aggregate === null || $aggregate['total'] < 1) {
            return;
        }

        unset($this->aggregates[$key]);
        ksort($aggregate['counts']);

        $this->persist(
            importRunId: $aggregate['run_id'],
            sourcePageId: null,
            catalogTitleId: null,
            event: self::AGGREGATE_EVENT,
            level: 'info',
            context: [
                'counts' => $aggregate['counts'],
                'total' => $aggregate['total'],
            ],
        );
    }

    private function aggregate(?int $importRunId, string $event): void
    {
        $key = $this->aggregateKey($importRunId);
        $aggregate = $this->aggregates[$key] ?? [
            'run_id' => $importRunId,
            'counts' => [],
            'total' => 0,
        ];
        $aggregate['counts'][$event] = ($aggregate['counts'][$event] ?? 0) + 1;
        $aggregate['total']++;
        $this->aggregates[$key] = $aggregate;

        $flushSize = max(
            1,
            min(10_000, (int) config('seasonvar.import.event_persistence.aggregate_flush_size', 100)),
        );

        if ($aggregate['total'] >= $flushSize) {
            $this->flushRun($importRunId);
        }
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function shouldSample(
        string $event,
        ?int $importRunId,
        ?int $sourcePageId,
        ?int $catalogTitleId,
        array $context,
    ): bool {
        $divisor = max(
            1,
            min(10_000, (int) config('seasonvar.import.event_persistence.sample_divisor', 100)),
        );

        if ($divisor === 1) {
            return true;
        }

        $samplingContext = $this->samplingContext(
            $context,
            $sourcePageId,
            $catalogTitleId,
        );
        $encodedContext = json_encode(
            $samplingContext,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );
        $sampleKey = implode('|', [
            $event,
            (string) ($importRunId ?? 0),
            (string) ($sourcePageId ?? 0),
            (string) ($catalogTitleId ?? 0),
            $encodedContext === false ? '' : $encodedContext,
        ]);
        $bucket = hexdec(substr(hash('sha256', $sampleKey), 0, 8));

        return $bucket % $divisor === 0;
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function samplingContext(
        array $context,
        ?int $sourcePageId,
        ?int $catalogTitleId,
    ): array {
        if ($sourcePageId !== null || $catalogTitleId !== null) {
            return [];
        }

        $sanitizedContext = $this->storageMaintenance->sanitizeEventContext($context);
        $identity = [];

        foreach ([
            'source_page_id',
            'catalog_title_id',
            'season_id',
            'episode_id',
            'media_id',
            'group_id',
            'source_url_hash',
            'url_hash',
            'content_hash',
            'sample_key',
        ] as $key) {
            if (array_key_exists($key, $sanitizedContext)) {
                $identity[$key] = $sanitizedContext[$key];
            }
        }

        return $this->sortRecursively($identity !== [] ? $identity : $sanitizedContext);
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private function sortRecursively(array $values): array
    {
        ksort($values);

        foreach ($values as $key => $value) {
            if (is_array($value) && ! array_is_list($value)) {
                $values[$key] = $this->sortRecursively($value);
            }
        }

        return $values;
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function persist(
        ?int $importRunId,
        ?int $sourcePageId,
        ?int $catalogTitleId,
        string $event,
        string $level,
        array $context,
    ): void {
        try {
            SeasonvarImportEvent::query()->create([
                'seasonvar_import_run_id' => $this->positiveId($importRunId),
                'source_page_id' => $this->positiveId($sourcePageId),
                'catalog_title_id' => $this->positiveId($catalogTitleId),
                'event' => $event,
                'level' => $level,
                'context' => $this->storageMaintenance->sanitizeEventContext($context),
            ]);
        } catch (Throwable) {
            // Необязательная телеметрия не должна останавливать импорт.
        }
    }

    private function levelFor(string $event): string
    {
        return str_contains($event, 'failed')
            || str_contains($event, 'failure')
            || str_contains($event, 'invalid')
            || str_contains($event, 'blocked')
            || str_contains($event, 'rejected')
                ? 'warning'
                : 'info';
    }

    private function aggregateKey(?int $importRunId): string
    {
        return $importRunId === null ? 'none' : 'run:'.$importRunId;
    }

    private function positiveId(?int $id): ?int
    {
        return $id !== null && $id > 0 ? $id : null;
    }
}
