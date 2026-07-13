<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Models\User;
use App\Services\Catalog\CatalogViewingActivityQuery;
use App\Services\Catalog\CatalogViewingActivityService;
use App\Services\Security\SensitiveActionRateLimiter;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Livewire\WithPagination;

final class ViewingActivity extends Component
{
    use WithPagination;

    protected CatalogViewingActivityQuery $activity;

    protected CatalogViewingActivityService $actions;

    protected SensitiveActionRateLimiter $rateLimits;

    public function boot(
        CatalogViewingActivityQuery $activity,
        CatalogViewingActivityService $actions,
        SensitiveActionRateLimiter $rateLimits,
    ): void {
        $this->activity = $activity;
        $this->actions = $actions;
        $this->rateLimits = $rateLimits;
    }

    public function mount(): void
    {
        abort_unless(auth()->check(), 403);
    }

    public function removeHistoryItem(mixed $progressId): void
    {
        $progressId = filter_var($progressId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        if ($progressId === false) {
            return;
        }

        $this->rateLimits->enforce('history', $this->user(), $progressId);
        $this->actions->remove($this->user(), $progressId);
        $this->resetPage(pageName: 'historyPage');
    }

    public function clearHistory(): void
    {
        $this->rateLimits->enforce('history', $this->user());
        $this->actions->clear($this->user());
        $this->resetPage(pageName: 'historyPage');
    }

    public function render(): View
    {
        $user = $this->user();

        return view('livewire.viewing-activity', [
            'continueWatching' => $this->activity->continueWatching($user),
            'history' => $this->activity->history($user),
        ])->extends('layouts.app', [
            'title' => __('catalog.viewing.title'),
            'seo' => [
                'title' => __('catalog.viewing.title'),
                'description' => __('catalog.viewing.seo_description'),
                'robots' => 'noindex, nofollow',
                'canonical' => route('viewing-activity'),
            ],
        ])->section('content');
    }

    private function user(): User
    {
        $user = auth()->user();

        abort_unless($user instanceof User, 403);

        return $user;
    }
}
