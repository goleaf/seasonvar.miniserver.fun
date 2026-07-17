<?php

declare(strict_types=1);

namespace App\DTOs;

final readonly class CatalogTopListFilters
{
    public function __construct(
        public ?int $yearFrom = null,
        public ?int $yearTo = null,
        public ?string $country = null,
        public ?string $genre = null,
    ) {}

    public static function empty(): self
    {
        return new self;
    }

    /** @return array{year_from?: int, year_to?: int, country?: string, genre?: string} */
    public function query(): array
    {
        return array_filter([
            'year_from' => $this->yearFrom,
            'year_to' => $this->yearTo,
            'country' => $this->country,
            'genre' => $this->genre,
        ], static fn (int|string|null $value): bool => $value !== null && $value !== '');
    }

    /** @return array{year_from?: int, year_to?: int, country?: string, genre?: string} */
    public function contextFilters(): array
    {
        return $this->query();
    }

    public function active(): bool
    {
        return $this->query() !== [];
    }
}
