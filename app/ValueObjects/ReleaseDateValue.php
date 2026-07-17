<?php

declare(strict_types=1);

namespace App\ValueObjects;

use App\Enums\ReleaseDatePrecision;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

final readonly class ReleaseDateValue
{
    public function __construct(
        public ReleaseDatePrecision $precision,
        public ?CarbonImmutable $startsAt,
        public ?CarbonImmutable $dateValue,
        public ?CarbonImmutable $dateEnd,
        public ?int $year,
        public ?int $month,
        public ?int $quarter,
        public string $timezone,
        public bool $estimated,
    ) {
        AccountTimezone::from($timezone);
        $this->guard();
    }

    /** @param array<string, mixed> $data */
    public static function fromValidated(array $data): self
    {
        $precision = ReleaseDatePrecision::from((string) $data['precision']);
        $timezone = (string) ($data['original_timezone'] ?? 'UTC');

        return new self(
            precision: $precision,
            startsAt: $precision === ReleaseDatePrecision::ExactDateTime && filled($data['starts_at'] ?? null)
                ? CarbonImmutable::parse((string) $data['starts_at'], $timezone)->utc()
                : null,
            dateValue: in_array($precision, [ReleaseDatePrecision::ExactDate, ReleaseDatePrecision::DateRange], true)
                && filled($data['date_value'] ?? null)
                ? CarbonImmutable::createFromFormat('!Y-m-d', (string) $data['date_value'], $timezone)
                : null,
            dateEnd: $precision === ReleaseDatePrecision::DateRange && filled($data['date_end'] ?? null)
                ? CarbonImmutable::createFromFormat('!Y-m-d', (string) $data['date_end'], $timezone)
                : null,
            year: in_array($precision, [ReleaseDatePrecision::Month, ReleaseDatePrecision::Quarter, ReleaseDatePrecision::Year], true)
                && isset($data['release_year']) ? (int) $data['release_year'] : null,
            month: $precision === ReleaseDatePrecision::Month && isset($data['release_month'])
                ? (int) $data['release_month'] : null,
            quarter: $precision === ReleaseDatePrecision::Quarter && isset($data['release_quarter'])
                ? (int) $data['release_quarter'] : null,
            timezone: $timezone,
            estimated: (bool) ($data['is_estimated'] ?? false),
        );
    }

    /** @return array<string, mixed> */
    public function attributes(): array
    {
        return [
            'starts_at' => $this->startsAt,
            'date_value' => $this->dateValue?->toDateString(),
            'date_end' => $this->dateEnd?->toDateString(),
            'release_year' => $this->year,
            'release_month' => $this->month,
            'release_quarter' => $this->quarter,
            'original_timezone' => $this->timezone,
            'is_estimated' => $this->estimated,
        ];
    }

    private function guard(): void
    {
        $valid = match ($this->precision) {
            ReleaseDatePrecision::ExactDateTime => $this->startsAt !== null && $this->dateValue === null && $this->dateEnd === null && $this->year === null && $this->month === null && $this->quarter === null,
            ReleaseDatePrecision::ExactDate => $this->startsAt === null && $this->dateValue !== null && $this->dateEnd === null && $this->year === null && $this->month === null && $this->quarter === null,
            ReleaseDatePrecision::Month => $this->startsAt === null && $this->dateValue === null && $this->dateEnd === null && $this->year !== null && $this->month !== null && $this->month >= 1 && $this->month <= 12 && $this->quarter === null,
            ReleaseDatePrecision::Quarter => $this->startsAt === null && $this->dateValue === null && $this->dateEnd === null && $this->year !== null && $this->month === null && $this->quarter !== null && $this->quarter >= 1 && $this->quarter <= 4,
            ReleaseDatePrecision::Year => $this->startsAt === null && $this->dateValue === null && $this->dateEnd === null && $this->year !== null && $this->month === null && $this->quarter === null,
            ReleaseDatePrecision::DateRange => $this->startsAt === null && $this->dateValue !== null && $this->dateEnd !== null && $this->dateEnd->greaterThanOrEqualTo($this->dateValue) && $this->year === null && $this->month === null && $this->quarter === null,
            ReleaseDatePrecision::Unknown => $this->startsAt === null && $this->dateValue === null && $this->dateEnd === null && $this->year === null && $this->month === null && $this->quarter === null,
        };

        if (! $valid || ($this->year !== null && ($this->year < 1900 || $this->year > 2200))) {
            throw new InvalidArgumentException('Invalid release date representation.');
        }
    }
}
