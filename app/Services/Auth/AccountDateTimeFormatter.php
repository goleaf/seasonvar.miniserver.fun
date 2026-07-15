<?php

declare(strict_types=1);

namespace App\Services\Auth;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
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
}
