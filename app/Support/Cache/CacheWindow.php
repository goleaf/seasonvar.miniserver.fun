<?php

declare(strict_types=1);

namespace App\Support\Cache;

use InvalidArgumentException;

final readonly class CacheWindow
{
    public function __construct(
        public int $freshSeconds,
        public int $staleSeconds,
        public int $hotSeconds,
        public int $negativeSeconds,
        public int $lockSeconds,
        public int $waitMilliseconds,
        public int $jitterPercent,
    ) {
        if ($freshSeconds < 1
            || $staleSeconds < $freshSeconds
            || $hotSeconds < 1
            || $negativeSeconds < 1
            || $lockSeconds < 1
            || $waitMilliseconds < 0
            || $waitMilliseconds > 5_000
            || $jitterPercent < 0
            || $jitterPercent > 25) {
            throw new InvalidArgumentException('Некорректное TTL-окно кэша.');
        }
    }

    public function jitteredFreshSeconds(): int
    {
        return $this->jitter($this->freshSeconds);
    }

    public function jitteredHotSeconds(): int
    {
        return $this->jitter($this->hotSeconds);
    }

    public function jitteredNegativeSeconds(): int
    {
        return $this->jitter($this->negativeSeconds);
    }

    private function jitter(int $seconds): int
    {
        $delta = (int) floor($seconds * ($this->jitterPercent / 100));

        return $delta === 0 ? $seconds : max(1, $seconds + random_int(-$delta, $delta));
    }
}
