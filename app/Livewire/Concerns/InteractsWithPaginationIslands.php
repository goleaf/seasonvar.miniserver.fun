<?php

declare(strict_types=1);

namespace App\Livewire\Concerns;

use Illuminate\Contracts\View\View;
use Livewire\Attributes\Computed;

trait InteractsWithPaginationIslands
{
    /** @var array<string, mixed>|null */
    protected ?array $paginationIslandViewData = null;

    /**
     * @return array<string, mixed>
     */
    #[Computed]
    public function paginationIslandPage(): array
    {
        if ($this->paginationIslandViewData !== null) {
            return $this->paginationIslandViewData;
        }

        $view = app()->call([$this, 'render']);

        return $this->paginationIslandViewData = $view->getData();
    }

    /**
     * Capture the already-built view data before Blade evaluates island scopes.
     *
     * @param  array<string, mixed>  $data
     */
    public function renderingInteractsWithPaginationIslands(View $view, array $data): void
    {
        $this->paginationIslandViewData = $view->getData();
    }
}
