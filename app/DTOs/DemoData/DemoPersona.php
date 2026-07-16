<?php

declare(strict_types=1);

namespace App\DTOs\DemoData;

final readonly class DemoPersona
{
    /**
     * @param  list<string>  $favoriteGenres
     */
    public function __construct(
        public int $index,
        public string $givenName,
        public string $familyName,
        public string $displayName,
        public string $username,
        public string $biography,
        public string $city,
        public string $occupation,
        public array $favoriteGenres,
        public string $reviewStyle,
        public string $commentStyle,
    ) {}
}
