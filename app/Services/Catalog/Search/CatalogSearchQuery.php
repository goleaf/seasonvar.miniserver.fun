<?php

namespace App\Services\Catalog\Search;

final readonly class CatalogSearchQuery
{
    /**
     * @param  list<string>  $terms
     * @param  list<string>  $exactNameHashes
     */
    public function __construct(
        public string $raw,
        public string $normalized,
        public array $terms,
        public ?int $year,
        public CatalogSearchState $state,
        public string $ftsExpression,
        public array $exactNameHashes,
    ) {}

    public function phrase(): string
    {
        return implode(' ', $this->terms);
    }

    public function isReady(): bool
    {
        return $this->state === CatalogSearchState::Ready;
    }
}
