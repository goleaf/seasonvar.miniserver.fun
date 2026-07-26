<?php

declare(strict_types=1);

namespace App\Services\ReleaseCalendar;

final class ReleaseCalendarEpisodeRangeFormatter
{
    /** @param iterable<int> $numbers */
    public function format(iterable $numbers): string
    {
        $sorted = [];

        foreach ($numbers as $number) {
            $sorted[$number] = $number;
        }

        sort($sorted, SORT_NUMERIC);

        if ($sorted === []) {
            return '';
        }

        $ranges = [];
        $start = $sorted[0];
        $previous = $start;

        foreach (array_slice($sorted, 1) as $number) {
            if ($number === $previous + 1) {
                $previous = $number;

                continue;
            }

            $ranges[] = $this->range($start, $previous);
            $start = $number;
            $previous = $number;
        }

        $ranges[] = $this->range($start, $previous);

        return implode(', ', $ranges);
    }

    private function range(int $start, int $end): string
    {
        return $start === $end ? (string) $start : $start.'–'.$end;
    }
}
