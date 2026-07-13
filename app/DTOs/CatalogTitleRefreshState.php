<?php

declare(strict_types=1);

namespace App\DTOs;

use App\Enums\SeasonvarImportStatus;
use Illuminate\Support\Carbon;
use Throwable;

final readonly class CatalogTitleRefreshState
{
    public function __construct(
        public ?SeasonvarImportStatus $status = null,
        public ?Carbon $queuedAt = null,
        public ?Carbon $startedAt = null,
        public ?Carbon $completedAt = null,
        public ?Carbon $failedAt = null,
        public ?Carbon $activeUntil = null,
        public ?int $importRunId = null,
    ) {}

    /** @param array<string, mixed> $state */
    public static function fromArray(array $state): self
    {
        return new self(
            status: self::status($state['status'] ?? null),
            queuedAt: self::date($state['queued_at'] ?? null),
            startedAt: self::date($state['started_at'] ?? null),
            completedAt: self::date($state['completed_at'] ?? null),
            failedAt: self::date($state['failed_at'] ?? null),
            activeUntil: self::date($state['active_until'] ?? null),
            importRunId: is_int($state['import_run_id'] ?? null) ? $state['import_run_id'] : null,
        );
    }

    public function isActive(): bool
    {
        return $this->status?->isActive() === true
            && $this->activeUntil?->isFuture() === true;
    }

    public function isFresh(int $minutes): bool
    {
        return $this->status === SeasonvarImportStatus::Completed
            && $this->completedAt !== null
            && $this->completedAt->greaterThan(now()->subMinutes(max(1, $minutes)));
    }

    /** @return array<string, int|string|null> */
    public function toArray(): array
    {
        return [
            'status' => $this->status?->value,
            'queued_at' => $this->queuedAt?->toIso8601String(),
            'started_at' => $this->startedAt?->toIso8601String(),
            'completed_at' => $this->completedAt?->toIso8601String(),
            'failed_at' => $this->failedAt?->toIso8601String(),
            'active_until' => $this->activeUntil?->toIso8601String(),
            'import_run_id' => $this->importRunId,
        ];
    }

    private static function status(mixed $status): ?SeasonvarImportStatus
    {
        return is_string($status) ? SeasonvarImportStatus::tryFrom($status) : null;
    }

    private static function date(mixed $date): ?Carbon
    {
        if (! is_string($date) || $date === '') {
            return null;
        }

        try {
            return Carbon::parse($date);
        } catch (Throwable) {
            return null;
        }
    }
}
