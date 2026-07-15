<?php

declare(strict_types=1);

namespace App\View\Components\Catalog;

use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

final class TitleFilters extends Component
{
    /**
     * @param  array<string, mixed>  $data
     * @param  array{actor: string, director: string}  $optionSearch
     */
    public function __construct(
        public readonly array $data,
        public readonly array $optionSearch,
        public readonly ?int $routeYear = null,
        public readonly ?string $routeFilterType = null,
        public readonly ?string $routeTaxonomy = null,
    ) {}

    public function render(): View
    {
        return view('components.catalog.title-filters', [
            ...$this->data,
            'optionSearch' => $this->optionSearch,
        ]);
    }
}
