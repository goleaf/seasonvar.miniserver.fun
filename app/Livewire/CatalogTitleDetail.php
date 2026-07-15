<?php

declare(strict_types=1);

namespace App\Livewire;

use App\DTOs\CatalogTitleRefreshState;
use App\Enums\SeasonvarImportStatus;
use App\Models\CatalogTitle;
use App\Models\User;
use App\Services\Catalog\CatalogTitlePageBuilder;
use App\Services\Collections\CatalogCollectionQuery;
use App\Services\Seasonvar\CatalogTitleRefreshCoordinator;
use App\Services\Seasonvar\CatalogTitleRefreshStateStore;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Locked;
use Livewire\Component;

class CatalogTitleDetail extends Component
{
    #[Locked]
    public int $catalogTitleId;

    #[Locked]
    public ?int $highlightedReviewId = null;

    protected CatalogTitlePageBuilder $pages;

    protected CatalogTitleRefreshCoordinator $refreshes;

    protected CatalogTitleRefreshStateStore $states;

    protected CatalogCollectionQuery $collections;

    public function boot(
        CatalogTitlePageBuilder $pages,
        CatalogTitleRefreshCoordinator $refreshes,
        CatalogTitleRefreshStateStore $states,
        CatalogCollectionQuery $collections,
    ): void {
        $this->pages = $pages;
        $this->refreshes = $refreshes;
        $this->states = $states;
        $this->collections = $collections;
    }

    public function mount(int $catalogTitleId): void
    {
        $this->catalogTitleId = $catalogTitleId;
        $highlightedReviewId = request()->integer('review');
        $this->highlightedReviewId = $highlightedReviewId > 0 ? $highlightedReviewId : null;
    }

    public function startRefresh(): void
    {
        $this->refreshes->request($this->title());
    }

    public function refreshCatalog(): void
    {
        $this->pages->forget($this->catalogTitleId, $this->user());
        $this->dispatch('catalog-title-refreshed', catalogTitleId: $this->catalogTitleId)
            ->to(component: CatalogTitlePlayer::class);
    }

    public function render(): View
    {
        $user = $this->user();
        $page = $this->pages->dataForId($this->catalogTitleId, $user);
        $refreshState = $this->states->read($this->catalogTitleId);

        return view('livewire.catalog-title-detail', [
            ...$page,
            'refreshIsActive' => $refreshState->isActive(),
            'refreshStatus' => $this->refreshStatus($refreshState),
            'publicCollections' => $this->collections->publicForTitle($this->catalogTitleId),
            'reviewLocale' => App::getLocale(),
        ]);
    }

    private function title(): CatalogTitle
    {
        $title = $this->pages->dataForId($this->catalogTitleId, $this->user())['title'];

        abort_unless($title instanceof CatalogTitle, 404);

        return $title;
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
