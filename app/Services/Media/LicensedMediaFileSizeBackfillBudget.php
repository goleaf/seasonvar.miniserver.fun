<?php

declare(strict_types=1);

namespace App\Services\Media;

use InvalidArgumentException;

final readonly class LicensedMediaFileSizeBackfillBudget
{
    public const MAX_SECONDS = 3_600;

    private const NANOSECONDS_PER_MILLISECOND = 1_000_000;

    private const NANOSECONDS_PER_SECOND = 1_000_000_000;

    private function __construct(
        public ?int $seconds,
        private int $startedAtNanoseconds,
        private ?int $deadlineNanoseconds,
    ) {}

    public static function start(?int $seconds): self
    {
        if ($seconds !== null && ($seconds < 1 || $seconds > self::MAX_SECONDS)) {
            throw new InvalidArgumentException(sprintf(
                'Media file-size backfill budget must be between 1 and %d seconds.',
                self::MAX_SECONDS,
            ));
        }

        $startedAtNanoseconds = (int) hrtime(true);

        return new self(
            seconds: $seconds,
            startedAtNanoseconds: $startedAtNanoseconds,
            deadlineNanoseconds: $seconds === null
                ? null
                : $startedAtNanoseconds + ($seconds * self::NANOSECONDS_PER_SECOND),
        );
    }

    public function exhausted(): bool
    {
        return $this->deadlineNanoseconds !== null
            && (int) hrtime(true) >= $this->deadlineNanoseconds;
    }

    public function elapsedMilliseconds(): int
    {
        return intdiv(
            max(0, (int) hrtime(true) - $this->startedAtNanoseconds),
            self::NANOSECONDS_PER_MILLISECOND,
        );
    }

    public function remainingSeconds(): ?int
    {
        if ($this->deadlineNanoseconds === null) {
            return null;
        }

        $remainingNanoseconds = max(0, $this->deadlineNanoseconds - (int) hrtime(true));

        return intdiv(
            $remainingNanoseconds + self::NANOSECONDS_PER_SECOND - 1,
            self::NANOSECONDS_PER_SECOND,
        );
    }
}
