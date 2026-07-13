<?php

declare(strict_types=1);

namespace App\Livewire;

use App\DTOs\CatalogTitleRefreshState;
use App\Enums\SeasonvarImportStatus;
use App\Models\CatalogTitle;
use App\Models\User;
use App\Services\Catalog\CatalogTitlePageBuilder;
use App\Services\Catalog\CatalogTitleQuery;
use App\Services\Seasonvar\CatalogTitleRefreshCoordinator;
use App\Services\Seasonvar\CatalogTitleRefreshStateStore;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Locked;
use Livewire\Component;

class CatalogTitleDetail extends Component
{
    #[Locked]
    public int $catalogTitleId;

    protected CatalogTitlePageBuilder $pages;

    protected CatalogTitleQuery $titles;

    protected CatalogTitleRefreshCoordinator $refreshes;

    protected CatalogTitleRefreshStateStore $states;

    public function boot(
        CatalogTitlePageBuilder $pages,
        CatalogTitleQuery $titles,
        CatalogTitleRefreshCoordinator $refreshes,
        CatalogTitleRefreshStateStore $states,
    ): void {
        $this->pages = $pages;
        $this->titles = $titles;
        $this->refreshes = $refreshes;
        $this->states = $states;
    }

    public function mount(int $catalogTitleId): void
    {
        $this->catalogTitleId = $catalogTitleId;
    }

    public function startRefresh(): void
    {
        $this->refreshes->request($this->title());
    }

    public function refreshCatalog(): void
    {
        $this->dispatch('catalog-title-refreshed', catalogTitleId: $this->catalogTitleId)
            ->to(component: CatalogTitlePlayer::class);
    }

    public function render(): View
    {
        $title = $this->title();
        $refreshState = $this->states->read($this->catalogTitleId);

        return view('livewire.catalog-title-detail', [
            ...$this->pages->data($title, $this->user()),
            'refreshState' => $refreshState,
            'refreshStatus' => $this->refreshStatus($refreshState),
        ]);
    }

    private function title(): CatalogTitle
    {
        return $this->titles->visibleTo($this->user())->findOrFail($this->catalogTitleId);
    }

    private function user(): ?User
    {
        $user = Auth::user();

        return $user instanceof User ? $user : null;
    }

    /** @return array{label: string, icon: string, tone: string}|null */
    private function refreshStatus(CatalogTitleRefreshState $state): ?array
    {
        return match ($state->status) {
            SeasonvarImportStatus::Queued, SeasonvarImportStatus::Running => [
                'label' => __('catalog.title.refreshing'),
                'icon' => 'fa-solid fa-arrows-rotate fa-spin',
                'tone' => 'active',
            ],
            SeasonvarImportStatus::Completed => [
                'label' => __('catalog.title.refreshed'),
                'icon' => 'fa-solid fa-circle-check',
                'tone' => 'completed',
            ],
            SeasonvarImportStatus::Failed => [
                'label' => __('catalog.title.refresh_failed'),
                'icon' => 'fa-solid fa-triangle-exclamation',
                'tone' => 'failed',
            ],
            default => null,
        };
    }
}
