<?php

declare(strict_types=1);

namespace App\DTOs\DemoData;

final readonly class DemoTitleContext
{
    /**
     * @param  list<string>  $genreNames
     */
    public function __construct(
        public int $titleId,
        public string $displayTitle,
        public ?int $year,
        public ?int $firstSeasonId,
        public ?int $firstEpisodeId,
        public ?int $lastEpisodeId,
        public ?int $licensedMediaId,
        public ?int $durationSeconds,
        public array $genreNames,
    ) {}
}
