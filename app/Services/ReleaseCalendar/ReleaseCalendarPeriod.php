<?php

declare(strict_types=1);

namespace App\Services\ReleaseCalendar;

use App\Enums\ReleaseCalendarView;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

final readonly class ReleaseCalendarPeriod
{
    public function __construct(
        public CarbonImmutable $start,
        public CarbonImmutable $end,
        public ?string $canonical,
    ) {}

    public static function resolve(ReleaseCalendarView $view, ?string $period, string $timezone): self
    {
        $now = CarbonImmutable::now($timezone);

        return match ($view) {
            ReleaseCalendarView::Upcoming, ReleaseCalendarView::Personal => new self(
                $now->startOfDay(),
                $now->addDays(self::boundedDays('upcoming_days', 366))->endOfDay(),
                null,
            ),
            ReleaseCalendarView::Recent => new self(
                $now->subDays(self::boundedDays('recent_days', 60))->startOfDay(),
                $now->endOfDay(),
                null,
            ),
            ReleaseCalendarView::Day => self::day($period, $timezone),
            ReleaseCalendarView::Week => self::week($period, $timezone),
            ReleaseCalendarView::Month => self::month($period, $timezone),
        };
    }

    private static function day(?string $period, string $timezone): self
    {
        $date = self::exactDate($period, '!Y-m-d', 'Y-m-d', $timezone);

        return new self($date->startOfDay(), $date->endOfDay(), $date->format('Y-m-d'));
    }

    private static function month(?string $period, string $timezone): self
    {
        $date = self::exactDate($period, '!Y-m', 'Y-m', $timezone);

        return new self($date->startOfMonth(), $date->endOfMonth(), $date->format('Y-m'));
    }

    private static function week(?string $period, string $timezone): self
    {
        if (! is_string($period) || preg_match('/\A(\d{4})-W(\d{2})\z/', $period, $matches) !== 1) {
            throw ValidationException::withMessages(['period' => [__('calendar.errors.invalid_period')]]);
        }

        $year = (int) $matches[1];
        $week = (int) $matches[2];
        $start = CarbonImmutable::now($timezone)->setISODate($year, $week)->startOfDay();

        if ($start->isoWeekYear !== $year || $start->isoWeek !== $week) {
            throw ValidationException::withMessages(['period' => [__('calendar.errors.invalid_period')]]);
        }

        return new self($start, $start->endOfWeek(), sprintf('%04d-W%02d', $year, $week));
    }

    private static function exactDate(?string $period, string $parseFormat, string $outputFormat, string $timezone): CarbonImmutable
    {
        if (! is_string($period) || $period === '') {
            throw ValidationException::withMessages(['period' => [__('calendar.errors.invalid_period')]]);
        }

        $date = CarbonImmutable::createFromFormat($parseFormat, $period, $timezone);

        if ($date === false || $date->format($outputFormat) !== $period) {
            throw ValidationException::withMessages(['period' => [__('calendar.errors.invalid_period')]]);
        }

        return $date;
    }

    private static function boundedDays(string $key, int $default): int
    {
        return min(
            max(1, (int) config('release-calendar.maximum_window_days', 400)),
            max(1, (int) config('release-calendar.'.$key, $default)),
        );
    }
}
