<?php

declare(strict_types=1);

namespace App\Services\ReleaseCalendar;

use App\Enums\ReleaseDatePrecision;
use App\Models\ReleaseScheduleEntry;
use App\Services\Auth\AccountDateTimeFormatter;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use IntlDateFormatter;

final readonly class ReleaseDatePresenter
{
    public function __construct(private AccountDateTimeFormatter $dateTimes) {}

    public function label(ReleaseScheduleEntry $entry, string $locale, string $timezone): string
    {
        return match ($entry->precision) {
            ReleaseDatePrecision::ExactDateTime => $entry->starts_at !== null
                ? $this->dateTimes->value($entry->starts_at, $locale, $timezone)
                : __('calendar.date_unknown'),
            ReleaseDatePrecision::ExactDate => $entry->date_value !== null
                ? $this->civilDate($entry->date_value, $locale)
                : __('calendar.date_unknown'),
            ReleaseDatePrecision::Month => $this->month($entry->release_year, $entry->release_month, $locale, $timezone),
            ReleaseDatePrecision::Quarter => __('calendar.quarter', ['quarter' => $entry->release_quarter, 'year' => $entry->release_year]),
            ReleaseDatePrecision::Year => (string) ($entry->release_year ?? __('calendar.date_unknown')),
            ReleaseDatePrecision::DateRange => $entry->date_value !== null && $entry->date_end !== null
                ? __('calendar.date_range', [
                    'from' => $this->civilDate($entry->date_value, $locale),
                    'to' => $this->civilDate($entry->date_end, $locale),
                ])
                : __('calendar.date_unknown'),
            ReleaseDatePrecision::Unknown => __('calendar.date_unknown'),
        };
    }

    public function localDate(ReleaseScheduleEntry $entry, string $timezone): ?CarbonImmutable
    {
        if ($entry->starts_at !== null) {
            return CarbonImmutable::createFromTimestamp($entry->starts_at->getTimestamp(), $timezone);
        }

        return $entry->date_value !== null
            ? CarbonImmutable::create(
                $entry->date_value->year,
                $entry->date_value->month,
                $entry->date_value->day,
                0,
                0,
                0,
                $timezone,
            )
            : null;
    }

    public function groupLabel(ReleaseScheduleEntry $entry, string $locale, string $timezone): string
    {
        $date = $this->localDate($entry, $timezone);

        if ($date === null) {
            return $this->label($entry, $locale, $timezone);
        }

        if ($date->startOfDay()->equalTo(CarbonImmutable::now($timezone)->addDay()->startOfDay())) {
            return __('calendar.tomorrow');
        }

        return $this->dateTimes->dateGroup($date, $locale, $timezone);
    }

    private function month(?int $year, ?int $month, string $locale, string $timezone): string
    {
        if ($year === null || $month === null) {
            return __('calendar.date_unknown');
        }

        $date = CarbonImmutable::create($year, $month, 1, 0, 0, 0, $timezone);
        $formatter = new IntlDateFormatter($locale, IntlDateFormatter::NONE, IntlDateFormatter::NONE, $timezone, null, 'LLLL y');
        $formatted = $formatter->format($date->getTimestamp());

        return is_string($formatted) ? $formatted : sprintf('%02d.%04d', $month, $year);
    }

    private function civilDate(CarbonInterface $date, string $locale): string
    {
        $formatter = new IntlDateFormatter($locale, IntlDateFormatter::MEDIUM, IntlDateFormatter::NONE, 'UTC');
        $formatted = $formatter->format(CarbonImmutable::create($date->year, $date->month, $date->day, 0, 0, 0, 'UTC')->getTimestamp());

        return is_string($formatted) ? $formatted : $date->format('Y-m-d');
    }
}
