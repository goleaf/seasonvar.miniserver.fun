<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Models\User;
use App\Services\Catalog\CatalogViewingActivityQuery;
use App\Services\Catalog\CatalogViewingActivityService;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Livewire\WithPagination;

final class ViewingActivity extends Component
{
    use WithPagination;

    protected CatalogViewingActivityQuery $activity;

    protected CatalogViewingActivityService $actions;

    public function boot(
        CatalogViewingActivityQuery $activity,
        CatalogViewingActivityService $actions,
    ): void {
        $this->activity = $activity;
        $this->actions = $actions;
    }

    public function removeHistoryItem(mixed $progressId): void
    {
        $progressId = filter_var($progressId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        if ($progressId === false) {
            return;
        }

        $this->actions->remove($this->user(), $progressId);
        $this->resetPage(pageName: 'historyPage');
    }

    public function clearHistory(): void
    {
        $this->actions->clear($this->user());
        $this->resetPage(pageName: 'historyPage');
    }

    public function render(): View
    {
        return view('livewire.viewing-activity', $this->paginationPage)->extends('layouts.app', [
            'title' => __('catalog.viewing.title'),
            'seo' => [
                'title' => __('catalog.viewing.title'),
                'description' => __('catalog.viewing.seo_description'),
                'robots' => 'noindex, nofollow',
                'canonical' => route('viewing-activity'),
            ],
        ])->section('content');
    }

    /**
     * @return array<string, mixed>
     */
    #[Computed]
    public function paginationPage(): array
    {
        $user = $this->user();

        return [
            'continueWatching' => $this->activity->continueWatching($user),
            'history' => $this->activity->history($user),
        ];
    }

    private function user(): User
    {
        $user = auth()->user();

        abort_unless($user instanceof User, 403);

        return $user;
    }
}
