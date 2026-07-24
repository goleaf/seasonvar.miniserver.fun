<?php

declare(strict_types=1);

namespace App\DTOs;

final readonly class PlayerEpisodePageData
{
    /**
     * @param  list<array{
     *     id: int,
     *     label: string,
     *     title: string|null,
     *     mediaCount: int,
     *     current: bool
     * }>  $episodes
     */
    public function __construct(
        public int $seasonId,
        public string $seasonLabel,
        public array $episodes,
        public int $page,
        public int $lastPage,
    ) {}

    /**
     * @return array{
     *     status: 'ready',
     *     season: array{id: int, label: string},
     *     episodes: list<array{
     *         id: int,
     *         label: string,
     *         title: string|null,
     *         mediaCount: int,
     *         current: bool
     *     }>,
     *     pagination: array{
     *         page: int,
     *         lastPage: int,
     *         previousPage: int|null,
     *         nextPage: int|null
     *     }
     * }
     */
    public function toArray(): array
    {
        return [
            'status' => 'ready',
            'season' => [
                'id' => $this->seasonId,
                'label' => $this->seasonLabel,
            ],
            'episodes' => $this->episodes,
            'pagination' => [
                'page' => $this->page,
                'lastPage' => $this->lastPage,
                'previousPage' => $this->page > 1 ? $this->page - 1 : null,
                'nextPage' => $this->page < $this->lastPage ? $this->page + 1 : null,
            ],
        ];
    }
}
