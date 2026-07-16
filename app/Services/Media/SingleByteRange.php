<?php

declare(strict_types=1);

namespace App\Services\Media;

use App\DTOs\SingleByteRangeData;
use InvalidArgumentException;

final class SingleByteRange
{
    public function parse(?string $header, ?int $knownTotal): ?SingleByteRangeData
    {
        $header = trim((string) $header);

        if ($header === '') {
            return null;
        }

        if (str_contains($header, ',')
            || preg_match('/^bytes=(?:([0-9]+)-([0-9]*)|-([0-9]+))$/D', $header, $matches) !== 1) {
            throw new InvalidArgumentException('invalid_range');
        }

        if (($matches[3] ?? '') !== '') {
            $suffixLength = $this->integer($matches[3]);

            if ($suffixLength < 1 || $knownTotal === 0) {
                throw new InvalidArgumentException('unsatisfiable_range');
            }

            if ($knownTotal !== null) {
                $start = max(0, $knownTotal - min($suffixLength, $knownTotal));
                $end = $knownTotal - 1;

                return new SingleByteRangeData("bytes={$start}-{$end}", $start, $end, $suffixLength);
            }

            return new SingleByteRangeData('bytes=-'.$suffixLength, null, null, $suffixLength);
        }

        $start = $this->integer($matches[1]);
        $end = ($matches[2] ?? '') !== '' ? $this->integer($matches[2]) : null;

        if ($end !== null && $end < $start) {
            throw new InvalidArgumentException('invalid_range');
        }

        if ($knownTotal !== null) {
            if ($knownTotal === 0 || $start >= $knownTotal) {
                throw new InvalidArgumentException('unsatisfiable_range');
            }

            $end = min($end ?? ($knownTotal - 1), $knownTotal - 1);
        }

        return new SingleByteRangeData(
            'bytes='.$start.'-'.($end === null ? '' : $end),
            $start,
            $end,
        );
    }

    private function integer(string $value): int
    {
        $maximum = (string) PHP_INT_MAX;

        if ($value === ''
            || strlen($value) > strlen($maximum)
            || (strlen($value) === strlen($maximum) && strcmp($value, $maximum) > 0)) {
            throw new InvalidArgumentException('invalid_range');
        }

        return (int) $value;
    }
}
