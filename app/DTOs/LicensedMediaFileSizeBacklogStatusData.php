<?php

declare(strict_types=1);

namespace App\DTOs;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

final readonly class LicensedMediaFileSizeBacklogStatusData
{
    public function __construct(
        public int $eligible,
        public int $checked,
        public int $pending,
        public int $due,
        public int $known,
        public int $unknown,
        public int $unsupported,
        public int $failed,
        public int $knownBytes,
        public CarbonImmutable $capturedAt,
    ) {
        foreach ([
            $eligible,
            $checked,
            $pending,
            $due,
            $known,
            $unknown,
            $unsupported,
            $failed,
            $knownBytes,
        ] as $value) {
            if ($value < 0) {
                throw new InvalidArgumentException('File-size backlog counters must be non-negative.');
            }
        }

        if ($checked > $eligible
            || $pending > $eligible
            || $due > $eligible
            || $known > $eligible
            || $unknown > $eligible
            || $unsupported > $eligible
            || $failed > $eligible) {
            throw new InvalidArgumentException('File-size backlog counters cannot exceed eligible media.');
        }
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            eligible: (int) ($data['eligible'] ?? 0),
            checked: (int) ($data['checked'] ?? 0),
            pending: (int) ($data['pending'] ?? 0),
            due: (int) ($data['due'] ?? 0),
            known: (int) ($data['known'] ?? 0),
            unknown: (int) ($data['unknown'] ?? 0),
            unsupported: (int) ($data['unsupported'] ?? 0),
            failed: (int) ($data['failed'] ?? 0),
            knownBytes: (int) ($data['known_bytes'] ?? 0),
            capturedAt: CarbonImmutable::parse((string) ($data['captured_at'] ?? 'now')),
        );
    }

    public function inspectionCoveragePercentage(): float
    {
        if ($this->eligible === 0) {
            return 100.0;
        }

        return round(($this->checked / $this->eligible) * 100, 2);
    }

    /** @return array<string, int|string> */
    public function toArray(): array
    {
        return [
            'eligible' => $this->eligible,
            'checked' => $this->checked,
            'pending' => $this->pending,
            'due' => $this->due,
            'known' => $this->known,
            'unknown' => $this->unknown,
            'unsupported' => $this->unsupported,
            'failed' => $this->failed,
            'known_bytes' => $this->knownBytes,
            'captured_at' => $this->capturedAt->toIso8601String(),
        ];
    }
}
