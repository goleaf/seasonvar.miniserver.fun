<?php

declare(strict_types=1);

namespace App\Services\Auth;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Lang;
use IntlDateFormatter;

final class AccountDateTimeFormatter
{
    public function nowPreview(string $locale, string $timezone): string
    {
        return $this->format(CarbonImmutable::now($timezone), $locale, $timezone);
    }

    public function timestamp(int $timestamp, string $locale, string $timezone): string
    {
        return $this->format(CarbonImmutable::createFromTimestamp($timestamp, $timezone), $locale, $timezone);
    }

    public function value(CarbonInterface $value, string $locale, string $timezone): string
    {
        return $this->format($value, $locale, $timezone);
    }

    public function date(CarbonInterface $value, string $locale, string $timezone): string
    {
        return $this->formatDate($value, $locale, $timezone, IntlDateFormatter::MEDIUM);
    }

    public function dateGroup(CarbonInterface $value, string $locale, string $timezone): string
    {
        $localized = CarbonImmutable::createFromTimestamp($value->getTimestamp(), $timezone);
        $today = CarbonImmutable::now($timezone)->startOfDay();
        $day = $localized->startOfDay();

        if ($day->equalTo($today)) {
            return Lang::get('home.dates.today', [], $locale);
        }

        if ($day->equalTo($today->subDay())) {
            return Lang::get('home.dates.yesterday', [], $locale);
        }

        return $this->formatDate($localized, $locale, $timezone, IntlDateFormatter::LONG);
    }

    private function format(CarbonInterface $value, string $locale, string $timezone): string
    {
        if (class_exists(IntlDateFormatter::class)) {
            $formatter = new IntlDateFormatter(
                $locale,
                IntlDateFormatter::MEDIUM,
                IntlDateFormatter::SHORT,
                $timezone,
            );
            $formatted = $formatter->format($value->getTimestamp());

            if (is_string($formatted)) {
                return $formatted;
            }
        }

        return $value->setTimezone($timezone)->format('d.m.Y H:i T');
    }

    private function formatDate(
        CarbonInterface $value,
        string $locale,
        string $timezone,
        int $dateStyle,
    ): string {
        if (class_exists(IntlDateFormatter::class)) {
            $formatter = new IntlDateFormatter(
                $locale,
                $dateStyle,
                IntlDateFormatter::NONE,
                $timezone,
            );
            $formatted = $formatter->format($value->getTimestamp());

            if (is_string($formatted)) {
                return $formatted;
            }
        }

        return $value->setTimezone($timezone)->toDateString();
    }
}
